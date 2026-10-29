<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Actions;

use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;

/**
 * Read the team's latest background-check report (highest version), or null if none has ever run.
 * All BI reads go through this Action so controllers never query directly.
 */
final class GetLatestReportAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function handle(Team $team): ?BusinessIntelReport
    {
        return $this->teamContext->run($team, static fn (): ?BusinessIntelReport => BusinessIntelReport::query()
            ->where('team_id', $team->getKey())
            ->orderByDesc('version')
            ->first());
    }
}
