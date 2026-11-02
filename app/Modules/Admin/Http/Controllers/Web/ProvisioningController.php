<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\ProvisionTeamAction;
use App\Modules\Admin\Http\Requests\ProvisionTeamRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\SupportedLocale;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Onboarding a business from the panel (Phase 19b).
 *
 * Two POSTs rather than one, and the first one writes nothing. `preview` is the dry run — the
 * screen shows exactly what will be created and, crucially, whether the owner's address already
 * belongs to an account, before anybody is emailed an invitation into somebody else's business.
 */
final class ProvisioningController extends Controller
{
    public function __construct(private readonly ResourceRegistry $registry) {}

    public function create(): Response
    {
        return Inertia::render('admin/provisioning/Create', [
            'navigation' => $this->registry->navigation(),
            'options' => [
                'markets' => array_map(
                    static fn (Market $market): array => ['value' => $market->value, 'label' => $market->label()],
                    Market::cases(),
                ),
                'locales' => array_map(
                    static fn (SupportedLocale $locale): array => ['value' => $locale->value, 'label' => $locale->label()],
                    SupportedLocale::cases(),
                ),
                'modes' => array_map(
                    static fn (BillingMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
                    BillingMode::cases(),
                ),
                'plans' => array_map(
                    static fn (PlanKey $plan): array => ['value' => $plan->value, 'label' => $plan->value],
                    PlanKey::cases(),
                ),
                'capabilities' => array_map(
                    static fn (Capability $capability): array => [
                        'value' => $capability->value,
                        'label' => $capability->label(),
                    ],
                    Capability::cases(),
                ),
            ],
        ]);
    }

    /**
     * The dry run. Validates and describes; writes nothing.
     */
    public function preview(ProvisionTeamRequest $request, ProvisionTeamAction $action): RedirectResponse
    {
        Inertia::flash('provision_preview', $action->preview($request->provision())->toArray());

        return back();
    }

    public function store(ProvisionTeamRequest $request, ProvisionTeamAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $team = $action->handle($request->provision(), $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $team->name.' is set up. The owner has an invitation to set a password.',
        ]);

        return to_route('admin.teams.show', $team);
    }
}
