<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Reporting\Data\ReportColumn;
use App\Modules\Reporting\Data\ReportRow;
use App\Modules\Reporting\Data\ReportTable;
use App\Modules\Reporting\Enums\ReportKey;
use App\Modules\Reporting\Support\DateRange;
use App\Modules\Reporting\Support\Ratio;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use Illuminate\Support\Collection;

/**
 * Which of a team's signal types are worth paying for (Signals S12, Phase 13's machinery).
 *
 * The question is **not** "which type finds the most", because a loose type does that by being
 * loose. It is *how far what it found actually got*: found → kept → converted → **moved off the
 * first column of the team's own board**. A type that produces forty cards a month and no pipeline
 * is telling the team something about the type; a type that produces three and two worked deals is
 * the one to clone.
 *
 * This is also what makes S15's "retire this type" suggestion credible rather than opinionated:
 * the proposal will be able to point at this table instead of at an opinion.
 *
 * **The cohort rule, written down** as every figure in this module is required to be: a row is
 * scoped by **when the signal was found** (`signals.created_at`), not by when the lead it became
 * moved. So "converted" means *of the signals this type found in the window, how many became
 * leads* — a signal found in March and converted in May counts in March, under the type that found
 * it. The alternative — scoping by the conversion date — would answer a different and much less
 * useful question ("what did we convert this month, whatever found it") and would make the funnel's
 * own columns incomparable with each other. The bounds reach the query through
 * {@see DateRange::constrain()}, so they arrive in **UTC** and a non-UTC team's figures do not
 * silently shift.
 *
 * **Counted per event, not per article** ({@see Signal::representatives()}): since S11 a signal is
 * one article and a cluster is one event, and a type whose stories get picked up widely would
 * otherwise look eight times as productive as one whose stories do not.
 *
 * Deleted types are included and named from the signal's own stored `event_key`, for the reason
 * ADR 0018 §4 gives: the record of how a type performed is the most useful thing this table can
 * hold about one a team has since removed.
 */
final readonly class BuildSignalPerformanceAction
{
    public function __construct(private TeamContext $teamContext) {}

    public function handle(Team $team, DateRange $range): ReportTable
    {
        $rows = $this->teamContext->run($team, function () use ($team, $range): Collection {
            /**
             * @var Collection<int, object{
             *     event_key: string,
             *     definition_name: string|null,
             *     found: int|string,
             *     kept: int|string,
             *     converted: int|string,
             *     progressed: int|string,
             *     average_heat: float|string|null,
             * }> $aggregates
             */
            $aggregates = $range->constrain(
                Signal::query()
                    ->where('signals.team_id', $team->getKey())
                    ->representatives()
                    // Left joins, both of them, because the row has to survive what it describes
                    // being gone: a deleted definition (ADR 0018 §4) and a deleted lead
                    // (`signals.lead_id` is `nullOnDelete`) must not drop a type out of its own
                    // performance table.
                    ->leftJoin('signal_definitions', 'signal_definitions.id', '=', 'signals.signal_definition_id')
                    ->leftJoin('leads', 'leads.id', '=', 'signals.lead_id')
                    ->leftJoin('pipeline_stages', 'pipeline_stages.id', '=', 'leads.pipeline_stage_id'),
                'signals.created_at',
            )
                ->groupBy('signals.event_key', 'signal_definitions.name')
                ->orderByDesc('found')
                ->selectRaw(
                    'signals.event_key as event_key, signal_definitions.name as definition_name, '
                    .'count(*) as found, '
                    // Conditional aggregation rather than four queries over the same rows. Every
                    // column here is a literal; nothing is assembled from input.
                    .'sum(case when signals.status != ? then 1 else 0 end) as kept, '
                    .'sum(case when signals.status = ? then 1 else 0 end) as converted, '
                    // The column that makes this table worth reading, and it is defined by the
                    // **team's own board** rather than by us: a lead that has moved off the
                    // default column is one somebody picked up and worked.
                    //
                    // `is_default = 0` rather than an outcome, because `StageOutcome` only
                    // distinguishes won from lost — there is no "in progress" case, and inventing
                    // one here would have been a guess about every stage in between. A lost lead
                    // still counts: it reached the pipeline and somebody spent time on it, which is
                    // exactly what this column is asking about. Whether it closed is a different
                    // question and a different report.
                    .'sum(case when leads.id is not null and pipeline_stages.is_default = 0 then 1 else 0 end) as progressed, '
                    .'avg(signals.heat) as average_heat',
                    [SignalStatus::Dismissed->value, SignalStatus::Converted->value],
                )
                ->toBase()
                ->get();

            return $aggregates;
        });

        $reportRows = [];

        foreach ($rows as $row) {
            $found = (int) $row->found;
            $converted = (int) $row->converted;

            $reportRows[] = new ReportRow(
                key: 'signal-type-'.$row->event_key,
                cells: [
                    // The definition's name where it still exists, and the stored key where it
                    // does not — which is the one case this column exists to render honestly.
                    'type' => $row->definition_name ?? $row->event_key,
                    'found' => $found,
                    'kept' => (int) $row->kept,
                    'converted' => $converted,
                    // Of what it found: the number a team compares two types on.
                    'conversion_rate' => Ratio::of($converted, $found),
                    'progressed' => (int) $row->progressed,
                    'average_heat' => $row->average_heat === null ? null : (int) round((float) $row->average_heat),
                ],
            );
        }

        return ReportTable::of(
            ReportKey::SignalPerformance,
            $this->columns(),
            $reportRows,
            'No trigger events were found in this period.',
        );
    }

    /**
     * @return list<ReportColumn>
     */
    private function columns(): array
    {
        return [
            new ReportColumn('type', 'Signal type'),
            ReportColumn::count('found', 'Found'),
            ReportColumn::count('kept', 'Not dismissed'),
            ReportColumn::count('converted', 'Became leads'),
            ReportColumn::percent('conversion_rate', 'Conversion rate'),
            ReportColumn::count('progressed', 'Picked up and worked'),
            ReportColumn::count('average_heat', 'Average score'),
        ];
    }
}
