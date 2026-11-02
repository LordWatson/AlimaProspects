<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Optional network restriction on `/admin` (Phase 19a).
 *
 * Empty by default, which is the only honest default: an allowlist configured wrong locks the ops
 * team out of the tool they would use to fix it, and a developer on a home connection has no fixed
 * address to put in it. Set `ADMIN_IP_ALLOWLIST` in production, where the office or VPN range is
 * known.
 *
 * A **404**, like {@see EnsurePlatformAdmin}: from the wrong network the panel does not exist. The
 * refusal is logged, because an attempt to reach the admin panel from an unexpected address is one
 * of the few events in this product worth a line on its own — and the log carries the address,
 * which is the point of the line.
 *
 * {@see IpUtils} handles CIDR notation for both IPv4 and IPv6, so `10.0.0.0/8` works and a bare
 * address works too. Note it reads `$request->ip()`, which behind a proxy is only as trustworthy as
 * `TrustProxies` is configured to be — Phase 21's deployment work is where that is settled.
 */
final class AdminIpAllowlist
{
    public function handle(Request $request, Closure $next): Response
    {
        $allowed = $this->allowlist();

        if ($allowed === []) {
            return $next($request);
        }

        $ip = (string) $request->ip();

        if (IpUtils::checkIp($ip, $allowed)) {
            return $next($request);
        }

        Log::warning('Admin panel request from an address outside the allowlist.', [
            'ip' => $ip,
            'path' => $request->path(),
            'user_id' => $request->user()?->getAuthIdentifier(),
        ]);

        abort(404);
    }

    /**
     * @return list<string>
     */
    private function allowlist(): array
    {
        /** @var mixed $configured */
        $configured = config('admin.ip_allowlist', []);

        if (! is_array($configured)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? trim($value) : '', $configured),
            static fn (string $value): bool => $value !== '',
        ));
    }
}
