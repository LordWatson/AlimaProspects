<?php

declare(strict_types=1);

namespace App\Modules\ICP\Actions;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\IcpSuggestion;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * One suggestion, by team as well as by id, with its profile loaded. Another team's suggestion is
 * not found rather than bound and then refused (ADR 0006): the policy is the second check, not the
 * first.
 */
final class FindIcpSuggestionAction
{
    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * @throws ModelNotFoundException<IcpSuggestion>
     */
    public function handle(Team $team, int $id): IcpSuggestion
    {
        return $this->teamContext->run($team, fn (): IcpSuggestion => IcpSuggestion::query()
            ->where('team_id', $team->getKey())
            ->with('icp')
            ->findOrFail($id));
    }
}
