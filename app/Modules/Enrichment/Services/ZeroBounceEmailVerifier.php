<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Contracts\EmailVerificationProvider;
use App\Modules\Enrichment\Data\CostProfile;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Str;

/**
 * The reference email verifier (PL2): ZeroBounce's v2 single-validate endpoint.
 *
 * **Marked `experimental` and disabled by default** (ADR 0027 §2). That means exactly what the ADR
 * says it means: it is written against the vendor's published documentation, it is tested against
 * our own `Http::fake()` fixtures, **no live call has ever validated this mapping**, and nothing
 * enables it until a written confirmation exists that embedding its answers in a product our
 * customers use is permitted. It exists so that PL3's waterfall is built against a real response
 * shape rather than against four fakes.
 *
 * ZeroBounce is the one of PL0's candidates whose request and response could be pinned from the
 * vendor's own docs. The email **finders** could not be — their response fields were not
 * confirmable — and PL2 therefore ships no finder adapter at all, only the contract and the fake.
 * A reference adapter built on a guessed response shape is worse than none, because it looks done.
 *
 * Four things about the implementation that are the module's conventions rather than this vendor's:
 *
 * - **A refusal is not an outage.** A 400 or a 401 means our key or our request is wrong and will
 *   be wrong next time; it raises `notConfigured` and leaves the breaker shut. A 5xx or a timeout
 *   is the provider being unwell and trips the breaker. That is ADR 0023 §4's distinction, applied
 *   to a vendor instead of to a search source.
 * - **A verdict we do not recognise is `Unverifiable`, not an error.** ZeroBounce can add a status
 *   tomorrow, and the honest answer to a word we have never seen is "I could not tell", not a
 *   failed job.
 * - **Its own breaker key**, so an outage here is not an outage for every other vendor
 *   (ADR 0023) — and it needs a matching `Admin\Enums\PlatformProvider` case before it is ever
 *   enabled, or an open breaker is invisible to operations.
 * - **No retries on a 429.** The documented limit is 80,000 requests per 10 seconds; if we are
 *   hitting it, something is wrong with *us*, and retrying inside the request would turn one
 *   mistake into a rate-limit ban. PL3's rate limiter is what keeps us under it.
 *
 * @see https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-validate-emails
 * @see https://www.zerobounce.net/docs/email-validation-api-quickstart/v2-status-codes
 */
final class ZeroBounceEmailVerifier implements EmailVerificationProvider
{
    public const string KEY = 'zerobounce';

