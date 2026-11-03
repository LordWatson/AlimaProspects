<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\PlatformProvider;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Support\SignalSourceBreakdown;
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
    public function __construct(private readonly ProviderHealth $health) {}

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
                // Where this run's money went, per source (S13, task 8). Read back from the stored
                // breakdown rather than recomputed, so the figures are the ones the run was charged
                // at rather than ones re-derived from today's prices — and empty for every run from
                // before S13, which the screen renders as a dash rather than as four zeros.
                'sources' => SignalSourceBreakdown::fromRun($run),
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
            'sources' => $this->sourceTotals($range),
            'statuses' => array_map(
                static fn (SignalRunStatus $status): array => [
                    'value' => $status->value,
                    'label' => $status->label(),
                ],
                SignalRunStatus::cases(),
            ),
        ];
    }

    /**
     * Cost and volume by **source** over the window, with each source's breaker beside it (S13,
     * task 8).
     *
     * **Why this lives here and not only on the provider-health screen.** The health screen answers
     * "is Serper up"; this answers "where did this week's signal spend go, and is one of these
     * sources quietly shut". An operator looking at a column of failing runs should not have to
     * remember that a second screen exists to tell them the breaker is open — that is the moment
     * the fact is worth knowing. `/admin/providers` remains the canonical health view and the only
     * place a breaker can be reset.
     *
     * **Aggregated in PHP over a chunked read of one column**, not in SQL. `source_breakdown` is
     * json, and MySQL and SQLite disagree enough about json aggregation that a query written for one
     * is wrong on the other — which is exactly the trap `sqlite-tests-mysql-prod` describes, where a
     * green suite says nothing about production. One column, chunked, is slower and is the same
     * answer on both.
     *
     * @return list<array{source: string, label: string, queries: int, results: int, rejected: int, cost_micros: int, breaker_open: bool|null, breaker_failures: int|null}>
     */
    private function sourceTotals(DateRange $range): array
    {
        $query = SignalRun::query()
            ->withoutGlobalScopes()
            ->select(['id', 'source_breakdown'])
            ->whereNotNull('source_breakdown');

        $range->constrain($query, 'signal_runs.created_at');

        /** @var array<string, array{queries: int, results: int, rejected: int, cost_micros: int}> $totals */
        $totals = [];

        $query->chunkById(500, static function ($runs) use (&$totals): void {
            foreach ($runs as $run) {
                foreach (SignalSourceBreakdown::fromRun($run) as $row) {
                    $key = $row['source'];

                    $totals[$key] ??= ['queries' => 0, 'results' => 0, 'rejected' => 0, 'cost_micros' => 0];
                    $totals[$key]['queries'] += $row['queries'];
                    $totals[$key]['results'] += $row['results'];
                    $totals[$key]['rejected'] += $row['rejected'];
                    $totals[$key]['cost_micros'] += $row['cost_micros'];
                }
            }
        });

        $out = [];

        foreach ($totals as $key => $row) {
            $kind = SignalSourceKind::parse($key);
            $provider = $kind === null ? null : $this->providerFor($kind);

            $out[] = [
                'source' => $key,
                // A withdrawn or unknown key labels as itself: an operator reading a historical
                // window needs to see that something spent money there.
                'label' => $kind?->label() ?? $key,
                ...$row,
                // Null, not false, where this build has no breaker mapped for the source — the same
                // rule `PlatformProvider::breakerPrefix()` follows, and for the same reason: a green
                // light nobody wired up is worse than no light.
                'breaker_open' => $provider === null
                    ? null
                    : $this->health->breaker($provider)->isOpen((string) $provider->breakerKey()),
                'breaker_failures' => $provider === null
                    ? null
                    : $this->health->breaker($provider)->failures((string) $provider->breakerKey()),
            ];
        }

        return $out;
    }

    /**
     * The health screen's provider row for a signal source, or null when it has none.
     *
     * The mapping is here rather than on `SignalSourceKind` because it points from Signals' own
     * vocabulary into Admin's, and Signals must not learn about the admin panel to run a watch —
     * the dependency only ever points this way (ADR 0014; `docs/modules/admin.md`, "it owns no
     * business rule").
     */
    private function providerFor(SignalSourceKind $kind): ?PlatformProvider
    {
        return match ($kind) {
            SignalSourceKind::News => PlatformProvider::SerperNews,
            SignalSourceKind::WebSearch => PlatformProvider::SignalsWebSearch,
            // **Lead Sourcing's row, deliberately** (S13b). The signals source shares that
            // credential and that breaker, because the registry rate-limits per API key and one
            // quota deserves one circuit — so the honest light to show here is the one that is
            // actually wired, rather than a second row claiming to watch the same thing.
            SignalSourceKind::CompaniesHouse => PlatformProvider::CompaniesHouse,
            // Still declared and not offered.
            SignalSourceKind::Feed, SignalSourceKind::Webhook => null,
        };
    }
}
