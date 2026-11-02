<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Middleware;

use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Listeners\SyncSubscriptionFromStripe;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Stops Stripe's webhook being accepted while Stripe is switched off (Phase 19b).
 *
 * **Why it is a global middleware.** Cashier registers its own routes in a group with a prefix and
 * a name and **no middleware at all**, and a package's routes are not ours to annotate after the
 * fact. Appending to the global stack is the one placement that reaches them. The alternative —
 * `Cashier::ignoreRoutes()` and re-registering both of its routes ourselves — would be our copy of
 * somebody else's controller wiring to keep in step.
 *
 * **Why it matches on the path and not the route name.** Global middleware runs in the kernel's
 * pipeline *before* the router has matched anything, so `$request->route()` is **null** here — a
 * route-name check compiles, reads perfectly and never fires. That is how this was written first,
 * and the webhook test is what caught it. The path is the only identity a request has this early.
 *
 * It is **defence in depth, not the protection**. The protection is
 * {@see SyncSubscriptionFromStripe}, which is the only thing a webhook actually changes and which
 * refuses for itself. A 404 here means a webhook that arrived while we were not listening gets
 * retried by Stripe later rather than being processed quietly now — which is what we want, because
 * Stripe retries for days.
 *
 * Logged at `warning`, because webhooks arriving while the switch is off is exactly the state
 * somebody needs to know about before they switch it back on: there is a queue of events waiting.
 */
final class RefuseStripeWhenDisabled
{
    public function __construct(private readonly StripeSwitch $stripe) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->is($this->webhookPath())) {
            return $next($request);
        }

        if ($this->stripe->isEnabled()) {
            return $next($request);
        }

        Log::channel('billing')->warning('Refused a Stripe webhook: Stripe is switched off.', [
            'path' => $request->path(),
            'ip' => $request->ip(),
        ]);

        abort(404);
    }

    /**
     * Cashier's webhook path, from its own config rather than hard-coded — `cashier.path` is
     * configurable and a deployment that changed it would otherwise lose this guard silently.
     */
    private function webhookPath(): string
    {
        $prefix = trim((string) config('cashier.path', 'stripe'), '/');

        return $prefix === '' ? 'webhook' : $prefix.'/webhook';
    }
}
