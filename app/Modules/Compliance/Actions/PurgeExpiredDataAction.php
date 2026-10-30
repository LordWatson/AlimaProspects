<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Actions;

use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Jobs\PurgeExpiredDataJob;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Compliance\Services\DataRetention;
use App\Modules\Compliance\Services\LeadAnonymiser;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * One team's retention sweep (docs/compliance.md §3.4): delete the leads nobody ever touched,
 * anonymise the ones that were worked and have gone quiet.
 *
 * Called by {@see PurgeExpiredDataJob} per team, and by `compliance:purge` for every team.
 *
 * Four things about it are load-bearing:
 *
 * - **`--dry-run` reports and changes nothing.** Retention is irreversible, and a team is entitled
 *   to see what a window change would cost before it costs it.
 * - **Chunked.** A team with a hundred thousand stale leads must not hold one transaction open for
 *   the whole sweep; each lead is its own transaction, so an interrupted run has done part of the
 *   work rather than none of it, and the next run finishes it.
 * - **Audited, with an actor of nobody.** The sweep is the platform keeping a promise, not a
 *   member's action. The audit entry carries counts, the windows that were in force and the team —
 *   never a company name or an address, because an audit log of what we deleted for privacy
 *   reasons must not be a copy of it (§3.8, §3.9).
 * - **It re-reads the windows per team, every run.** The windows are a team setting that changes;
 *   caching them across a sweep would apply yesterday's policy to tonight's data.
 */
final class PurgeExpiredDataAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly DataRetention $retention,
        private readonly LeadAnonymiser $anonymiser,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sweep one team.
     *
     * @return array<string, int> counts keyed by {@see RetentionAction} value, plus `skipped`
     */
    public function handle(Team $team, bool $dryRun = false): array
    {
        $windows = $this->retention->windowsFor($team);

        if (! $windows['enabled']) {
            // A team that has switched retention off — a litigation hold, a migration. Reported
            // rather than silently returning zeros, so a run over every team can say how many teams
            // it left alone.
            return [
                RetentionAction::Deleted->value => 0,
                RetentionAction::Anonymised->value => 0,
                'skipped' => 1,
            ];
        }

        $deleted = $this->sweepDeletable($team, $dryRun);
        $anonymised = $this->sweepAnonymisable($team, $dryRun);

        if (! $dryRun) {
            $this->recordRun($team, $deleted, $anonymised, $windows);
        }

        // Counts, windows and ids only (CLAUDE.md logging rules).
        Log::channel('compliance')->info($dryRun ? 'Retention sweep (dry run).' : 'Retention sweep finished.', [
            'team_id' => (int) $team->getKey(),
            'deleted' => $deleted,
            'anonymised' => $anonymised,
            'uncontacted_days' => $windows['uncontacted_days'],
            'contacted_days' => $windows['contacted_days'],
        ]);

        return [
            RetentionAction::Deleted->value => $deleted,
            RetentionAction::Anonymised->value => $anonymised,
            'skipped' => 0,
        ];
    }

    /**
     * Leads nobody ever touched. Deleted in chunks of ids, which cascades contacts, scores,
     * timeline, messages and attempts.
     *
     * The **signals** attached to them are deleted first and deliberately. The foreign key is
     * `nullOnDelete`, so without this a converted signal would survive its lead with `lead_id`
     * null and a named individual still on it — and `signals:prune` never sweeps a converted
     * signal, so nothing else would ever remove it (§5a). A row that outlives the thing it was
     * provenance for is exactly the retention leak a policy exists to close.
     */
    private function sweepDeletable(Team $team, bool $dryRun): int
    {
        if ($dryRun) {
            // Count and stop. The loop below is driven by rows leaving the candidate set, so a dry
            // run that entered it would count the same page for ever.
            return $this->retention->deletable($team)->count();
        }

        $chunk = $this->retention->chunkSize();
        $total = 0;

        while (true) {
            /** @var list<int> $ids */
            $ids = $this->retention->deletable($team)
                ->orderBy('id')
                ->limit($chunk)
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            if ($ids === []) {
                break;
            }

            DB::transaction(function () use ($team, $ids): void {
                Signal::query()->withoutGlobalScopes()
                    ->where('team_id', $team->getKey())
                    ->whereIn('lead_id', $ids)
                    ->delete();

                Lead::query()->withoutGlobalScopes()
                    ->where('team_id', $team->getKey())
                    ->whereIn('id', $ids)
                    ->delete();
            });

            $total += count($ids);
        }

        return $total;
    }

    /**
     * Leads that were worked and have gone quiet. One transaction each, so a long sweep is
     * interruptible and a failure on one lead doesn't roll back the rest.
     */
    private function sweepAnonymisable(Team $team, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->retention->anonymisable($team)->count();
        }

        $chunk = $this->retention->chunkSize();
        $total = 0;

        while (true) {
            $leads = $this->retention->anonymisable($team)
                ->orderBy('id')
                ->limit($chunk)
                ->get();

            if ($leads->isEmpty()) {
                break;
            }

            $processed = 0;

            foreach ($leads as $lead) {
                if ($this->anonymiser->handle($lead)) {
                    $processed++;
                }
            }

            $total += $processed;

            // A pass that changed nothing cannot be followed by one that does — the candidate set
            // is defined by `anonymised_at` being null, so the same page would come back for ever.
            // It should be unreachable; it is here because an infinite loop in a nightly job is
            // worse than a count that stops one lead early.
            if ($processed === 0) {
                break;
            }
        }

        return $total;
    }

    /**
     * Stamp the team's policy row with what this run did, and write the audit entry.
     *
     * The row is **created if the team never saved one**, which is the point: a team on the
     * defaults still has its sweep recorded, and the settings screen can say when it last ran
     * instead of implying nothing has happened.
     *
     * @param  array{enabled: bool, uncontacted_days: int, contacted_days: int}  $windows
     */
    private function recordRun(Team $team, int $deleted, int $anonymised, array $windows): void
    {
        $this->teamContext->run($team, function () use ($team, $deleted, $anonymised, $windows): void {
            DB::transaction(function () use ($team, $deleted, $anonymised, $windows): void {
                $policy = RetentionPolicy::query()->withoutGlobalScopes()->firstOrNew(
                    ['team_id' => $team->getKey()],
                    [
                        'is_enabled' => true,
                        'uncontacted_lead_days' => $windows['uncontacted_days'],
                        'contacted_lead_days' => $windows['contacted_days'],
                    ],
                );

                $policy->forceFill([
                    'team_id' => $team->getKey(),
                    'last_swept_at' => now(),
                    'last_deleted_count' => $deleted,
                    'last_anonymised_count' => $anonymised,
                ])->save();

                // Actor is deliberately null: nobody did this, the policy did.
                $this->audit->log('compliance.retention_swept', $policy, [
                    'deleted' => $deleted,
                    'anonymised' => $anonymised,
                    'uncontacted_days' => $windows['uncontacted_days'],
                    'contacted_days' => $windows['contacted_days'],
                ]);
            });
        });
    }
}
