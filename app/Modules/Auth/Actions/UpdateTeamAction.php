<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Update a team's settings (name, timezone, locale, market).
 */
final class UpdateTeamAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Team $team, CreateTeamData $data, ?User $actor = null): Team
    {
        return DB::transaction(function () use ($team, $data, $actor): Team {
            $team->forceFill([
                'name' => $data->name,
                'timezone' => $data->timezone,
                'locale' => $data->locale,
                'market' => $data->market,
            ])->save();

            $this->audit->log('team.updated', $team, [
                'name' => $team->name,
                'locale' => $team->locale,
                'market' => $team->market,
            ], $actor);

            return $team;
        });
    }
}
