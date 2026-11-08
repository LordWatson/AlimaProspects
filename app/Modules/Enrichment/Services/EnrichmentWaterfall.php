<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Contracts\EmailFinder;
use App\Modules\Enrichment\Contracts\EmailVerificationProvider;
use App\Modules\Enrichment\Contracts\EnrichmentProvider;
use App\Modules\Enrichment\Contracts\PersonFinder;
use App\Modules\Enrichment\Contracts\PhoneFinder;
use App\Modules\Enrichment\Data\CompanyQuery;
use App\Modules\Enrichment\Data\EmailVerificationResult;
use App\Modules\Enrichment\Data\EnrichmentResult;
use App\Modules\Enrichment\Data\FoundPerson;
use App\Modules\Enrichment\Data\PeopleResult;
use App\Modules\Enrichment\Data\PersonCriteria;
use App\Modules\Enrichment\Data\PersonQuery;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\ContactEnrichmentState;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Exceptions\EnrichmentProviderException;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Actions\FindActiveIcpAction;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Try vendors in order until one answers, record every call, and stop as soon as the answer is good
 * enough (PL3).
 *
 * This class holds the one piece of judgement the whole phase is about: **when to stop paying.**
 * Everything else — which vendors, in what order, at what price — is config and contracts.
 *
 * ## The four rules it enforces, each guarding a specific way money leaks
 *
 * 1. **Stop at the first good-enough answer.** Not the first *answer*: a verifier that says
 *    "accept-all" has answered without telling us anything, and `enrichment.stop_on.email_verify`
 *    is what decides whether that is good enough (it defaults to `verified`, so it is not).
 * 2. **A miss falls through; an error falls through; a skip costs nothing.** Three different
 *    reasons to try the next provider, recorded as three different outcomes, because folding them
 *    together makes an outage look like a vendor that is bad at its job.
 * 3. **Never ask a question that cannot be answered.** `PersonQuery::isAnswerable()` is checked
 *    before a provider is called, not after it answers — a vendor that bills per attempt charges
 *    for a question we could have known was unanswerable.
 * 4. **Ask the cheap gate before the expensive call.** The rate limiter and the breaker are both
 *    consulted first and both produce a skip, so a paused provider costs a row in the attempts
 *    table and nothing else.
 *
 * ## What it is not responsible for
 *
 * It does not decide **whether** a team may spend — that is the `CreditReserver` the Action asks
 * before this is ever reached — and it does not decide whether a prospect may be **contacted**,
 * which is `OutreachGuard`'s answer. It asks {@see ContactApprovalGate} about storing a person,
 * which is the narrower question (PL5 binds the real one).
 *
 * ## Transactions
 *
 * **No vendor call happens inside a transaction** (CLAUDE.md §2). Each contact write is its own
 * short transaction after the call that produced it, so a provider that hangs for ten seconds is
 * not a lock held for ten seconds — and a run that dies half way has stored the contacts it found
 * rather than rolling back work somebody has already been charged for.
 */
final class EnrichmentWaterfall
{
    public function __construct(
        private readonly EnrichmentProviderRegistry $registry,
        private readonly ProviderRateLimiter $limiter,
        private readonly CircuitBreaker $breaker,
        private readonly AttemptRecorder $attempts,
        private readonly PhoneNormaliser $phones,
        private readonly ContactApprovalGate $approval,
        private readonly FindActiveIcpAction $activeIcp,
    ) {}

    /**
     * How long the last provider call took, in milliseconds.
     *
     * Carried on the instance rather than returned, because {@see self::attempt()} hands the result
     * back and the **caller** decides what outcome it was — so the timing has to survive that
     * split. Safe because a waterfall is strictly sequential: one call at a time, read immediately
     * after, and the service is resolved per job rather than shared.
     */
    private ?int $lastLatencyMs = null;

    /**
     * Run one scope for one lead, and return what happened as the run's summary.
     *
     * @return array<string, mixed>
     */
    public function run(EnrichmentRun $run, Lead $lead, Team $team): array
    {
        return match ($run->scope) {
            EnrichmentScope::People => $this->findPeople($run, $lead, $team),
            EnrichmentScope::Email => $this->findEmails($run, $lead, $team),
            EnrichmentScope::Phone => $this->findPhones($run, $lead, $team),
            EnrichmentScope::Verify => $this->verifyEmails($run, $lead, $team),
        };
    }

