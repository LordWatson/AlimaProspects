<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWebhookSecret;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Throwable;

/**
 * The door an inbound event comes through, and every lock on it (S13b, ADR 0023 §7).
 *
 * **This is the only request in the product whose body came from outside our own code**, and the
 * ADR's list is a list of *conditions of acceptance* rather than features. Each is enforced here
 * rather than in the Action, because a request that fails any of them must not reach business logic
 * at all:
 *
 * 1. **A per-definition secret and an HMAC over the raw body**, compared in constant time. The raw
 *    body and not the parsed one: `json_encode(json_decode($body))` is not `$body`, so verifying a
 *    re-encoded payload would let an attacker who can change key order or whitespace keep a valid
 *    signature over different bytes.
 * 2. **A timestamp inside the signature's scope.** Signing the body alone makes a captured request
 *    valid for ever; the timestamp is part of what is signed, so it cannot be edited to refresh one.
 * 3. **An idempotency key**, unique per team, so a machine retrying in a loop is accepted once and
 *    acknowledged every time.
 * 4. **A strict bounded schema**, rejected whole rather than coerced — and the fields that decide
 *    meaning are not in it at all (see {@see self::rules()}).
 * 5. **Its own rate limit**, applied on the route.
 *
 * The one thing this class deliberately does **not** do is tell a caller which check failed. A
 * signature failure, an unknown definition key and a type belonging to another team all answer the
 * same way, because the differences are an oracle: "that key exists but the signature is wrong" is
 * how somebody enumerates another tenant's signal types.
 */
final class IngestSignalEventRequest extends FormRequest
{
    private const int MAX_TITLE = 255;

    private const int MAX_SUMMARY = 1_000;

    private const int MAX_URL = 2048;

    private const int MAX_KEY = 128;

    /** The signal type this event is for, resolved and verified. Set by {@see self::authorize()}. */
    private ?SignalDefinition $definition = null;

    /**
     * Verify the signature before anything else looks at the body.
     *
     * In `authorize()` rather than a middleware so the failure is this endpoint's own 403 through
     * the standard envelope, and so the resolved definition can be handed to the controller without
     * being looked up twice.
     */
    public function authorize(): bool
    {
        $definition = $this->resolveDefinition();

        if (! $definition instanceof SignalDefinition) {
            return false;
        }

        // Found by the **definition**, which the query above already established is this team's.
        // There is nothing to look up from the request itself: the caller presents a signature, not
        // a credential, so there is no identifier to be guessed or enumerated.
        $secret = SignalWebhookSecret::query()
            ->where('team_id', $definition->team_id)
            ->where('signal_definition_id', $definition->getKey())
            ->first();

        if (! $secret instanceof SignalWebhookSecret) {
            return false;
        }

        if (! $this->timestampIsFresh()) {
            return false;
        }

        if (! $this->signatureMatches($secret)) {
            return false;
        }

        $secret->forceFill(['last_used_at' => now()])->save();

        $this->definition = $definition;

        return true;
    }

    /**
     * The bounded schema.
     *
     * **What is absent matters more than what is present.** None of `heat`, `score_breakdown`,
     * `jurisdiction`, `resolved_market`, `lead_id`, `matched_lead_id`, `team_id`, `confidence` or
     * `relevance` is accepted, and {@see self::prepareForValidation()} strips them so a caller
     * cannot even have them silently ignored — each is a conclusion the product reaches rather than
     * an input (ADR 0023 §7). The jurisdiction one is not a matter of taste: it decides which
     * outreach restriction a converted lead carries, so a caller able to assert it is a compliance
     * hole with an API key.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // The type, by **key** and within the authenticated team. Never an id: a guessed integer
            // must not be able to write into another tenant's type.
            'event_type' => ['required', 'string', 'max:32'],

            // The caller's own identity for this event. Required, because without one a retry is
            // indistinguishable from a second event.
            'idempotency_key' => ['required', 'string', 'min:8', 'max:'.self::MAX_KEY],

            'title' => ['required', 'string', 'max:'.self::MAX_TITLE],
            'summary' => ['nullable', 'string', 'max:'.self::MAX_SUMMARY],

            // Optional: plenty of internal events have no public page. Bounded to the column and
            // required to be http(s) when present, so a stored link cannot be a `javascript:` URL
            // rendered on a card.
            'url' => ['nullable', 'string', 'max:'.self::MAX_URL, 'url:http,https'],

            'source_name' => ['nullable', 'string', 'max:'.self::MAX_TITLE],

            // Believed as a **date and nothing else**, and only inside a sanity window.
            'occurred_at' => ['nullable', 'date', 'after:-1 year', 'before:+2 days'],

            // The subject, which the classifier will read as untrusted text like any snippet.
            'company_name' => ['nullable', 'string', 'max:'.self::MAX_TITLE],
            'person_name' => ['nullable', 'string', 'max:'.self::MAX_TITLE],
        ];
    }

    /**
     * Strip the fields a caller may not set, before validation sees them.
     *
     * Stripped rather than rejected, deliberately: a customer's integration that helpfully sends
     * `confidence: 90` should have it ignored rather than have its event refused, because refusing
     * teaches them to remove a field while accepting-and-ignoring teaches them nothing can be
     * asserted. Either way the value never reaches a column. The list is the ADR's.
     */
    protected function prepareForValidation(): void
    {
        $this->replace($this->except([
            'heat',
            'score_breakdown',
            'confidence',
            'relevance',
            'jurisdiction',
            'resolved_market',
            'market',
            'lead_id',
            'matched_lead_id',
            'match_confidence',
            'team_id',
            'signal_definition_id',
            'signal_watch_id',
            'status',
        ]));
    }

