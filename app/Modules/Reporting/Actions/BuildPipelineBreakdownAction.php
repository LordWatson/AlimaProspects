<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Reporting\Data\BreakdownSlice;

/**
 * How many leads sit in each pipeline stage, in **board order** rather than busiest-first: the
 * dashboard bar should read like the board it mirrors, so a member can see where work is piling up
 * without re-reading the labels.
 *
 * Stages with no leads are kept — an empty "Won" column is the point of the chart.
 */
final readonly class BuildPipelineBreakdownAction
{
    public function __construct(private TeamContext $teamContext) {}

    /**
     * @return list<BreakdownSlice>
     */
    public function handle(Team $team): array
    {
        return $this->teamContext->run($team, function () use ($team): array {
            $stages = PipelineStage::query()
                ->where('pipeline_stages.team_id', $team->getKey())
                ->withCount('leads')
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