    // -----------------------------------------------------------------------------------------
    // People
    // -----------------------------------------------------------------------------------------

    /**
     * Find decision-makers, and turn them into contacts.
     *
     * The only scope that creates contacts, and the most expensive — a people-finder bills for what
     * it returns, so the ICP's own titles and a hard limit are what keep "who works at Acme" from
     * being a bill for forty records to reach the two a member would have called.
     *
     * @return array<string, mixed>
     */
    private function findPeople(EnrichmentRun $run, Lead $lead, Team $team): array
    {
        $company = CompanyQuery::forLead($lead);
        // Through the ICP module's own Action rather than a relation on the team: which profile is
        // active is that module's question, and it already answers it for Scoring.
        $criteria = PersonCriteria::fromIcp($this->activeIcp->handle($team), $this->peopleLimit($team));

        if (! $company->isAnswerable()) {
            // No domain and no company number: a people-finder would return whoever it lists first
            // at a company of that name, which is rarely a decision-maker and always chargeable.
            $this->attempts->skipped($run, $lead, null, ProviderCapability::PeopleFind, 'n/a', AttemptOutcome::SkippedUnanswerable);

            return ['people_found' => 0, 'contacts_created' => 0, 'reason' => 'company_unanswerable'];
        }

        $created = 0;
        $found = 0;

        foreach ($this->providersFor(ProviderCapability::PeopleFind, $run, $lead, null) as $provider) {
            if (! $provider instanceof PersonFinder) {
                continue; // the registry refuses this at registration; belt and braces
            }

            $result = $this->attempt(
                $run,
                $lead,
                null,
                $provider,
                static fn (): PeopleResult => $provider->findPeople($company, $criteria),
            );

            if (! $result instanceof PeopleResult) {
                continue;
            }

            $this->attempts->record(
                $run,
                $lead,
                null,
                $provider,
                $result->found() ? AttemptOutcome::Hit : AttemptOutcome::Miss,
                latencyMs: $this->lastLatencyMs,
                costMicros: $result->costMicros,
            );

            $found += count($result->named());

            foreach ($result->named() as $person) {
                if ($this->storePerson($lead, $team, $person, $provider->key())) {
                    $created++;
                }
            }

            if ($found > 0) {
                break; // somebody answered; the next provider is money for a second opinion
            }
        }

        return ['people_found' => $found, 'contacts_created' => $created];
    }

    /**
     * Turn one found person into a contact, if we are allowed to hold them.
     *
     * De-duplicated on the address's **identity** where there is one, and on the name where there
     * is not: a people-finder run twice must not double the contacts, and the second run is often
     * a different vendor returning the same person with the same address spelled differently.
     */
    private function storePerson(Lead $lead, Team $team, FoundPerson $person, string $provider): bool
    {
        $email = $person->email;

        if ($email !== null && ! $this->approval->allowsEmail($team, $email)) {
            // Somebody who asked to be left alone should not be stored at all — not stored and
            // then filtered at send time (docs/compliance.md §3.9).
            return false;
        }

        return DB::transaction(function () use ($lead, $person, $email, $provider): bool {
            $existing = $this->matchExisting($lead, $person, $email);

            $contact = $existing ?? new LeadContact;

            $contact->forceFill([
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                // Fill gaps, never overwrite: a name read off the company's own page outranks a
                // vendor's spelling of it, and the same rule the lead de-duplicator follows.
                'name' => $contact->name ?? $person->name(),
                'first_name' => $contact->first_name ?? $person->firstName,
                'last_name' => $contact->last_name ?? $person->lastName,
                'title' => $contact->title ?? $person->title,
                'department' => $contact->department ?? $person->department,
                'seniority' => $contact->seniority ?? $person->seniority,
                'linkedin_url' => $contact->linkedin_url ?? $person->linkedinUrl,
                'source' => $contact->source ?? $provider,
                'method' => $contact->method ?? 'provider',
                'collected_at' => $contact->collected_at ?? Carbon::now(),
                'enrichment_state' => ContactEnrichmentState::Done,
                'enriched_at' => Carbon::now(),
            ]);

            // An address that arrived bundled with the person is a free answer to the next
            // question — but it is **unverified**, whatever the vendor implies by including it.
            if ($email !== null && $contact->email === null) {
                $contact->setEmail($email, $provider);
                $contact->email_verification_status = EmailVerificationStatus::Unknown;
            }

            $contact->save();

            return $existing === null;
        });
    }

