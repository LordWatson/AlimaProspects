<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Builds the kanban board (Phase 10b): the team's stages in order, each with its leads and a true
 * total.
 *
 * **The board is a summary, not the list.** Each column loads at most
 * `leads-workspace.board_column_limit` leads but reports its real count, so a column with 400 leads
 * says 400 and the member goes to the filtered list rather than the browser trying to render 400
 * cards. That is also what keeps the board a fixed number of queries: one for the stages, one for
 * the counts, one for the cards, and one per eager-loaded relation.
 *
 * Leads with **no** stage belong to the default column — see the `pipeline_stage_id` migration —
 * which is why the counts and the cards are fetched by stage id rather than through the relation.
 */
final class ListPipelineAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly EnsureDefaultPipelineStagesAction $ensureStages,
    ) {}

    /**
     * @return array{stages: Collection<int, PipelineStage>, leads: array<int, Collection<int, Lead>>, counts: array<int, int>}
     */
    public function handle(Team $team): array
    {
        $stages = $this->ensureStages->handle($team);

        return $this->teamContext->run($team, function () use ($team, $stages): array {
            $default = $stages->firstWhere('is_default', true);
            $limit = max(1, (int) config('leads-workspace.board_column_limit', 25));

            $counts = [];
            $leads = [];

            foreach ($stages as $stage) {
                $stageId = (int) $stage->getKey();
                $isDefault = $default !== null && $stageId === (int) $default->getKey();

                $counts[$stageId] = $this->query($team, $stageId, $isDefault)->count();

                $leads[$stageId] = $this->query($team, $stageId, $isDefault)
                    ->with(['activeScore', 'tags', 'assignments.user:id,name'])
                    ->withCount(['tasks' => fn ($query) => $query->open()])
                    // Newest first: a board is worked from the top, and the lead that arrived last
                    // is the one nobody has looked at.
                    ->orderByDesc('id')
                    ->limit($limit)
                    ->get();
            }

            return ['stages' => $stages, 'leads' => $leads, 'counts' => $counts];
        });
    }

    /**
     * @return Builder<Lead>
     */
    private function query(Team $team, int $stageId, bool $isDefault): Builder
    {
        return Lead::query()
            ->where('team_id', $team->getKey())
            ->where(fn ($query) => $query
                ->where('pipeline_stage_id', $stageId)
                // An unplaced lead shows in the default column rather than nowhere.
                ->when($isDefault, fn ($q) => $q->orWhereNull('pipeline_stage_id')));
    }
}
