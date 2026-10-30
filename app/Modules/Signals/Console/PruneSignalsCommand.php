<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Services\SignalRetention;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * The Signals retention sweep (S6, ADR 0011 / `docs/compliance.md` §5a): hard-delete signals the
 * team has had long enough to act on and hasn't.
 *
 * The windows come from {@see SignalRetention}, which is also what tells the feed "deletes in N
 * days" — so the promise on screen and the rule the sweep applies are the same rule. Dismissed
 * signals go after 90 days (the team said no, and the reason is already in the audit log, which is
 * what tuning reads); unactioned ones after 180 (a six-month-old funding round is not a trigger
 * event any more, and keeping a named person's details because nobody clicked is exactly the
 * retention a data-minimisation policy exists to prevent).
 *
 * **Converted signals are never swept.** `SignalRetention::sweptStatuses()` doesn't return that
 * status and `eligible()` refuses to build a query for it, so there is no way to write a caller
 * here that deletes one.
 *
 * The sweep is **idempotent** (a second run finds nothing to do), **chunked** (a year of a busy
 * team's signals is not one `DELETE ... WHERE`), runs **without the tenant scope on purpose** — it
 * is a system job across every team, reachable only from the console — and logs **counts only**,
 * never a headline or a name.
 */
final class PruneSignalsCommand extends Command
{
    /**
     * `--dry-run` reports what it would delete and deletes nothing. Retention is irreversible, so
     * it should be possible to see the damage before doing it (the plan's own risk note).
     */
    protected $signature = 'signals:prune {--dry-run : Report what would be deleted without deleting it}';

    protected $description = 'Delete dismissed and unactioned signals past their retention window';

    /** Rows per delete, so one sweep can't hold a long lock or exhaust memory. */
    private const int CHUNK = 500;

    public function __construct(private readonly SignalRetention $retention)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = [];

        foreach ($this->retention->sweptStatuses() as $status) {
            $counts[$status->value] = $this->prune($status, $dryRun);
        }

        $context = [
            'dismissed_deleted' => $counts[SignalStatus::Dismissed->value] ?? 0,
            'unactioned_deleted' => $counts[SignalStatus::New->value] ?? 0,
            'dismissed_days' => $this->retention->windowFor(SignalStatus::Dismissed),
            'unactioned_days' => $this->retention->windowFor(SignalStatus::New),
            'dry_run' => $dryRun,
        ];

        // Counts and windows only — never a headline, a name or a team's content.
        Log::channel('signals')->info('Signals retention sweep finished.', $context);

        $this->info(sprintf(
            '%s %d dismissed and %d unactioned signal(s).',
            $dryRun ? 'Would delete' : 'Deleted',
            $context['dismissed_deleted'],
            $context['unactioned_deleted'],
        ));

        return self::SUCCESS;
    }

    /**
     * Delete every signal in `$status` past its window, in chunks.
     *
     * The ids are read and then deleted **through the same constraint**, not by id alone. Between
     * the read and the delete a member can convert or restore a signal, and a plain
     * `whereIn('id', …)` would delete it anyway — taking the provenance of a brand-new lead with
     * it. Re-applying the status and age guards at the `DELETE` closes that window, and means the
     * "converted is never deleted" guarantee is enforced where the rows actually go.
     */
    private function prune(SignalStatus $status, bool $dryRun): int
    {
        $eligible = $this->retention->eligible($status);

        if ($eligible === null) {
            return 0; // Never swept — nothing to do, and nothing a caller can force.
        }

        if ($dryRun) {
            // One count, not a loop: nothing is being removed, so there is no shrinking set to
            // chunk through — and a chunked dry run would either under-report or never finish.
            return (clone $eligible)->count();
        }

        $deleted = 0;

        while (true) {
            $ids = (clone $eligible)->orderBy('id')->limit(self::CHUNK)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $removed = (clone $eligible)->whereIn('id', $ids)->delete();

            if ($removed === 0) {
                // Every row in this chunk was acted on by a member in the meantime. Stop rather
                // than spin: the next nightly sweep will pick up whatever is still eligible.
                break;
            }

            $deleted += $removed;
        }

        return $deleted;
    }
}
