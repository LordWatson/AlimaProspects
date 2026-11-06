<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Data\SignalMatch;
use App\Modules\Signals\Enums\SignalMatchConfidence;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Support\Str;

/**
 * Is this event about a company the team already has? (S10)
 *
 * **It reuses the name rule rather than inventing one.** `LeadSourcing\Support\CompanyNameKey` is
 * the same normalisation `SourcedLead::dedupeKey()` has used since Phase 7, extracted so that both
 * read it — because a third name-matching rule is how two leads become three, and this one would
 * have been the third.
 *
 * **It errs toward no match, by design.** The way account monitoring goes wrong is an event pinned
 * to the wrong company: a member sees "Acme was sold" on the wrong lead, calls the wrong person and
 * stops trusting the feature. So there are exactly two confidences and no third
 * ({@see SignalMatchConfidence}) — the team's own statement or an identical name, and a name that
 * agrees once the legal form is set aside. Anything weaker is **null**, and a signal with no match
 * behaves exactly as it did before this phase.
 *
 * It deliberately does **not** match on:
 *
 * - **A person's name alone.** "Jane Smith" is not a company, and a contact called Jane Smith at an
 *   unrelated lead is a coincidence rather than a signal. A person only strengthens a match the
 *   company name already made (see {@see self::corroboratedBy()}).
 * - **Substrings.** "Acme" would match "Acme Holdings", "Acme Air" and "Acmetech"; the plan's own
 *   risk note is about exactly this.
 * - **An ambiguous looser name.** Two leads whose names agree only once the legal form is set aside
 *   — "Acme Ltd" and "Acme Inc" — are a question, not an answer, so the matcher returns **null**
 *   rather than picking whichever row came back first (review finding).
 * - **The counterparty, when the subject already matched.** An acquisition names two companies, and
 *   the event is about the one being bought — which is the subject. The counterparty is tried only
 *   when the subject matched nothing, because "our lead bought somebody" is also news about our
 *   lead.
 *
 * **The team is passed, not assumed.** Every query here is team-scoped twice: by the `BelongsToTeam`
 * global scope and by an explicit `where('team_id')`. The global scope applies **nothing** when
 * `TeamContext::id()` is null, so a caller outside the context would otherwise match a signal
 * against every tenant's leads and write another team's id into `matched_lead_id` — the worst bug
 * this phase could have, and not one to leave resting on the call site remembering.
 */
final class SignalLeadMatcher
{
    /**
     * The leads of each team this instance has been asked about, by comparable name.
     *
     * Read **once per team per run** rather than once per signal. The alternative measured badly on
     * paper: fifty kept signals against twenty thousand leads, tried twice each (subject then
     * counterparty), is a million hydrated models for one watch. Nothing here mutates leads, and an
     * instance lives for one run, so the snapshot cannot go stale in a way that matters.
     *
     * @var array<int, array{strict: array<string, list<Lead>>, bare: array<string, list<Lead>>}>
     */
    private array $byTeam = [];

    /**
     * The lead these names are about, or null when nothing is sure enough.
     *
     * Takes the **names rather than a `Signal`**, because the run matches while the row is still
     * being built — the match is one of the row's own columns, so a signal is never stored
     * unmatched and then corrected. It also means the same rule serves a signal that already
     * exists ({@see self::forSignal()}).
     */
    public function match(
        int $teamId,
        ?string $subjectCompany,
        ?string $counterparty = null,
        ?string $person = null,
    ): ?SignalMatch {
        // **Every certain source before any inferred one**, which is not the same as "watchlist
        // first": a watchlist hit that itself needed the legal form set aside is an inference too,
        // and taking it ahead of an *identical* lead name would answer `Likely` where the team's
        // own records say `Exact`.
        //
        // 1. The team's own statement about this exact name. A watchlist entry carrying a
        //    `lead_id` is somebody saying "this name is that lead" — there is nothing to infer.
        $declared = $this->fromWatchlist($teamId, $subjectCompany, loose: false);

        if ($declared instanceof SignalMatch) {
            return $declared;
        }

        // 2. The subject company, which is what the event is about: an identical name, else a name
        //    that agrees once the legal form is set aside.
        $subject = $this->byName($teamId, $subjectCompany, $person);

        if ($subject instanceof SignalMatch) {
            return $subject;
        }

        // 3. A watchlist name that agrees once the legal form is set aside — "Delta Freight Inc"
        //    in an article against an entry called "Delta Freight". Still the team's list, so
        //    still worth asking, but an inference and labelled as one.
        $loosely = $this->fromWatchlist($teamId, $subjectCompany, loose: true);

        if ($loosely instanceof SignalMatch) {
            return $loosely;
        }

        // 4. The counterparty, only now: "our lead bought somebody" is news about our lead, but if
        //    the subject matched we already have the better answer.
        return $this->byName($teamId, $counterparty, $person);
    }

    /**
     * The same question about a signal that already exists — the attach-to-lead path.
     */
    public function forSignal(Signal $signal): ?SignalMatch
    {
        return $this->match(
            (int) $signal->team_id,
            $signal->subject_company_name,
            $signal->counterparty_name,
            $signal->subject_person_name,
        );
    }

