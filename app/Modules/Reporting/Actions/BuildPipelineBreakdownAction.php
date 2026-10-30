<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Reporting\Data\BreakdownSlice;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Database\Eloquent\Builder;

/**
 * How many leads sit in each pipeline stage, in **board order** rather than busiest-first: the
 * dashboard bar should read like the board it mirrors, so a member can see where work is piling up
 * without re-reading the labels.
 *
 * Under a filter it answers "of the prospects sourced in this period, where are they now" — the
 * stage is a current fact about a lead from the cohort, not a thing that happened in the window.
 * The screen's caption says so, because "12 in Won" means something different from "12 moved to
 * Won this month".
 *
 * Stages with no leads are kept — an empty "Won" column is the point of the chart.
 */
final readonly class BuildPipelineBreakdownAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team, DateRange $range): array
    {
        return $this->teamContext->run($team, function () use ($team, $range): array {
            $stages = PipelineStage::query()
                ->where('pipeline_stages.team_id', $team->getKey())
                ->withCount([
                    'leads' => fn (Builder $query): Builder => $range->constrain($query, 'leads.created_at'),
                ])
                ->orderBy('position')
                ->get();

            $slices = [];

            foreach ($stages as $stage) {
                $slices[] = new BreakdownSlice(
                    key: $stage->slug,
                    label: $stage->name,
                    value: (int) ($stage->leads_count ?? 0),
                    hint: $stage->outcome?->label(),
                );
            }

            return $slices;
        });
    }
}
