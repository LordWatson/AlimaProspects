<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\CompaniesHouseNumberLookup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Give a watchlist entry its Companies House number, once (S13b).
 *
 * **Why this is an Action and not something the source does.** The registry is addressed by number
 * and a watchlist entry is a name a member typed or a lead the pipeline mirrored, so a number has to
 * be found before any filing can be fetched. Two places it must *not* happen:
 *
 * - not in `SignalQueryBuilder`, which is deterministic and does no I/O — that is what lets the
 *   cost estimator and S9's preview build a real plan without spending anything;
 * - not in `CompaniesHouseSignalSource`, because a provider maps a response and must not write to a
 *   tenant's tables (`SignalSourceProvider`'s contract).
 *
 * So the run calls this first, it writes the numbers, and the builder reads them off the entries.
 *
 * **Free before paid.** A `pipeline` watchlist mirrors the team's own leads, and a lead discovered
 * through Companies House already carries `leads.company_number` — so most entries resolve from the
 * database with no request at all. Only a manual entry, or a lead found by another provider, needs
 * the registry's search.
 *
 * **It never guesses.** Companies House search is fuzzy: "Greggs" returns a page of companies with
 * Greggs in the name. Pinning a filing to the wrong company is the failure ADR 0021 named for lead
 * matching — "an event pinned to the wrong company" — and it is worse here, because a registry
 * filing arrives with confidence 100 and skips the AI, so nothing downstream would doubt it. A
 * candidate is therefore accepted **only on an exact normalised-name match**, through the one name
 * rule in {@see CompanyNameKey}, and anything less is recorded as "asked, not found".
 *
 * That record is the point of `company_number_checked_at`. Most manual entries will never resolve —
 * a trading name is not a registered name, an overseas business is not on the UK register — and
 * without remembering the attempt every one would cost a search on every run for ever.
 */
final class ResolveWatchlistCompanyNumbersAction
{
    public function __construct(private readonly CompaniesHouseNumberLookup $lookup) {}

    /**
     * Resolve up to the configured ceiling of this list's unresolved company entries.
     *
     * Must run inside the watch's `TeamContext`, like everything else in a run.
     *
     * @param  list<SignalWatchlistEntry>  $entries  The ring slice this run is about to search, in
     *                                               the order it will read them (S13b review,
     *                                               finding 6) — so the per-run search ceiling is
     *                                               spent on the accounts about to be searched
     *                                               rather than on the head of the list every run.
     * @return int how many entries gained a number
     */
    public function handle(int $teamId, array $entries): int
    {
        $pending = array_values(array_filter(
            $entries,
            static fn (SignalWatchlistEntry $entry): bool => $entry->isRegistryAddressable()
                && $entry->company_number === null
                && ! $entry->companyNumberResolved(),
        ));

        // **The negative cache is re-read against the team's own data, for free** (S13b review,
        // finding 13). An entry checked and not found is never asked again, which is the whole
        // point — but "not found" was recorded against the registry *and* against
        // `leads.company_number` as it stood that day, and the second half of that goes stale. A
        // pipeline entry checked in week one and enriched with a company number in week two would
        // have been skipped for ever, because the filter above excludes anything stamped. So a
        // stamped entry that has a lead is reconsidered on the **free** path only: no search, no
        // ceiling, one query that was happening anyway.
        $recheck = array_values(array_filter(
            $entries,
            static fn (SignalWatchlistEntry $entry): bool => $entry->isRegistryAddressable()
                && $entry->company_number === null
                && $entry->companyNumberResolved()
                && $entry->lead_id !== null,
        ));

        $fromLeads = 0;

        foreach ($recheck as $entry) {
            $number = $this->fromLead($teamId, $entry);

            if ($number !== null) {
                $this->remember($entry, $number);
                $fromLeads++;
            }
        }

        if ($pending === []) {
            if ($fromLeads > 0) {
                Log::channel('signals')->info('Re-read watchlist company numbers from the team\'s own leads.', [
                    'team_id' => $teamId,
                    'resolved' => $fromLeads,
                ]);
            }

            return $fromLeads;
        }

        // Bounded per run, because each unresolved entry past the free path is one registry search
        // and a freshly pasted list of 250 names would otherwise make 250 of them in one go. The
        // rest resolve on the next run; `company_number_checked_at` means nothing is asked twice.
        $ceiling = max(1, (int) config('signals.sources.companies_house.resolve_per_run', 10));
        $resolved = 0;
        $searched = 0;

        foreach ($pending as $entry) {
            $number = $this->fromLead($teamId, $entry);

            if ($number === null) {
                if ($searched >= $ceiling) {
                    // Deliberately **not** stamped as checked: we did not ask, so a later run must.
                    continue;
                }

                $searched++;
                $number = $this->fromRegistry($entry);

                // `false` is "we could not ask" — a timeout, a 5xx, an open breaker. Not an answer,
                // so **not** stamped: caching it would record "not on the register" for an account
                // that is, and nothing would ever ask again. The next run tries.
                //
                // Three outcomes where a boolean has two, which is the distinction
                // `Core\Contracts\WebContentFetcher::fetchLines()` documents for the same reason:
                // a caller enforcing a rule has to tell "there is no such thing" from "we could not
                // find out".
                if ($number === false) {
                    continue;
                }
            }

            // Stamped either way now. A null number with a timestamp is the negative cache —
            // "asked, nothing matched exactly" — and is what stops this costing a search every run.
            $this->remember($entry, $number);

            if ($number !== null) {
                $resolved++;
            }
        }

        Log::channel('signals')->info('Resolved Companies House numbers for watchlist entries.', [
            'team_id' => $teamId,
            'pending' => count($pending),
            'searched' => $searched,
            'rechecked_free' => $fromLeads,
            'resolved' => $resolved,
        ]);

        return $resolved + $fromLeads;
    }