    /**
     * A match the team declared by putting this name on a watchlist against a lead.
     *
     * `$loose` picks which claim is being made. The **strict** key is the team's statement and
     * reads as `Exact`. The looser key is still only a legal form set aside — "Delta Inc" in an
     * article against an entry called "Delta Freight" — so it is `Likely`, like any other bare
     * match (ADR 0021 §5): labelling it `Exact` would suppress the confirmation prompt that is the
     * one thing the weaker case exists to show.
     */
    private function fromWatchlist(int $teamId, ?string $subjectCompany, bool $loose): ?SignalMatch
    {
        if ($subjectCompany === null) {
            return null;
        }

        $key = $loose ? CompanyNameKey::bare($subjectCompany) : CompanyNameKey::for($subjectCompany);

        if ($key === '') {
            return null;
        }

        $entry = SignalWatchlistEntry::query()
            ->where('team_id', $teamId)
            ->whereNotNull('lead_id')
            ->where('is_active', true)
            ->where('entity_type', WatchlistEntityType::Company->value)
            ->where('normalised_name', $key)
            ->orderBy('id')
            ->first();

        $lead = $entry?->lead;

        if (! $entry instanceof SignalWatchlistEntry || ! $lead instanceof Lead || $lead->anonymised_at !== null) {
            return null;
        }

        return new SignalMatch(
            lead: $lead,
            confidence: $loose ? SignalMatchConfidence::Likely : SignalMatchConfidence::Exact,
            reason: $loose ? 'watchlist_without_legal_form' : 'watchlist',
            matchedName: $entry->name,
        );
    }

    /**
     * A match on a company name, strict first and then with the legal form set aside.
     */
    private function byName(int $teamId, ?string $name, ?string $person): ?SignalMatch
    {
        if ($name === null) {
            return null;
        }

        $strict = CompanyNameKey::for($name);

        if ($strict === '') {
            return null;
        }

        $index = $this->index($teamId);

        // An identical name is an identical name, so several rows under it are duplicates of one
        // company rather than an ambiguity. The lowest id wins, which makes the choice stable
        // across runs instead of depending on row order.
        $exact = $index['strict'][$strict] ?? [];

        if ($exact !== []) {
            return new SignalMatch(
                lead: $exact[0],
                confidence: SignalMatchConfidence::Exact,
                reason: 'name',
                matchedName: $exact[0]->company_name,
            );
        }

        $bare = CompanyNameKey::bare($name);
        $loose = $bare === '' ? [] : ($index['bare'][$bare] ?? []);

        // Two different companies agreeing once the legal form is dropped is the false positive this
        // phase was warned about. Err toward no match.
        if (count($loose) !== 1) {
            return null;
        }

        $lead = $loose[0];

        if ($this->corroboratedBy($person, $lead)) {
            // A named person who is already a contact on that lead turns an inference into the
            // team's own record agreeing with it.
            return new SignalMatch(
                lead: $lead,
                confidence: SignalMatchConfidence::Exact,
                reason: 'name_and_known_contact',
                matchedName: $lead->company_name,
            );
        }

        return new SignalMatch(
            lead: $lead,
            confidence: SignalMatchConfidence::Likely,
            reason: 'name_without_legal_form',
            matchedName: $lead->company_name,
        );
    }

    /**
     * This team's leads by comparable name, read once.
     *
     * Candidates are compared in PHP because the strict key is **not** a stored column on `leads` —
     * `dedupe_key` is only a name key for a name-only lead, so a registry-keyed lead would be missed
     * by a key comparison. Anonymised leads are left out here rather than skipped later: their name
     * is gone, and attaching a fresh named event to one would put the person back
     * (`docs/compliance.md` §4).
     *
     * @return array{strict: array<string, list<Lead>>, bare: array<string, list<Lead>>}
     */
    private function index(int $teamId): array
    {
        if (isset($this->byTeam[$teamId])) {
            return $this->byTeam[$teamId];
        }

        $index = ['strict' => [], 'bare' => []];

        Lead::query()
            ->where('team_id', $teamId)
            ->whereNull('anonymised_at')
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '')
            ->orderBy('id')
            ->select(['id', 'team_id', 'company_name', 'website', 'company_number', 'anonymised_at'])
            ->chunk(500, static function ($leads) use (&$index): void {
                foreach ($leads as $lead) {
                    $strict = CompanyNameKey::for($lead->company_name);

                    if ($strict === '') {
                        continue;
                    }

                    $index['strict'][$strict][] = $lead;

                    $bare = CompanyNameKey::bare($lead->company_name);

                    if ($bare !== '') {
                        $index['bare'][$bare][] = $lead;
                    }
                }
            });

        return $this->byTeam[$teamId] = $index;
    }

    /**
     * Whether the person the article names is already a contact on this lead.
     *
     * Only ever **strengthens** a match the company name made. A person is never a match on their
     * own: two people share a name far more often than two companies do, and a contact called Jane
     * Smith at an unrelated lead is a coincidence.
     */
    private function corroboratedBy(?string $person, Lead $lead): bool
    {
        $name = Str::squish((string) $person);

        if ($name === '') {
            return false;
        }

        $key = Str::lower($name);

        return $lead->contacts()
            ->whereNotNull('name')
            ->get(['id', 'name'])
            ->contains(fn (object $contact): bool => Str::lower(Str::squish((string) $contact->name)) === $key);
    }
}