    /**
     * The contact this person already is, if any.
     */
    private function matchExisting(Lead $lead, FoundPerson $person, ?string $email): ?LeadContact
    {
        $normalised = $email === null ? null : ContactIdentity::email($email);

        if ($normalised !== null) {
            // Normalised **before** hashing, and skipped entirely when the value is not an
            // address: hashing a cast-to-empty-string would produce a key that matches every other
            // row written the same wrong way, which is a de-duplication bug that merges strangers.
            $byEmail = $lead->contacts()
                ->where('email_hash', ContactIdentity::hash($normalised))
                ->first();

            if ($byEmail instanceof LeadContact) {
                return $byEmail;
            }
        }

        $name = $person->name();

        return $name === null ? null : $lead->contacts()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first();
    }

    // -----------------------------------------------------------------------------------------
    // Email
    // -----------------------------------------------------------------------------------------

    /**
     * Find an address for each contact that has none, then verify what was found.
     *
     * Verifying in the same pass is the point: an address nobody has checked is an address a member
     * cannot trust, and running a second scope to find out would mean two jobs and two waits for
     * one answer.
     *
     * @return array<string, mixed>
     */
    private function findEmails(EnrichmentRun $run, Lead $lead, Team $team): array
    {
        $found = 0;
        $verified = 0;

        foreach ($this->contactsNeedingEmail($lead) as $contact) {
            $query = PersonQuery::forContact($contact, $lead);

            if (! $query->isAnswerable()) {
                $this->attempts->skipped($run, $lead, $contact, ProviderCapability::EmailFind, 'n/a', AttemptOutcome::SkippedUnanswerable);

                continue;
            }

            $result = $this->findOne(
                ProviderCapability::EmailFind,
                $run,
                $lead,
                $contact,
                static fn (EnrichmentProvider $provider): ?EnrichmentResult => $provider instanceof EmailFinder
                    ? $provider->findEmail($query)
                    : null,
            );

            if ($result === null) {
                continue;
            }

            $email = (string) $result->value;

            if (! $this->approval->allowsEmail($team, $email)) {
                continue;
            }

            if (! $contact->setEmail($email, $result->provider)) {
                continue; // not an address at all; nothing to store
            }

            $contact->email_confidence = $result->confidence;
            $contact->enrichment_state = ContactEnrichmentState::Done;
            $contact->enriched_at = Carbon::now();
            $contact->save();

            $found++;

            if ($this->verifyOne($run, $lead, $contact)) {
                $verified++;
            }
        }

        return ['emails_found' => $found, 'emails_verified' => $verified];
    }

    /**
     * Verify the addresses we already hold.
     *
     * @return array<string, mixed>
     */
    private function verifyEmails(EnrichmentRun $run, Lead $lead, Team $team): array
    {
        $verified = 0;
        $checked = 0;

        foreach ($lead->contacts as $contact) {
            if ($contact->email === null || ! $this->worthReverifying($contact)) {
                continue;
            }

            $checked++;

            if ($this->verifyOne($run, $lead, $contact)) {
                $verified++;
            }
        }

        return ['emails_checked' => $checked, 'emails_verified' => $verified];
    }

    /**
     * Run the verify waterfall for one contact, stopping at the first verdict good enough.
     *
     * Returns whether the stored verdict now clears the configured bar — which is **not** the same
     * as "a provider answered": the whole reason `stop_on` exists is that `accept_all` is an answer
     * that tells us nothing.
     */
    private function verifyOne(EnrichmentRun $run, Lead $lead, LeadContact $contact): bool
    {
        $email = (string) $contact->email;
        $best = null;

        foreach ($this->providersFor(ProviderCapability::EmailVerify, $run, $lead, $contact) as $provider) {
            if (! $provider instanceof EmailVerificationProvider) {
                continue;
            }

            $result = $this->attempt(
                $run,
                $lead,
                $contact,
                $provider,
                static fn (): EmailVerificationResult => $provider->verifyEmail($email),
            );

            if (! $result instanceof EmailVerificationResult) {
                continue;
            }

            $this->attempts->record(
                $run,
                $lead,
                $contact,
                $provider,
                // A verdict is a hit whatever it says: the vendor answered, and we were charged.
                // Whether the answer was *good enough* is the `stop_on` question below.
                $result->status->isVendorVerdict() ? AttemptOutcome::Hit : AttemptOutcome::Miss,
                latencyMs: $this->lastLatencyMs,
                costMicros: $result->costMicros,
                statusReturned: $result->status->value,
            );

            $best = $result;

            if ($this->goodEnough($result->status)) {
                break;
            }
        }

        if ($best === null) {
            return false;
        }

        $contact->forceFill([
            'email_verification_status' => $best->status,
            'email_confidence' => $best->confidence,
            'email_verified_at' => Carbon::now(),
            'email_source' => $best->provider ?? $contact->email_source,
            'is_role_based' => $best->isRoleBased,
            'enrichment_state' => ContactEnrichmentState::Done,
            'enriched_at' => Carbon::now(),
        ])->save();

        return $this->goodEnough($best->status);
    }

