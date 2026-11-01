<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Actions\ResetCircuitBreakerAction;
use App\Modules\Admin\Http\Requests\ResetBreakerRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\Platform\ProviderHealth;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Provider health, and the one lever on it (Phase 19c).
 *
 * The screen pairs a **live** signal with **recent** context: the circuit breaker's state as it is
 * right now, and the error and latency rates from the nightly rollup. That asymmetry is deliberate
 * and the page says so — the breaker is what tells you a provider is failing in this minute, and
 * the rates are what tell you whether it has been failing all week.
 *
 * Reset is behind `operations.manage` rather than `settings.manage`: it is an incident action for
 * whoever is on support, not a product decision. It is audited in the Action.
 */
final class ProviderHealthController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ProviderHealth $health,
    ) {}

    public function index(): Response
    {
        return Inertia::render('admin/operations/Providers', [
            'navigation' => $this->registry->navigation(),
            'providers' => $this->health->all(),
            'context' => $this->health->context(),
        ]);
    }

    public function reset(ResetBreakerRequest $request, ResetCircuitBreakerAction $action): RedirectResponse
    {
        $provider = $request->provider();

        $action->handle($provider);

        Inertia::flash('toast', [
            'type' => 'success',
            // "Closed", not "fixed". The breaker is advisory: closing it means we will try the
            // provider again, not that the provider is well.
            'message' => $provider->label().'\'s circuit is closed. Calls will be attempted again.',
        ]);

        return to_route('admin.providers.index');
    }
}
