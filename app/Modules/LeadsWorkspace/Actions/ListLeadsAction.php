<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * One page of a team's leads for the index screen and `/api/v1` (Phase 10).
 *
 * What a filter *means* lives in {@see LeadQueryBuilder}, shared with the CSV export and bulk
 * selection; this Action's job is the page: the eager loads each row needs, the page size, and
 * keeping the filters on the links.
 *
 * It guarantees **a fixed number of queries whatever the page size** — the grade badge, contact
 * count, stage, tags and assignees all come from eager loads and `withCount`, so a page of 100
 * costs what a page of 10 does and `preventLazyLoading` stays quiet.
 */
final class ListLeadsAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly LeadQueryBuilder $leadQuery,
    ) {}

    /**
     * @return LengthAwarePaginator<int, Lead>
     */
    public function handle(Team $team, LeadFilters $filters, int $perPage = 25): LengthAwarePaginator
    {
        $perPage = max(1, min($perPage, 100));

        return $this->teamContext->run($team, fn (): LengthAwarePaginator => $this->leadQuery
            ->filtered($team, $filters)
            ->with([
                // The grade badge on each row, against the team's *active* ICP only.
                'activeScore',
                'pipelineStage',
                'tags',
                'assignments.user:id,name',
            ])
            // Both counts are sub-queries on the one statement, so they cost the same whatever the
            // page size. `tasks` is constrained to the open ones, which is what the row shows.
            ->withCount(['contacts', 'tasks' => fn ($tasks) => $tasks->open()])
            ->paginate($perPage)
            // Keeps the filters on every page link, so page 2 of a filtered list is still filtered
            // — and a shared URL reproduces exactly what the sender saw.
            ->appends($filters->toParams()));
    }
}
