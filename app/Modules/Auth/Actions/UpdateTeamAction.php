<?php

declare(strict_types=1);

namespace App\Modules\Auth\Actions;

use App\Models\User;
use App\Modules\Auth\Data\CreateTeamData;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Update a team's settings (name, timezone, locale, market, minimum lead confidence).
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
                'min_lead_confidence' => $data->minLeadConfidence,
            ])->save();

            $this->audit->log('team.updated', $team, [
                'name' => $team->name,
                'locale' => $team->locale,
                'market' => $team->market,
                // Changing this changes which leads the whole team can see, so the trail records
                // it: "half our leads vanished" is a question somebody will ask.
                'min_lead_confidence' => $team->min_lead_confidence,
            ], $actor);

            return $team;
        });
    }
}