    /**
     * The number the team's own data already knows, with no request at all.
     *
     * Scoped by `team_id` beside the relation for the usual reason: this reads a `leads` row from a
     * watchlist entry, and the two tables are joined by an FK that is not team-qualified at the
     * database level (`docs/modules/signals.md`, "Cross-team parent references").
     */
    private function fromLead(int $teamId, SignalWatchlistEntry $entry): ?string
    {
        if ($entry->lead_id === null) {
            return null;
        }

        $number = Lead::query()
            ->where('team_id', $teamId)
            ->whereKey($entry->lead_id)
            ->value('company_number');

        $number = is_string($number) ? trim($number) : '';

        return $number === '' ? null : $number;
    }

    /**
     * Ask the registry, and accept only an exact name match.
     *
     * A lookup failure is swallowed rather than raised: resolving a number is a convenience the run
     * can proceed without, and a registry that is briefly unwell must not fail a watch whose news
     * sources are fine.
     *
     * **Three outcomes, not two**, which is what the caller needs:
     *
     * - a **string** — the number of the company whose registered name matches exactly;
     * - **null** — we asked and nothing matched. A real answer, and the one worth remembering;
     * - **false** — we could not ask (a timeout, a 5xx, an open breaker). Not an answer, so the
     *   entry is left unstamped and a later run asks again. Caching this would record "not on the
     *   register" for an account that is, permanently, on the strength of one bad minute.
     */
    private function fromRegistry(SignalWatchlistEntry $entry): string|null|false
    {
        try {
            return $this->lookup->exactMatch($entry->searchNames());
        } catch (Throwable $e) {
            Log::channel('signals')->warning('Companies House name lookup failed; leaving the entry unresolved.', [
                'team_id' => (int) $entry->team_id,
                'entry_id' => (int) $entry->getKey(),
                'error' => $e::class,
            ]);

            return false;
        }
    }

    /**
     * Write the outcome onto the entry.
     *
     * Its own tiny transaction, and `forceFill` rather than an update query, so the model the run is
     * holding reflects what was stored — the builder reads `company_number` off these same objects a
     * moment later.
     */
    private function remember(SignalWatchlistEntry $entry, ?string $number): void
    {
        DB::transaction(static function () use ($entry, $number): void {
            $entry->forceFill([
                'company_number' => $number,
                'company_number_checked_at' => now(),
            ])->save();
        });
    }
}
