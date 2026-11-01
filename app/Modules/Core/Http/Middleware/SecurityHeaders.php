<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Middleware;

use App\Modules\Core\Support\Security\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Puts the response security headers on every response (Phase 20, ADR 0017).
 *
 * **Global middleware, not a group.** The headers belong to the HTTP response, not to the web or
 * the API stack: `/up`, the Pulse dashboard, a CSV download, a 500 page and an `/api/v1` error
 * envelope all come back through here. Registering it on the groups instead is how the one route
 * nobody thought about ends up as the one route without a header.
 *
 * Three things about it are decisions rather than mechanics.
 *
 * **The CSP is nonce-based and skipped while the Vite dev server is running.** A nonce policy holds
 * only if *every* inline script and style carries the nonce, and under `npm run dev` Vue injects
 * component styles from JavaScript with no nonce to carry. A policy that stayed on locally would
 * therefore hold by breaking the page, which teaches a developer to switch it off — so it is off
 * exactly there, and on for every built asset path, which is every environment that is not
 * somebody's laptop mid-edit. {@see ContentSecurityPolicy} for what it says.
 *
 * **The CSP is skipped on the vendor dashboards** (`pulse`, `horizon`). Both inject their own
 * inline Livewire and Alpine bootstrapping that we do not control and cannot nonce, and a policy
 * that breaks the tool an operator opens at 2am is worse than no policy on a route that is already
 * behind the platform-admin door, enforced 2FA and the optional IP allowlist. The exclusion is a
 * config list so it is visible rather than implied.
 *
 * **HSTS follows the connection, not the environment.** Sent over plain HTTP a browser ignores it;
 * sent from a local `.test` domain it pins that host to HTTPS in the developer's browser for a year
 * and costs them a morning. So the condition is `$request->secure()` — which is true on staging and
 * production and false on a laptop — rather than `isProduction()`, which would have skipped a
 * staging box that is just as public.
 */
final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = $this->shouldSendPolicy($request)
            ? Vite::useCspNonce()
            : null;

        /** @var Response $response */
        $response = $next($request);

        $this->addBaseHeaders($response);
        $this->addStrictTransportSecurity($request, $response);

        if ($nonce !== null && $this->isHtml($response)) {
            $this->addContentSecurityPolicy($response, $nonce);
        }

        return $response;
    }

    /**
     * The headers every response carries, whatever its content type.
     */
    private function addBaseHeaders(Response $response): void
    {
        /** @var array<string, mixed> $headers */
        $headers = (array) config('security.headers', []);

        $response->headers->set('X-Content-Type-Options', 'nosniff');

        foreach ([
            'X-Frame-Options' => 'frame_options',
            'Referrer-Policy' => 'referrer_policy',
            'Permissions-Policy' => 'permissions_policy',
            'Cross-Origin-Opener-Policy' => 'cross_origin_opener_policy',
            'Cross-Origin-Resource-Policy' => 'cross_origin_resource_policy',
            'X-Permitted-Cross-Domain-Policies' => 'permitted_cross_domain_policies',
        ] as $header => $key) {
            $value = $headers[$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $response->headers->set($header, trim($value));
            }
        }
    }

    /**
     * HSTS, over a secure connection only. See the class docblock for why that and not the
     * environment is the condition.
     */
    private function addStrictTransportSecurity(Request $request, Response $response): void
    {
        if (! $request->secure() || ! (bool) config('security.headers.hsts.enabled', true)) {
            return;
        }

        $value = 'max-age='.max(0, (int) config('security.headers.hsts.max_age', 31_536_000));

        if ((bool) config('security.headers.hsts.include_subdomains', true)) {
            $value .= '; includeSubDomains';
        }

        if ((bool) config('security.headers.hsts.preload', false)) {
            $value .= '; preload';
        }

        $response->headers->set('Strict-Transport-Security', $value);
    }

    private function addContentSecurityPolicy(Response $response, string $nonce): void
    {
        $header = (bool) config('security.csp.report_only', false)
            ? 'Content-Security-Policy-Report-Only'
            : 'Content-Security-Policy';

        $response->headers->set($header, (new ContentSecurityPolicy($nonce))->value());
    }

    /**
     * Whether this request should get a policy at all — and therefore whether a nonce needs
     * minting before the response is rendered.
     *
     * The nonce has to be decided *before* `$next()`, because the blade tags read it while the view
     * renders. That is why this asks about the request rather than the response.
     */
    private function shouldSendPolicy(Request $request): bool
    {
        if (! (bool) config('security.csp.enabled', true)) {
            return false;
        }

        if (Vite::isRunningHot()) {
            return false;
        }

        $patterns = $this->exemptPatterns();

        return $patterns === [] || ! $request->is(...$patterns);
    }

    /**
     * The paths that get no policy, with the **configured** Pulse path folded in.
     *
     * `config/security.php` lists `pulse` and `horizon` as the readable default, but `PULSE_PATH`
     * is an env var: a deployment that moves the dashboard to `/_ops` would otherwise lose its
     * exemption and get a broken dashboard, with the config file still claiming the exemption
     * exists. Derived rather than duplicated, for the same reason `connect-src` is.
     *
     * @return list<string>
     */
    private function exemptPatterns(): array
    {
        /** @var array<array-key, mixed> $except */
        $except = (array) config('security.csp.except', []);

        $patterns = array_values(array_filter(
            array_map(static fn (mixed $pattern): string => is_string($pattern) ? $pattern : '', $except),
            static fn (string $pattern): bool => $pattern !== '',
        ));

        $pulsePath = config('pulse.path');

        if (is_string($pulsePath) && trim($pulsePath, '/') !== '') {
            $pulsePath = trim($pulsePath, '/');
            $patterns[] = $pulsePath;
            $patterns[] = $pulsePath.'/*';
        }

        return array_values(array_unique($patterns));
    }

    /**
     * A CSP on a JSON envelope or a CSV download protects nothing — the directives only bind a
     * document the browser parses as HTML.
     */
    private function isHtml(Response $response): bool
    {
        return str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }
}
