<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Console;

use App\Modules\Compliance\Actions\PurgeExpiredDataAction;
use App\Modules\Compliance\Enums\RetentionAction;
use App\Modules\Compliance\Jobs\PurgeExpiredDataJob;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Compliance\Models\TpsCheck;
use App\Modules\Compliance\Services\DataRetention;
use App\Modules\Core\Models\Team;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The platform's retention sweep (docs/compliance.md §3.4), scheduled nightly.
 *
 * It does not do the work itself: it walks every team and **queues one
 * {@see PurgeExpiredDataJob} each**, so one team's backlog cannot hold up everybody else's
 * promise. The exceptions are `--dry-run` and `--sync`, which run inline because a human is
 * waiting for the answer — and a dry run that queued jobs would print nothing useful.
 *
 * `--dry-run` reports and changes nothing. Retention is irreversible, so it must be possible to see
 * what a window would cost before it costs it; the compliance screen shows the same counts.
 *
 * Logs **counts, windows and team ids only** — never a company name (CLAUDE.md logging rules).
 */
final class PurgeExpiredDataCommand extends Command
{
    protected $signature = 'compliance:purge
        {--dry-run : Report what would be removed without removing it}
        {--sync : Sweep inline instead of queueing a job per team}
        {--team= : Sweep one team by id}';

    protected $description = 'Delete and anonymise prospect data past each team\'s retention window';

    public function __construct(
        private readonly PurgeExpiredDataAction $purge,
        private readonly DataRetention $retention,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $inline = $dryRun || (bool) $this->option('sync');

        $teams = $this->teams();

        // The two platform tables, swept here rather than in the per-team Action because neither
        // belongs to a team (PL5). Done before the per-team work so it happens even on a run that
        // only queues jobs — and skipped on a dry run, like everything else.
        $platform = $dryRun ? ['requests' => 0, 'tps_checks' => 0] : $this->sweepPlatformTables();

        if ($teams === []) {
            $this->info('No teams to sweep.');

            return self::SUCCESS;
        }

        if (! $inline) {
            foreach ($teams as $team) {
                PurgeExpiredDataJob::dispatch((int) $team->getKey());
            }

            $this->info(sprintf('Queued a retention sweep for %d team(s).', count($teams)));

            return self::SUCCESS;
        }

        $deleted = 0;
        $anonymised = 0;
        $skipped = 0;

        foreach ($teams as $team) {
            $counts = $this->purge->handle($team, $dryRun);

            $deleted += $counts[RetentionAction::Deleted->value];
            $anonymised += $counts[RetentionAction::Anonymised->value];
            $skipped += $counts['skipped'];
        }

        Log::channel('compliance')->info('Retention sweep run from the console.', [
            'teams' => count($teams),
            'deleted' => $deleted,
            'anonymised' => $anonymised,
            'skipped_teams' => $skipped,
            'dry_run' => $dryRun,
            'subject_requests_pruned' => $platform['requests'],
            'tps_checks_pruned' => $platform['tps_checks'],
            'default_uncontacted_days' => $this->retention->defaultUncontactedDays(),
            'default_contacted_days' => $this->retention->defaultContactedDays(),
        ]);

        $this->info(sprintf(
            '%s %d lead(s) and %s %d lead(s) across %d team(s); %d team(s) have retention switched off.',
            $dryRun ? 'Would delete' : 'Deleted',
            $deleted,
            $dryRun ? 'would anonymise' : 'anonymised',
            $anonymised,
            count($teams),
            $skipped,
        ));

        return self::SUCCESS;
    }

    /**
     * The platform tables PL5 added, neither of which has a `team_id`.
     *
     * - **`data_subject_requests`** holds an email address in the clear, which is justified only
     *   for as long as the accountability record is needed. Closed requests past
     *   `subject_requests.retain_days` go; an **open** one is never swept however old it is,
     *   because an unanswered statutory request is live work rather than stale data. An
     *   **unverified** one is swept once its link has expired — it is neither a record nor live
     *   work, just an address somebody typed into a public form, and leaving those for ever is
     *   exactly what the clear-text column cannot be justified by.
     * - **`tps_checks`** rolls from `checked_at` — a verdict nobody has asked about in a year is
     *   dropped, and the next screening re-fetches it. A stale verdict is still what the register
     *   last said, which is why the window is far longer than the 28-day re-check.
     *
     * The eligibility query is re-applied at the `DELETE` rather than collecting ids and deleting
     * by id, which is the bug `signals:prune` has already had.
     *
     * @return array{requests: int, tps_checks: int}
     */
    private function sweepPlatformTables(): array
    {
        $requestDays = max(1, (int) config('compliance.subject_requests.retain_days', 730));
        $checkDays = max(1, (int) config('compliance.tps.retain_days', 365));

        // Twice the link's own lifetime, so a request is never swept while the link in somebody's
        // inbox could still work.
        $linkDays = max(1, (int) config('compliance.subject_requests.link_days', 14)) * 2;

        $requests = (int) DataSubjectRequest::query()
            ->where(function ($query) use ($requestDays): void {
                $query->whereNotNull('completed_at')
                    ->where('completed_at', '<', now()->subDays($requestDays));
            })
            ->orWhere(function ($query) use ($linkDays): void {
                $query->whereNull('verified_at')
                    ->where('created_at', '<', now()->subDays($linkDays));
            })
            ->delete();

        $checks = (int) TpsCheck::query()
            ->where('checked_at', '<', now()->subDays($checkDays))
            ->delete();

        return ['requests' => $requests, 'tps_checks' => $checks];
    }

    /**
     * The teams to sweep — all of them, or the one named by `--team`.
     *
     * Read **without a tenant context**: this is a system job across every team, which is why it
     * is reachable only from the console (the same reasoning as `signals:prune` and
     * `insights:prune`).
     *
     * @return list<Team>
     */
    private function teams(): array
    {
        $query = Team::query()->orderBy('id');

        $teamId = $this->option('team');

        if (is_string($teamId) && $teamId !== '') {
            $query->whereKey((int) $teamId);
        }

        return array_values($query->get()->all());
    }
}