    // -----------------------------------------------------------------------------------------
    // Phone
    // -----------------------------------------------------------------------------------------

    /**
     * Find a number for each contact that has none.
     *
     * @return array<string, mixed>
     */
    private function findPhones(EnrichmentRun $run, Lead $lead, Team $team): array
    {
        $found = 0;

        foreach ($lead->contacts as $contact) {
            if ($contact->phoneValue() !== null) {
                continue;
            }

            $query = PersonQuery::forContact($contact, $lead);

            if (! $query->isAnswerable()) {
                $this->attempts->skipped($run, $lead, $contact, ProviderCapability::PhoneFind, 'n/a', AttemptOutcome::SkippedUnanswerable);

                continue;
            }

            $result = $this->findOne(
                ProviderCapability::PhoneFind,
                $run,
                $lead,
                $contact,
                static fn (EnrichmentProvider $provider): ?EnrichmentResult => $provider instanceof PhoneFinder
                    ? $provider->findPhone($query)
                    : null,
            );

            if ($result === null) {
                continue;
            }

            $number = (string) $result->value;

            if (! $this->approval->allowsPhone($team, $number)) {
                continue;
            }

            // The vendor's own classification is preferred; the library is the fallback for the
            // vendors that do not say.
            $type = $result->phoneType ?? $this->phones->classify($number, $lead->country);

            $contact->setPhone(
                $number,
                $type,
                $result->provider,
                // Null where it cannot be read with confidence: the screen then shows the raw
                // value and offers no tap-to-call, which is better than dialling somebody else.
                e164: $this->phones->toE164($number, $lead->country),
            );

            $contact->phone_found_at = Carbon::now();
            $contact->enrichment_state = ContactEnrichmentState::Done;
            $contact->enriched_at = Carbon::now();
            $contact->save();

            $found++;
        }

        return ['phones_found' => $found];
    }

    // -----------------------------------------------------------------------------------------
    // The machinery
    // -----------------------------------------------------------------------------------------

