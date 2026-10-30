<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use Illuminate\Support\Collection;

/**
 * Where the team's AI and data spend went (Phase 13), one row per kind of work: scoring, drafting,
 * background checks.
 *
 * Grouped by `prompt_key` rather than by model or provider, because the question an owner asks is
 * "what is costing me money", and the answer has to be a thing they recognise — a prompt key names
 * the job (`lead_scoring.assess`), where a model name names our plumbing. Versions are summed
 * together: a prompt's v2 is the same job as its v1.
 *
 * Scoped by when the call was made, not by a lead cohort: spend is activity, and the point of the
 * report is the bill for the window.
 *
 * The **cache-hit** column is the one worth watching. A cached response cost nothing, so a high
 * hit rate beside a flat cost is the platform working; a falling hit rate is the first sign a bill
 * is about to grow. Zero-cost cache hits are counted in `calls` but contribute nothing to spend,
 * which is why the two columns do not move together.
 *
 * Currency: `cost_micros` is summed across providers and labelled with the team's market currency.
 * If a provider ever bills in another one the total would mix units — Billing (Phase 16) is where
 * that gets settled, and the module doc records it as a known limitation rather than hiding it.
 */
final readonly class BuildAiUsageAction
{
    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, DateRange $range): ReportTable
    {
        $rows = $this->teamContext->run($team, function () use ($team, $range): Collection {
            /**
             * @var Collection<int, object{
             *     prompt_key: string,
             *     calls: int|string,
             *     failures: int|string,
             *     cache_hits: int|string,
             *     input_tokens: int|string,
             *     output_tokens: int|string,
             *     cost_micros_total: int|string,
             * }> $aggregates
             */
            $aggregates = $range->constrain(
                AiRequest::query()->where('ai_requests.team_id', $team->getKey()),
                'ai_requests.created_at',
            )
                ->groupBy('prompt_key')
                ->orderByDesc('cost_micros_total')
                ->selectRaw(
                    'prompt_key, count(*) as calls, '
                    .'sum(case when status = ? then 1 else 0 end) as failures, '
                    .'sum(case when cache_hit = 1 then 1 else 0 end) as cache_hits, '
                    .'sum(input_tokens) as input_tokens, sum(output_tokens) as output_tokens, '
                    .'sum(cost_micros) as cost_micros_total',
                    [AiRequestStatus::Failed->value],
                )
                ->toBase()
                ->get();

            return $aggregates;
        });

        $currency = BuildHeadlineKpisAction::currency($team);

        $reportRows = [];

        foreach ($rows as $row) {
            $calls = (int) $row->calls;

            $reportRows[] = new ReportRow(
                key: $row->prompt_key,
                cells: [
                    // The prompt key itself, printed as the team's platform logs it: data, not a
                    // translation key (see ReportColumn).
                    'prompt' => $row->prompt_key,
                    'calls' => $calls,
                    'failures' => (int) $row->failures,
                    'cache_rate' => Ratio::of((int) $row->cache_hits, $calls),
                    'input_tokens' => (int) $row->input_tokens,
                    'output_tokens' => (int) $row->output_tokens,
                    // Micros to minor units: 1,000,000 micros to the major unit, 100 minor units
                    // to it, so 10,000 micros per minor unit (CLAUDE.md §2 — money is integer).
                    // Sub-penny calls therefore read as 0, which is the truth about a single call
                    // and not about the column's total.
                    'cost' => intdiv((int) $row->cost_micros_total, 10_000),
                ],
            );
        }

        return ReportTable::of(
            ReportKey::AiUsage,
            $this->columns($currency),
            $reportRows,
            'Alima has not spent anything on your behalf in this period.',
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(string $currency): array
    {
        return [
            new ReportColumn('prompt', 'Work'),
            ReportColumn::count('calls', 'Calls'),
            ReportColumn::count('failures', 'Failed'),
            ReportColumn::percent('cache_rate', 'Served from cache'),
            ReportColumn::count('input_tokens', 'Input tokens'),
            ReportColumn::count('output_tokens', 'Output tokens'),
            ReportColumn::money('cost', 'Cost', $currency),
        ];
    }
}
