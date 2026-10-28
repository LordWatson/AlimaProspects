<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Concerns\BelongsToTeam;
use App\Modules\Core\Models\Team;

/**
 * Holds the active team for the current request/console context.
 *
 * Registered as a singleton (see CoreServiceProvider). The {@see BelongsToTeam} trait's global
 * scope reads the active team id from here so that tenant data is scoped automatically. When no
 * team is active (e.g. during migrations or unauthenticated requests) the scope is skipped and
 * Policies remain the backstop.
 */
final class TeamContext
{
    private ?int $teamId = null;

    public function set(Team|int $team): void
    {
        $this->teamId = $team instanceof Team ? (int) $team->getKey() : $team;
    }

    public function id(): ?int
    {
        return $this->teamId;
    }

    public function has(): bool
    {
        return $this->teamId !== null;
    }

    public function forget(): void
    {
        $this->teamId = null;
    }

    /**
     * Run a callback with a given team active, restoring the previous team afterwards.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function run(Team|int $team, callable $callback): mixed
    {
        $previous = $this->teamId;
        $this->set($team);

        try {
            return $callback();
        } finally {
            $this->teamId = $previous;
        }
    }
}
