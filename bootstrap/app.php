<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Modules\Auth\Http\Middleware\EnsureTokenAbility;
use App\Modules\Core\Exceptions\ApiExceptionRenderer;
use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Core\Http\Middleware\LogContext;
use App\Modules\Core\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state', 'locale']);

        // RFC 8058 one-click unsubscribe (Compliance, Phase 15). A mail client posting
        // `List-Unsubscribe=One-Click` has no session and no CSRF token, so the route cannot
        // require one. Its authorisation is the **signed URL** instead: the link carries a
        // signature over the message id, so a request without one is rejected before the
        // controller, and anybody holding a valid link already holds the recipient's email.
        $middleware->validateCsrfTokens(except: ['unsubscribe/*']);

        $middleware->web(append: [
            HandleAppearance::class,
            // Resolve the active locale before Inertia shares props, so shared
            // translations and the `dir` attribute reflect it on first paint.
            SetLocale::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            LogContext::class,
        ]);

        // Structured log context + tenant resolution on the API too.
        $middleware->api(append: [
            SetLocale::class,
            LogContext::class,
        ]);

        // Token scopes on /api/v1 (Phase 17). `ability:leads:read,leads:write` passes if the
        // acting token carries any one of them; a first-party session caller carries no token and
        // is let through at the web app's trust level. See EnsureTokenAbility.
        $middleware->alias([
            'ability' => EnsureTokenAbility::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Domain exceptions render a JSON envelope for API/JSON (see DomainException::render);
        // for web/Inertia requests, surface the friendly message as a toast and return the user
        // to where they were.
        $exceptions->render(function (DomainException $e, Request $request): ?RedirectResponse {
            if ($request->is('api/*') || $request->expectsJson()) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $e->getMessage()]);

            return back();
        });

        // Everything else on `api/*` — a failed validation, a 404, a throttle, an unhandled
        // throwable — answers with the same envelope as a domain exception, so a client has one
        // error shape to parse rather than three. Scoped by path, not by `expectsJson()`: the web
        // forms depend on Laravel's native 422 for their error bags.
        $exceptions->render(
            fn (Throwable $e, Request $request) => app(ApiExceptionRenderer::class)->render($e, $request),
        );
    })->create();
