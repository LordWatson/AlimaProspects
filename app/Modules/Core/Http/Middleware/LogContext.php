<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Support\TeamContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active team into {@see TeamContext} (and spatie's team-scoped permission context)
 * and attaches structured context (team_id, user_id, request_id) to every log line for the
 * request (CLAUDE.md logging rules). Runs early in the web + api stacks.
 */
final class LogContext
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly PermissionRegistrar $permissions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-Id') ?: (string) Str::uuid();
        $request->headers->set('X-Request-Id', $requestId);

        $user = Auth::user();

        if ($user instanceof User && $user->currentTeamId() !== null) {
            $this->teamContext->set($user->currentTeamId());
            // Keep spatie's team-scoped roles/permissions resolving against the active team.
            $this->permissions->setPermissionsTeamId($user->currentTeamId());
        }

        Log::withContext([
            'request_id' => $requestId,
            'user_id' => $user?->getKey(),
            'team_id' => $this->teamContext->id(),
        ]);

        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }
}
