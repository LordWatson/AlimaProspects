<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Reporting\Support\Ratio;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * How each paid enrichment vendor is performing, across every tenant (PL3).
 *
 * **Its own builder in Admin rather than a call into Enrichment**, which is ADR 0016's rule: a
 * platform question is not a tenant question with the scope removed. What a vendor's hit rate is
 * across the whole estate is a question no tenant can ask, and answering it by borrowing a
 * tenant-scoped service and stripping its scope is how the two sets of figures come to disagree.
 *
 * **Straight off `enrichment_attempts` rather than through `platform_daily_metrics`.** The rollup
 * exists so that a cross-tenant scan over the AI ledger does not happen on a page load, and that
 * reasoning holds for `ai_requests` — millions of rows, one per prompt. An attempt row is written
 * once per vendor call, there are no vendors enabled yet, and the figures here are per **provider**
 * rather than per day, which the rollup's shape cannot express. When the volume justifies a
 * nightly fold, that is an ADR 0016 conversation and a new metric; until then a direct aggregate is
 * the honest answer rather than a derived one.
 *
 * The numbers it reports are chosen to answer three questions an operator actually has:
 *
 * - **Is this vendor earning its money?** A hit rate over *called* attempts — skips excluded,
 *   because we never asked, and including them would credit a vendor for an outage.
 * - **What does a hit really cost?** Total spend divided by hits, not by calls. A finder with a
 *   30% hit rate at 10p a call costs 33p per address, and the per-call figure hides that.
 * - **Is it slow enough to matter?** Mean latency over the calls that returned.
 */
final class EnrichmentProviderStats
{
    /**
     * Per-provider figures over the last `$days` days, keyed by the provider's own key.
     *
     * `withoutGlobalScopes` is not reached for here because this goes through the query builder
     * rather than Eloquent: there is no tenancy scope on a `DB::table()` read, which is the point
     * — and a reminder that this is a platform surface and not a tenant one.
     *
     * @return array<string, array<string, mixed>>
     */
    public function since(int $days): array
    {
        $since = Carbon::now()->subDays(max(1, $days));

        $rows = DB::table('enrichment_attempts')
            ->where('created_at', '>=', $since)
            ->groupBy('provider')
            ->select('provider')
            ->selectRaw('count(*) as attempts')
            ->selectRaw('sum(cost_micros) as cost_micros')
            // `case when` rather than a conditional aggregate: SQLite reads this in tests and
            // MySQL in production, and `sum(outcome = ?)` is MySQL's alone. The expression is a
            // **literal** with the value bound, rather than concatenated — a raw clause that
            // concatenates anything is a raw clause somebody later concatenates a column into.
            ->selectRaw('sum(case when outcome = ? then 1 else 0 end) as hits', [AttemptOutcome::Hit->value])
            ->selectRaw('sum(case when outcome = ? then 1 else 0 end) as misses', [AttemptOutcome::Miss->value])
            ->selectRaw('sum(case when outcome = ? then 1 else 0 end) as errors', [AttemptOutcome::Error->value])
            ->selectRaw('avg(latency_ms) as mean_latency')
            ->get();

        $stats = [];

        foreach ($rows as $row) {
            $hits = (int) $row->hits;
            $called = $hits + (int) $row->misses + (int) $row->errors;
            $cost = (int) $row->cost_micros;

            $stats[(string) $row->provider] = [
                'attempts' => (int) $row->attempts,
                'hits' => $hits,
                'misses' => (int) $row->misses,
                'errors' => (int) $row->errors,
                // Skips excluded: we never asked, so they are evidence about us rather than about
                // the vendor.
                'called' => $called,
                'hit_rate' => Ratio::of($hits, $hits + (int) $row->misses),
                'error_rate' => Ratio::of((int) $row->errors, $called),
                'cost_micros' => $cost,
                // **Per hit, not per call.** A finder that misses two times in three costs three
                // times its sticker price per address, and the per-call figure hides exactly that.
                // Null rather than zero when nothing was found: a cost per hit of zero reads as
                // free, and the truth is that there is no figure yet.
                'cost_per_hit_micros' => $hits > 0 ? (int) round($cost / $hits) : null,
                'mean_latency_ms' => $row->mean_latency === null ? null : (int) round((float) $row->mean_latency),
            ];
        }

        return $stats;
    }
}
