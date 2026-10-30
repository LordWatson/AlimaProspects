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

    /**
     * The active team as a model, once something has needed it — see {@see self::team()}. Held for
     * the **current** id only and dropped the moment the id changes, so a long-lived queue worker
     * can never answer with a team it loaded three jobs ago.
     */
    private ?Team $team = null;

    /**
     * The id {@see self::$team} was resolved for — including when it resolved to **null**, for a
     * context pointing at a team that has since been deleted. Without it a null result would be
     * re-queried on every call, which in an API Resource serialising a page of rows is an N+1.
     */
    private ?int $resolvedFor = null;

    public function set(Team|int $team): void
    {
        $this->teamId = $team instanceof Team ? (int) $team->getKey() : $team;
        $this->team = $team instanceof Team ? $team : null;
        $this->resolvedFor = $team instanceof Team ? $this->teamId : null;
    }

    public function id(): ?int
    {
        return $this->teamId;
    }

    public function has(): bool
    {
        return $this->teamId !== null;
    }

    /**
     * The active team as a model, or null when none is active.
     *
     * **Memoised by id**, which is the point: callers that only hold a tenant id — an API Resource
     * serialising a page of rows, for instance — need the team's own settings (its market, its
     * timezone) without issuing a query per row. Loading it here costs one query per context,
     * however many rows ask.
     *
     * Read-only by intent. The memo is a convenience for settings that do not change inside a
     * request, not a cache to write through: anything that *updates* a team loads and saves its own
     * instance.
     */
    public function team(): ?Team
    {
        if ($this->teamId === null) {
            return null;
        }

        if ($this->resolvedFor === $this->teamId) {
            return $this->team;
        }

        // `find`, not `findOrFail`: a context pointing at a team that has since been deleted is a
        // situation to read as "no team", not one to turn into a 404 inside an API Resource. The
        // null is memoised too — see `$resolvedFor`.
        $this->resolvedFor = $this->teamId;

        return $this->team = Team::query()->find($this->teamId);
    }

    public function forget(): void
    {
        $this->teamId = null;
        $this->team = null;
        $this->resolvedFor = null;
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
        $previousTeam = $this->team;
        $previousResolvedFor = $this->resolvedFor;
        $this->set($team);

        try {
            return $callback();
        } finally {
            $this->teamId = $previous;
            $this->team = $previousTeam;
            $this->resolvedFor = $previousResolvedFor;
        }
    }
}
