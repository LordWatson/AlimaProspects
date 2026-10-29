<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolve the active locale for the request and apply it.
 *
 * Precedence: an explicit per-user choice (the `locale` cookie) wins; otherwise
 * the active team's configured locale; otherwise the app default. Only the
 * supported locales (en/ar) are honoured — anything else falls through. Runs
 * before HandleInertiaRequests so shared props and the `dir` attribute reflect it.
 */
final class SetLocale
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->resolve($request));

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $cookie = $request->cookie('locale');

        if (is_string($cookie) && Locale::supported($cookie)) {
            return $cookie;
        }

        $user = $request->user();

        if ($user instanceof User) {
            $team = $user->currentTeam()->first();

            if ($team instanceof Team && Locale::supported($team->locale)) {
                return $team->locale;
            }
        }

        return Locale::default();
    }
}
