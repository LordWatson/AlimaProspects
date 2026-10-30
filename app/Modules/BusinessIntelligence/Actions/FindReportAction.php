<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Actions;

use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;

/**
 * Resolve one of a team's background-check reports by id, scoped to the team so another team's
 * report 404s (never binds cross-tenant). Keeps controllers query-free (CLAUDE.md).
 */
final class FindReportAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team, int $id): BusinessIntelReport
    {
        return $this->teamContext->run($team, static fn (): BusinessIntelReport => BusinessIntelReport::query()
            ->where('team_id', $team->getKey())
            ->whereKey($id)
            ->firstOrFail());
    }
}
