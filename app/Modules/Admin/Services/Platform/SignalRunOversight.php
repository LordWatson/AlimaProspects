<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Models\SignalRun;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Cross-tenant `signal_runs` — **cost, failures and durations per run** (Phase 19c).
 *
 * This closes the observability task Signals left open at S6 (`signals-plan.md` task 6,
 * `signals.md`): the plan said a read-only operator view of `signal_runs` if an admin panel existed
 * by then, and otherwise to note it for Phase 19. It did not, so it was noted. This is it.
 *
 * **Read-only, and there is no write path to find.** No Action, no update, no retry: a signal run
 * is re-run by the tenant from their own watch, and `TriggerSignalWatchAction` throttles that for a
 * reason — each run spends real money at Serper and the AI. An operator "helpfully" re-running
 * somebody's watch would spend their allowance for them. `PlatformOversightTest` asserts no write
 * route exists for this view.
 *
 * Durations come from `started_at`/`finished_at` on the row rather than from `created_at`, because
 * a queued run can sit for minutes before it begins and the number an operator wants is how long
 * the *work* took.
 */
final class SignalRunOversight
{
    /**
     * @param  array<string, mixed>  $filters  status, team_id
     * @return LengthAwarePaginator<int, SignalRun>
     */
    public function paginate(array $filters, DateRange $range, int $perPage = 50): LengthAwarePaginator
    {
        $query = SignalRun::query()
            ->withoutGlobalScopes()
            // Declared because the rows render both: a lazy relation on a page of fifty rows is an
            // N+1 that `preventLazyLoading` turns into a failure rather than a slow page.
            ->with(['team:id,name', 'watch:id,name']);

        $range->constrain($query, 'signal_runs.created_at');

        $status = $filters['status'] ?? null;

        if (is_string($status) && SignalRunStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        if (is_numeric($filters['team_id'] ?? null)) {
            $query->where('team_id', (int) $filters['team_id']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, SignalRun>  $page
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $page): array
    {
        return array_values(array_map(static function (SignalRun $run): array {
            $started = $run->started_at;
            $finished = $run->finished_at;

            return [
                'id' => (int) $run->getKey(),
                'team' => $run->team?->name,
                'team_id' => $run->team_id,
                'watch' => $run->watch?->name,
                'status' => $run->status->value,
                'queries' => $run->queries_count,
                'results' => $run->results_count,
                'created' => $run->signals_created,
                'cost_micros' => $run->cost_micros,
                // Null when the run never started or never finished — which is itself the finding
                // on a stuck run, and a zero would hide it.
                'duration_ms' => $started === null || $finished === null
                    ? null
                    : max(0, (int) round(($finished->getTimestamp() - $started->getTimestamp()) * 1000)),
                'error' => $run->error,
                'at' => $run->created_at?->toIso8601String(),
            ];
        }, $page->items()));
    }

    /**
     * Totals over the window, for the cards above the table.
     *
     * Computed live over `signal_runs` rather than from the rollup, and that is a deliberate
     * exception: this table is small by construction — one row per watch per scheduled tick — and
     * an operator diagnosing a failing watch needs **today**, which the nightly rollup has not
     * written yet.
     *
     * @return array<string, mixed>
     */
    public function summary(DateRange $range): array
    {
        $query = SignalRun::query()->withoutGlobalScopes();
        $range->constrain($query, 'signal_runs.created_at');

        $total = (clone $query)->count();
        $failed = (clone $query)->where('status', SignalRunStatus::Failed->value)->count();

        return [
            'runs' => $total,
            'failed' => $failed,
            'failure_rate' => Ratio::of($failed, $total),
            'cost_micros' => (int) (clone $query)->sum('cost_micros'),
            'signals_created' => (int) (clone $query)->sum('signals_created'),
            'statuses' => array_map(
                static fn (SignalRunStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                SignalRunStatus::cases(),
            ),
        ];
    }
}