    /**
     * Walk a capability's providers until one finds something, recording each attempt.
     *
     * The shared half of the email and phone waterfalls: both ask a provider for one value, both
     * treat a null as "try the next one", and both record every call. `$ask` returns null for a
     * provider that does not implement the capability's contract, which the registry already
     * refuses — belt and braces, and it keeps the closure's type honest.
     *
     * @param  callable(EnrichmentProvider): ?EnrichmentResult  $ask
     */
    private function findOne(
        ProviderCapability $capability,
        EnrichmentRun $run,
        Lead $lead,
        ?LeadContact $contact,
        callable $ask,
    ): ?EnrichmentResult {
        foreach ($this->providersFor($capability, $run, $lead, $contact) as $provider) {
            $result = $this->attempt($run, $lead, $contact, $provider, static fn (): ?EnrichmentResult => $ask($provider));

            if (! $result instanceof EnrichmentResult) {
                continue;
            }

            $this->attempts->record(
                $run,
                $lead,
                $contact,
                $provider,
                $result->found() ? AttemptOutcome::Hit : AttemptOutcome::Miss,
                latencyMs: $this->lastLatencyMs,
                costMicros: $result->costMicros,
            );

            if ($result->found()) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Make one call, timing it and turning a failure into a recorded error rather than an
     * exception.
     *
     * Returns null when the call failed, so the caller's only decision is whether to keep going.
     * The **success** path deliberately does not record: what counts as a hit differs per
     * capability (a verifier that says "risky" has answered and charged us), so the caller records
     * it with the outcome only it can decide. {@see self::$lastLatencyMs} is how the timing
     * survives that split.
     *
     * @template TResult of object
     *
     * @param  callable(): ?TResult  $call
     * @return TResult|null
     */
    private function attempt(
        EnrichmentRun $run,
        Lead $lead,
        ?LeadContact $contact,
        EnrichmentProvider $provider,
        callable $call,
    ): ?object {
        $startedAt = microtime(true);

        try {
            $result = $call();
            $this->lastLatencyMs = $this->elapsed($startedAt);

            return $result;
        } catch (EnrichmentProviderException $e) {
            $this->attempts->record(
                $run,
                $lead,
                $contact,
                $provider,
                AttemptOutcome::Error,
                latencyMs: $this->elapsed($startedAt),
                errorCode: $e->errorCode(),
            );

            return null;
        } catch (Throwable) {
            // A provider that threw something we did not design for is still a provider that
            // failed: recorded as an error and fallen through, never allowed to fail the run — one
            // badly behaved adapter must not take the other vendors down with it.
            $this->attempts->record(
                $run,
                $lead,
                $contact,
                $provider,
                AttemptOutcome::Error,
                latencyMs: $this->elapsed($startedAt),
                errorCode: 'enrichment.provider_threw',
            );

            return null;
        }
    }

    /**
     * The providers worth trying for this capability, in config order, with the cheap gates already
     * applied — so a paused or rate-limited provider is recorded as a skip and never called.
     *
     * A generator, because the caller stops as soon as something answers and the gates for the
     * providers after it should never be evaluated.
     *
     * @return iterable<EnrichmentProvider>
     */
    private function providersFor(
        ProviderCapability $capability,
        EnrichmentRun $run,
        Lead $lead,
        ?LeadContact $contact,
    ): iterable {
        foreach ($this->registry->for($capability) as $provider) {
            if ($this->breaker->isOpen($this->breakerKey($provider))) {
                $this->attempts->skipped($run, $lead, $contact, $capability, $provider->key(), AttemptOutcome::SkippedCircuitOpen);

                continue;
            }

            if (! $this->limiter->attempt($provider)) {
                // Skipped rather than slept: the next provider can usually answer immediately, and
                // holding a worker for a minute to save one call is a bad trade for the member.
                $this->attempts->skipped($run, $lead, $contact, $capability, $provider->key(), AttemptOutcome::SkippedRateLimited);

                continue;
            }

            yield $provider;
        }
    }

    /**
     * Whether a verdict clears the configured bar.
     *
     * `accept_all` deliberately does not by default: a catch-all domain cannot *disprove* an
     * address, which reads as a pass and is not one, and a team that stopped there would be paying
     * for confidence it had not bought.
     */
    private function goodEnough(EmailVerificationStatus $status): bool
    {
        $required = EmailVerificationStatus::tryFrom(
            (string) config('enrichment.stop_on.email_verify', EmailVerificationStatus::Verified->value),
        ) ?? EmailVerificationStatus::Verified;

        return $status->confidence() >= $required->confidence();
    }

    /**
     * Contacts with no address, in a stable order so a retry does the same work.
     *
     * @return iterable<LeadContact>
     */
    private function contactsNeedingEmail(Lead $lead): iterable
    {
        return $lead->contacts
            ->filter(static fn (LeadContact $contact): bool => $contact->email === null)
            ->sortBy('id');
    }

    /**
     * Whether an address is worth paying to check again.
     *
     * Never checked, or checked longer ago than `reenrich_after_days`. The window exists because a
     * mailbox that existed last month usually still does, and re-verifying a team's whole list
     * nightly would be a bill for confirming what we already knew.
     */
    private function worthReverifying(LeadContact $contact): bool
    {
        if ($contact->email_verified_at === null) {
            return true;
        }

        $days = max(1, (int) config('enrichment.reenrich_after_days', 30));

        return $contact->email_verified_at->lt(Carbon::now()->subDays($days));
    }

    /**
     * How many people one run may pay for. From config; PL4 narrows it per plan.
     */
    private function peopleLimit(Team $team): int
    {
        return max(1, (int) config('enrichment.max_people_per_run', 3));
    }

    /**
     * The breaker key for a provider — its own, so one vendor's outage is not another's (ADR 0023).
     */
    private function breakerKey(EnrichmentProvider $provider): string
    {
        return $provider->key();
    }

    private function elapsed(float $startedAt): int
    {
        return (int) round((microtime(true) - $startedAt) * 1000);
    }
}
