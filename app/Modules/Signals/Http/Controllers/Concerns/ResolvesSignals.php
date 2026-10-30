<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Concerns;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
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
