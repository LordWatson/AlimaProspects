<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Http\Request;

/**
 * The resolution every Signals controller repeats — web and API alike: the caller, their team, and
 * a team-scoped read of the signal or watch being acted on.
 *
 * Reading inside {@see TeamContext} is what makes a cross-tenant request a **404 before any policy
 * runs**: the global scope hides the row, so the request can't even learn that it exists. The
 * policy still double-checks ownership afterwards (ADR 0006) — a global scope is a convenience,
 * not the tenancy guarantee.
 *
 * `team` is always eager-loaded, because every policy reads it and a lazy read would trip
 * `preventLazyLoading`.
 */
trait ResolvesSignals
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
     * @param  list<string>  $with  Extra relations the response needs loaded.
     */
    protected function signal(Request $request, int $signalId, array $with = []): Signal
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): Signal => Signal::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($signalId));
    }

    /**
     * @param  list<string>  $with  Extra relations the response needs loaded.
     */
    protected function watch(Request $request, int $watchId, array $with = []): SignalWatch
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($watchId));
    }

    /**
     * @param  list<string>  $with  Extra relations the response needs loaded.
     */
    protected function definition(Request $request, int $definitionId, array $with = []): SignalDefinition
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::query()
            // The explicit `team_id` matters more on this table than on the others: a row here may
            // belong to the **platform** rather than to a team (ADR 0018 §2), and a definition
            // endpoint must never resolve a preset - a team clones one, it does not edit it. The
            // global scope already hides presets; this says so out loud.
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($definitionId));
    }

    /**
     * @param  list<string>  $with  Extra relations the response needs loaded.
     */
    protected function watchlist(Request $request, int $watchlistId, array $with = []): SignalWatchlist
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): SignalWatchlist => SignalWatchlist::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($watchlistId));
    }

    /**
     * One entry, resolved **through its list** so an id from another team's list is a 404.
     *
     * The entry table carries its own `team_id` and the global scope uses it, so this is belt and
     * braces — but an entry is the one row in this module a member deletes by id from a screen that
     * lists dozens of them, which is exactly where an id gets mistyped or replayed.
     */
    protected function watchlistEntry(Request $request, int $watchlistId, int $entryId): SignalWatchlistEntry
    {
        $team = $this->team($request);
        $watchlist = $this->watchlist($request, $watchlistId);

        return app(TeamContext::class)->run($team, fn (): SignalWatchlistEntry => SignalWatchlistEntry::query()
            ->where('team_id', $team->getKey())
            ->where('signal_watchlist_id', $watchlist->getKey())
            ->findOrFail($entryId));
    }

    /**
     * @param  list<string>  $with  Extra relations the response needs loaded.
     */
    protected function playbook(Request $request, int $playbookId, array $with = []): SignalPlaybook
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, fn (): SignalPlaybook => SignalPlaybook::query()
            ->where('team_id', $team->getKey())
            ->with(['team', ...$with])
            ->findOrFail($playbookId));
    }

    /**
     * A transient, team-scoped playbook for authorising list, create and preview.
     */
    protected function transientPlaybook(Team $team): SignalPlaybook
    {
        $playbook = new SignalPlaybook;
        $playbook->forceFill(['team_id' => $team->getKey()]);
        $playbook->setRelation('team', $team);

        return $playbook;
    }

    /**
     * A transient, team-scoped watchlist for authorising list/create.
     */
    protected function transientWatchlist(Team $team): SignalWatchlist
    {
        $watchlist = new SignalWatchlist;
        $watchlist->forceFill(['team_id' => $team->getKey()]);
        $watchlist->setRelation('team', $team);

        return $watchlist;
    }

    /**
     * A transient, team-scoped definition for authorising list/create, which needs no stored row.
     */
    protected function transientDefinition(Team $team): SignalDefinition
    {
        $definition = new SignalDefinition;
        $definition->forceFill(['team_id' => $team->getKey()]);
        $definition->setRelation('team', $team);

        return $definition;
    }

    /**
     * A transient, team-scoped signal for authorising "may this member see the feed at all", which
     * needs no stored row.
     */
    protected function transientSignal(Team $team): Signal
    {
        $signal = new Signal;
        $signal->forceFill(['team_id' => $team->getKey()]);
        $signal->setRelation('team', $team);

        return $signal;
    }

    /**
     * A transient, team-scoped watch for authorising list/create.
     */
    protected function transientWatch(Team $team): SignalWatch
    {
        $watch = new SignalWatch;
        $watch->forceFill(['team_id' => $team->getKey()]);
        $watch->setRelation('team', $team);

        return $watch;
    }
}
