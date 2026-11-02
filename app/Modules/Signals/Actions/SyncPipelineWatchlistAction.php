<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Support\CompanyNameKey;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Mirrors a team's own pipeline onto a watchlist (S10).
 *
 * **Idempotent by construction.** It compares the leads that should be on the list against the
 * entries that are, adds what is missing, removes what no longer qualifies and leaves the rest
 * untouched — so running it twice changes nothing, and a nightly schedule is safe. It does **not**
 * delete and rebuild: an entry carries a `lead_id` the matcher relies on, and rebuilding would
 * churn every row every night for nothing.
 *
 * **An entry whose lead has gone is removed too.** `lead_id` is `nullOnDelete` and a lead is not
 * soft-deleted, so a deleted lead leaves a row with a company name and nothing behind it — and a
 * member cannot remove it by hand, because a synced list refuses hand edits. So the sync owns that
 * cleanup; otherwise the name of a company the team deleted would go on being searched and paid for.
 *
 * **It honours erasure, which is the part that would otherwise be found by Compliance's tests.**
 * An anonymised lead is dropped on the next sync: its company name has been removed and a
 * watchlist entry still naming it would put the thing Compliance deleted back into a search query.
 * The entry also goes immediately, in the same transaction as the rest of the erasure — that is
 * `LeadAnonymiser`'s job and S10 taught it to do so. This is the second line of defence, for an
 * anonymisation that happened by some other route.
 *
 * **Capped at `signals.watchlist.max_entries`.** A pipeline grows on its own, and every entry on a
 * list is a paid search; following a team to four figures would be a standing instruction nobody
 * gave. The cap keeps the **most recently worked** leads, because a watchlist is about what
 * somebody is doing now.
 */
final class SyncPipelineWatchlistAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * Bring one list in step with the pipeline, and return what changed.
     *
     * @return array{added: int, removed: int, kept: int}
     */
    public function handle(Team $team, SignalWatchlist $watchlist): array
    {
        if (! $watchlist->isSyncable()) {
            // A paused list is left exactly as it is rather than emptied: pausing means "stop
            // searching", not "forget what I told you".
            return ['added' => 0, 'removed' => 0, 'kept' => (int) $watchlist->entries_count];
        }

        return $this->teamContext->run($team, function () use ($team, $watchlist): array {
            $max = max(1, (int) config('signals.watchlist.max_entries', 250));

            /** @var array<int, Lead> $wanted keyed by lead id */
            $wanted = [];

            foreach ($this->leadsFor($watchlist, $max) as $lead) {
                $wanted[(int) $lead->getKey()] = $lead;
            }

            return DB::transaction(function () use ($team, $watchlist, $wanted): array {
                /** @var array<int, SignalWatchlistEntry> $existing keyed by lead id */
                $existing = [];
                $orphans = [];

                foreach ($watchlist->entries()->get() as $entry) {
                    if ($entry->lead_id === null) {
                        // A row on a synced list with no lead behind it. `lead_id` is
                        // `nullOnDelete` and `Lead` is not soft-deleted, so this is what a
                        // **deleted** lead leaves behind — and the member cannot clear it up
                        // themselves, because hand-editing a synced list is refused. Left in
                        // place it would keep its company name on the list, keep being paid for
                        // on every run and keep counting against the cap (review finding).
                        $orphans[] = $entry;

                        continue;
                    }

                    $existing[(int) $entry->lead_id] = $entry;
                }

                $added = 0;
                $removed = 0;

                foreach ($orphans as $orphan) {
                    $orphan->delete();
                    $removed++;
                }

                foreach ($wanted as $leadId => $lead) {
                    if (isset($existing[$leadId])) {
                        continue;
                    }

                    // `normalised_name` is set by the model's mutator from `name`, and the unique
                    // index is on it — so a lead whose name normalises to one already on the list
                    // (two records for the same company, which enrichment may not have merged yet)
                    // is skipped rather than failing the whole sync.
                    if ($this->nameTaken($watchlist, $lead->company_name)) {
                        continue;
                    }

                    SignalWatchlistEntry::query()->create([
                        'team_id' => $team->getKey(),
                        'signal_watchlist_id' => $watchlist->getKey(),
                        'entity_type' => WatchlistEntityType::Company,
                        'name' => $lead->company_name,
                        'domain' => $lead->domain(),
                        'lead_id' => $leadId,
                        'is_active' => true,
                    ]);

                    $added++;
                }

                foreach ($existing as $leadId => $entry) {
                    if (isset($wanted[$leadId])) {
                        continue;
                    }

                    // The lead left the stages being mirrored, changed hands, was deleted or was
                    // anonymised. Any of those means it is no longer something to watch.
                    $entry->delete();
                    $removed++;
                }

                $count = $watchlist->entries()->count();

                $watchlist->forceFill([
                    'entries_count' => $count,
                    'synced_at' => now(),
                ])->save();

                Log::channel('signals')->info('Synced a pipeline watchlist.', [
                    'team_id' => (int) $team->getKey(),
                    'signal_watchlist_id' => (int) $watchlist->getKey(),
                    'added' => $added,
                    'removed' => $removed,
                    'entries' => $count,
                    // Separately, because an entry outliving its lead is a different event from an
                    // account leaving the stages being watched.
                    'orphans_removed' => count($orphans),
                ]);

                return ['added' => $added, 'removed' => $removed, 'kept' => $count];
            });
        });
    }

    /**
     * The leads this list should mirror: in the chosen stages, owned by the chosen members, and
     * never anonymised.
     *
     * Ordered by **most recently updated**, because the cap keeps the front of this list and a
     * watchlist is about what somebody is working now rather than what they worked in March.
     *
     * @return list<Lead>
     */
    private function leadsFor(SignalWatchlist $watchlist, int $max): array
    {
        $stages = $watchlist->syncStages();
        $owners = $watchlist->syncOwners();

        $query = Lead::query()
            ->whereNull('anonymised_at')
            ->whereNotNull('company_name')
            ->where('company_name', '!=', '');

        if ($stages !== []) {
            $query->whereIn('pipeline_stage_id', $stages);
        }

        if ($owners !== []) {
            // Through the assignment table rather than a column, because a lead can have several
            // owners (Phase 10b) — a researcher and a closer on the same company is normal.
            $query->whereIn(
                'id',
                LeadAssignment::query()
                    ->whereIn('user_id', $owners)
                    ->select('lead_id'),
            );
        }

        /** @var list<Lead> $leads */
        $leads = $query
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit($max)
            ->get(['id', 'team_id', 'company_name', 'website', 'pipeline_stage_id', 'updated_at'])
            ->all();

        return $leads;
    }

    /**
     * Whether this list already holds something under the same comparable name.
     *
     * Through {@see CompanyNameKey} — the one name rule — so the check agrees with the unique index
     * it exists to protect.
     */
    private function nameTaken(SignalWatchlist $watchlist, string $name): bool
    {
        $key = CompanyNameKey::for($name);

        if ($key === '') {
            return true;
        }

        return $watchlist->entries()->where('normalised_name', $key)->exists();
    }
}