    private const string BREAKER = 'zerobounce';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly CircuitBreaker $breaker,
    ) {}

    public function verifyEmail(string $email): EmailVerificationResult
    {
        $normalised = ContactIdentity::email($email);

        if ($normalised === null) {
            // Not worth a paid call: our own syntax check already knows the answer, and a vendor
            // that bills per attempt would charge us for asking.
            return $this->result($email, EmailVerificationStatus::InvalidSyntax, costMicros: 0);
        }

        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            throw EnrichmentProviderException::notConfigured(self::KEY);
        }

        if ($this->breaker->isOpen(self::BREAKER)) {
            throw EnrichmentProviderException::unavailable(self::KEY, 'circuit open');
        }

        $response = $this->call($apiKey, $normalised);

        $this->guard($response);

        $this->breaker->recordSuccess(self::BREAKER);

        /** @var array<string, mixed> $body */
        $body = (array) $response->json();

        $status = $body['status'] ?? null;

        if (! is_string($status) || $status === '') {
            throw EnrichmentProviderException::unreadable(self::KEY, 'no status in response');
        }

        return $this->result(
            $normalised,
            $this->mapStatus($status, is_string($body['sub_status'] ?? null) ? $body['sub_status'] : null),
            costMicros: $this->describesCost()->forOutcome(true),
            raw: $body,
        );
    }

    /**
     * ZeroBounce's own vocabulary, mapped onto ours.
     *
     * The lossy step, and deliberately so: our column has one vocabulary whoever filled it in
     * (see {@see EmailVerificationStatus}). Where the status alone is too coarse the `sub_status`
     * refines it — `do_not_mail/disposable` is a throwaway domain, which we already have a case
     * for and which reads better to a member than "do not mail".
     *
     * An unrecognised status is {@see EmailVerificationStatus::Unverifiable}: the vendor may add
     * one, and "we could not tell" is the honest reading of a word we do not know.
     */
    private function mapStatus(string $status, ?string $subStatus): EmailVerificationStatus
    {
        $sub = Str::lower(trim((string) $subStatus));

        return match (Str::lower(trim($status))) {
            'valid' => EmailVerificationStatus::Verified,
            'catch-all' => EmailVerificationStatus::AcceptAll,
            'invalid' => EmailVerificationStatus::Undeliverable,
            // Spam traps, abuse addresses and the rest of `do_not_mail` are all "do not write
            // here", which is what Risky means — except a disposable domain, which we name.
            'spamtrap', 'abuse' => EmailVerificationStatus::Risky,
            'do_not_mail' => $sub === 'disposable'
                ? EmailVerificationStatus::DisposableDomain
                : EmailVerificationStatus::Risky,
            'unknown' => EmailVerificationStatus::Unverifiable,
            default => EmailVerificationStatus::Unverifiable,
        };
    }

    /**
     * Turn an HTTP failure into the right kind of exception, and the breaker with it.
     *
     * The distinction is the one that matters commercially: a request we got wrong fails the same
     * way for ever and must not take the vendor out of every other team's waterfall, while a
     * vendor that is down should be stopped being asked.
     */
    private function guard(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        if ($response->status() === 429) {
            // Not a retry and not a breaker trip: it is a statement that we asked too fast, and
            // the fix is the rate limiter rather than asking again harder.
            throw EnrichmentProviderException::unavailable(self::KEY, 'rate limited');
        }

        if ($response->clientError()) {
            // 400/401/403 against a documented endpoint means the key or the request is wrong.
            throw EnrichmentProviderException::notConfigured(self::KEY);
        }

        $this->breaker->recordFailure(self::BREAKER);

        throw EnrichmentProviderException::unavailable(self::KEY, 'http '.$response->status());
    }

    /**
     * The one HTTP call, with the module's timeout and a single retry for a connection that never
     * landed. A connection failure is counted against the breaker; a slow-but-answered call is not.
     */
    private function call(string $apiKey, string $email): Response
    {
        try {
            return $this->http
                ->timeout((int) config('enrichment.providers.'.self::KEY.'.timeout', 10))
                ->retry(2, 200, throw: false)
                ->get($this->endpoint(), [
                    'api_key' => $apiKey,
                    'email' => $email,
                ]);
        } catch (ConnectionException $e) {
            $this->breaker->recordFailure(self::BREAKER);

            throw EnrichmentProviderException::unavailable(self::KEY, $e->getMessage());
        }
    }

    /**
     * A verification result in the module's own shape.
     *
     * `isRoleBased` is borrowed from the free-tier checker rather than reimplemented — it is a pure
     * config lookup, and a second definition of "what counts as info@" is a second answer waiting
     * to disagree with the first.
     *
     * @param  array<string, mixed>  $raw
     */
    private function result(
        string $email,
        EmailVerificationStatus $status,
        int $costMicros,
        array $raw = [],
    ): EmailVerificationResult {
        $isRoleBased = app(EmailVerifier::class)->isRoleBased($email);

        return new EmailVerificationResult(
            email: $email,
            status: $status,
            confidence: $status->confidence(),
            isRoleBased: $isRoleBased,
            isPersonal: ! $isRoleBased,
            provider: self::KEY,
            costMicros: $costMicros,
            raw: $raw,
        );
    }

    private function endpoint(): string
    {
        return rtrim((string) config(
            'enrichment.providers.'.self::KEY.'.endpoint',
            'https://api.zerobounce.net/v2/validate',
        ), '/');
    }

    private function apiKey(): ?string
    {
        $key = config('enrichment.providers.'.self::KEY.'.api_key');

        return is_string($key) && trim($key) !== '' ? trim($key) : null;
    }

    public function key(): string
    {
        return self::KEY;
    }

    public function capability(): ProviderCapability
    {
        return ProviderCapability::EmailVerify;
    }

    public function describesCost(): CostProfile
    {
        return new CostProfile(
            // A verifier bills per lookup, hit or miss, which is why the cost sits on
            // `perAttemptMicros`: every "no" is chargeable and PL4's margin figures depend on
            // knowing that. The figure comes from config because it is a contract term.
            perAttemptMicros: (int) config('enrichment.providers.'.self::KEY.'.cost_micros', 0),
        );
    }

    public function isEnabled(): bool
    {
        return (bool) config('enrichment.providers.'.self::KEY.'.enabled', false)
            && $this->apiKey() !== null;
    }
}
