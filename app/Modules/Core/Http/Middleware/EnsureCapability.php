<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `capability:signals` on a route whose feature a plan may not include (Phase 19b).
 *
 * This is the **courtesy** half of capability enforcement, not the enforcing half. The guard inside
 * the Action is what makes a refusal true, because a queued discovery job and a scheduled signal
 * watch have no request to put middleware on. What this adds is that a member who clicks something
 * their plan does not include gets a sentence about their plan, instead of a half-rendered screen
 * or a 500 from three layers down.
 *
 * Both come through {@see CapabilityGate}, so the two cannot disagree.
 *
 * With no active team it does nothing: an unauthenticated or team-less request has no plan to check
 * against, and the route's own `auth` middleware is what should be refusing it.
 */
final class EnsureCapability
{
    public function __construct(
        private readonly CapabilityGate $capabilities,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * @throws CapabilityException
     */
    public function handle(Request $request, Closure $next, string $capability): Response
    {
        $required = Capability::tryFrom($capability);

        // A misspelled capability is a programming error, not a plan question. A 403 would send
        // somebody to change a config file that was never wrong.
        abort_if($required === null, 500, 'Unknown capability: '.$capability);

        $team = $this->team($request);

        if (! $team instanceof Team) {
            return $next($request);
        }

        if (! $this->capabilities->allows($team, $required)) {
            throw CapabilityException::unavailable($required);
        }

        return $next($request);
    }

    private function team(Request $request): ?Team
    {
        $team = $this->teamContext->team();

        if ($team instanceof Team) {
            return $team;
        }

        $user = $request->user();

        return $user instanceof User ? $user->currentTeam()->first() : null;
    }
}