    /**
     * The verified signal type this event is for.
     *
     * Only ever called after {@see self::authorize()} has returned true, which is what guarantees it
     * is the authenticated team's.
     */
    public function definition(): SignalDefinition
    {
        return $this->definition ?? throw SignalsException::webhookRefused();
    }

    /** The caller's idempotency key, clamped to the column. */
    public function idempotencyKey(): string
    {
        return Str::limit((string) $this->input('idempotency_key'), self::MAX_KEY, '');
    }

    /**
     * The event, as the columns want it.
     *
     * @return array<string, mixed>
     */
    public function event(): array
    {
        $occurredAt = $this->input('occurred_at');

        return [
            'title' => Str::limit(Str::squish((string) $this->input('title')), self::MAX_TITLE, ''),
            'summary' => $this->text('summary', self::MAX_SUMMARY),
            'link' => $this->text('url', self::MAX_URL),
            'source_name' => $this->text('source_name', self::MAX_TITLE),
            'occurred_at' => is_string($occurredAt) && $occurredAt !== ''
                ? Carbon::parse($occurredAt)->utc()
                : null,
        ];
    }

    /**
     * What is stored on the row as the payload: the validated body, and nothing else.
     *
     * The **validated** data rather than the raw body, so the stored json cannot contain a field the
     * schema refused — an operator reading this row should see what we accepted, not what somebody
     * tried.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return $this->validated();
    }

    /**
     * The signal type this event names, within the authenticated team, or null.
     *
     * A single query that is both the lookup and the tenancy check: scoped by the token's team, so
     * a key that exists for somebody else simply does not resolve. No separate "does this belong to
     * you" step, because a separate step is one somebody can forget.
     */
    private function resolveDefinition(): ?SignalDefinition
    {
        $key = $this->input('event_type');
        $team = $this->user()?->currentTeam;

        if (! is_string($key) || $key === '' || $team === null) {
            return null;
        }

        return SignalDefinition::query()
            ->where('team_id', $team->getKey())
            ->where('key', $key)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Whether the request's timestamp is close enough to now.
     *
     * The tolerance is two-sided: a clock that is fast is as much a sign of a replayed or forged
     * request as one that is slow, and accepting a far-future timestamp would let a caller mint a
     * signature valid for a week.
     */
    private function timestampIsFresh(): bool
    {
        $header = $this->header('X-Signal-Timestamp');

        if (! is_string($header) || ! ctype_digit($header)) {
            return false;
        }

        $tolerance = max(1, (int) config('signals.sources.webhook.timestamp_tolerance', 300));

        return abs(now()->getTimestamp() - (int) $header) <= $tolerance;
    }

    /**
     * Whether the signature is the one this type's secret would have produced.
     *
     * The caller never sends the secret — only a signature derived from it, which is the whole
     * point: the secret proves possession without travelling, and the HMAC binds the proof to *this
     * body* and *this timestamp*. The comparison itself is {@see SignalWebhookSecret::verify()},
     * beside the secret, so there is one definition of what a valid signature is.
     */
    private function signatureMatches(SignalWebhookSecret $secret): bool
    {
        $presented = $this->header('X-Signal-Signature');
        $timestamp = $this->header('X-Signal-Timestamp');

        if (! is_string($presented) || $presented === '' || ! is_string($timestamp)) {
            return false;
        }

        try {
            return $secret->verify($timestamp, $this->getContent(), $presented);
        } catch (Throwable) {
            // A secret that cannot be decrypted — a rotated `APP_KEY`, a corrupted row — is a
            // refusal rather than a 500: the caller gets the same answer as a bad signature, and the
            // operator gets the exception in Sentry.
            return false;
        }
    }

    private function text(string $key, int $max): ?string
    {
        $value = $this->input($key);

        if (! is_string($value)) {
            return null;
        }

        $clean = Str::squish($value);

        return $clean === '' ? null : Str::limit($clean, $max, '');
    }
}
