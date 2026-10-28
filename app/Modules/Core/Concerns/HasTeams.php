<?php

declare(strict_types=1);

namespace App\Modules\Core\Concerns;

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User;

/**
 * Multi-team membership for the User model (ADR 0006): a user belongs to many teams and has one
 * active ("current") team that drives tenant scoping.
 *
 * @phpstan-require-extends User
 */
trait HasTeams
{
    /**
     * @return BelongsToMany<Team, $this>
     */
    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class)
            ->withPivot('role')
            ->withTimestamps();
    }

    /**
     * @return BelongsTo<Team, $this>
     */
    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(Team::class, 'current_team_id');
    }

    public function belongsToTeam(Team $team): bool
    {
        return $this->teams()->whereKey($team->getKey())->exists();
    }

    /**
     * Strict-safe read of the active team id: returns null when the attribute isn't hydrated
     * (e.g. a freshly created, not-yet-reloaded model) instead of throwing under shouldBeStrict().
     */
    public function currentTeamId(): ?int
    {
        $value = $this->getAttributes()['current_team_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Switch the active team, if the user is a member. Also updates the request {@see TeamContext}.
     */
    public function switchTeam(Team $team): bool
    {
        if (! $this->belongsToTeam($team)) {
            return false;
        }

        $this->setAttribute('current_team_id', $team->getKey());
        $this->save();

        app(TeamContext::class)->set($team);

        return true;
    }

    public function roleOn(Team $team): ?TeamRole
    {
        /** @var Team|null $membership */
        $membership = $this->teams()->whereKey($team->getKey())->first();

        if ($membership === null) {
            return null;
        }

        $role = $membership->getAttribute('pivot')?->getAttribute('role');

        return is_string($role) ? TeamRole::tryFrom($role) : null;
    }
}
