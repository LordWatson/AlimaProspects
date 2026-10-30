<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controllers\Web\Concerns;

use App\Models\User;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Http\Request;

/**
 * The resolution every compliance controller repeats: the caller, their team, and a team-stamped
 * model to authorise against.
 *
 * The transient models are the point. Both of the module's tables are **one row per team**, and the
 * row usually does not exist yet — a team that has never opened the screen has no assessment and no
 * policy. Authorising against a transient, team-stamped instance means the policy is asked the same
 * question whether or not anything has been saved, instead of the first visit skipping
 * authorisation because there was nothing to authorise.
 *
 * `team` is always set on the relation, because the policies read it and a lazy read would trip
 * `preventLazyLoading`.
 */
trait ResolvesCompliance
{
    protected function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    protected function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * The team's assessment for a market, stored or transient.
     */
    protected function liaRecord(Team $team, Market $market): LiaRecord
    {
        $record = app(TeamContext::class)->run($team, fn (): ?LiaRecord => LiaRecord::query()
            ->where('team_id', $team->getKey())
            ->where('market', $market->value)
            ->first());

        if ($record === null) {
            $record = new LiaRecord;
            $record->forceFill(['team_id' => $team->getKey(), 'market' => $market->value]);
        }

        $record->setRelation('team', $team);

        return $record;
    }

    /**
     * The team's retention policy, stored or transient. A transient one carries the configured
     * defaults, which are the windows the sweep is actually applying — so the screen shows the real
     * numbers rather than empty fields.
     */
    protected function retentionPolicy(Team $team): RetentionPolicy
    {
        $policy = app(TeamContext::class)->run($team, fn (): ?RetentionPolicy => RetentionPolicy::query()
            ->where('team_id', $team->getKey())
            ->first());

        if ($policy === null) {
            $policy = new RetentionPolicy;
            $policy->forceFill([
                'team_id' => $team->getKey(),
                'is_enabled' => true,
                'uncontacted_lead_days' => (int) config('compliance.retention.defaults.uncontacted_lead_days', 180),
                'contacted_lead_days' => (int) config('compliance.retention.defaults.contacted_lead_days', 540),
            ]);
        }

        $policy->setRelation('team', $team);

        return $policy;
    }
}
