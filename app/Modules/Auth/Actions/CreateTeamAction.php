<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;

/**
 * Create a team and make the given user its Owner. Optionally switch the user's active team to it.
 */
final class CreateTeamAction
{
    public function __construct(
        private readonly TeamMembership $membership,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(User $owner, CreateTeamData $data, bool $switchTo = false): Team
    {
        return DB::transaction(function () use ($owner, $data, $switchTo): Team {
            $team = Team::query()->create([
                'name' => $data->name,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'market' => $data->market,
            ]);

            // Scope subsequent tenant writes (audit) to the new team.
            $this->teamContext->set($team);

            $this->membership->add($owner, $team, TeamRole::Owner);

            if ($switchTo || $owner->currentTeamId() === null) {
                $owner->forceFill(['current_team_id' => $team->getKey()])->save();
            }

            $this->audit->log('team.created', $team, ['name' => $team->name], $owner);

            return $team;
        });
    }
}
