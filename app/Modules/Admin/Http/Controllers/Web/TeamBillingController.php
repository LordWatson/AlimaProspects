<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\GrantPlanAction;
use App\Modules\Admin\Actions\RevokePlanGrantAction;
use App\Modules\Admin\Actions\SetBillingModeAction;
use App\Modules\Admin\Actions\SetCapabilityOverrideAction;
use App\Modules\Admin\Http\Requests\GrantPlanRequest;
use App\Modules\Admin\Http\Requests\SetBillingModeRequest;
use App\Modules\Admin\Http\Requests\SetCapabilityOverrideRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\CapabilityExplainer;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Models\PlanGrant;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * One tenant's billing and capabilities (Phase 19b) — the screen an operator uses to put an
 * invoiced customer on their contracted plan, or to give a pilot a feature their plan excludes.
 *
 * The page shows the **resolved** answer beside the levers, which is the point: an operator needs
 * to see that this team is on Scale *because of a grant* rather than because of Stripe, and that
 * Signals is on *because of an override* rather than because of the plan. A screen that showed only
 * the levers would leave them guessing which one was winning.
 */
final class TeamBillingController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly PlanResolver $plans,
        private readonly CapabilityExplainer $capabilities,
        private readonly AdminAudit $audit,
    ) {}

    public function show(Team $team): Response
    {
        $this->audit->record(
            AdminAudit::RECORD_VIEWED,
            $team,
            ['resource' => 'teams', 'intent' => 'billing'],
            (int) $team->getKey(),
        );

        $entitlements = $this->plans->for($team);
        $grant = $this->plans->grantFor($team);

        return Inertia::render('admin/teams/Billing', [
            'navigation' => $this->registry->navigation(),
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'billing_mode' => BillingMode::of($team)->value,
                'suspended' => $team->isSuspended(),
            ],
            // The resolved answer, so the screen can say *why* this team is entitled to what it is.
            'entitlements' => [
                'plan' => $entitlements->plan->key->value,
                'plan_name' => $entitlements->plan->name,
                'state' => $entitlements->state->value,
                'state_label' => $entitlements->state->label(),
                'seats' => $entitlements->plan->seats,
                'leads' => $entitlements->plan->leads,
                'credits' => $entitlements->plan->credits,
                'trial_ends_at' => $entitlements->trialEndsAt?->toIso8601String(),
                'ends_at' => $entitlements->endsAt?->toIso8601String(),
            ],
            'grant' => $grant instanceof PlanGrant ? [
                'id' => $grant->id,
                'plan' => $grant->plan->value,
                'seats' => $grant->seats,
                'leads' => $grant->leads,
                'credits' => $grant->credits,
                'starts_at' => $grant->starts_at->toIso8601String(),
                'ends_at' => $grant->ends_at?->toIso8601String(),
                'note' => $grant->note,
            ] : null,
            'capabilities' => $this->capabilities->rows($team),
            'options' => [
                'modes' => array_map(
                    static fn (BillingMode $mode): array => ['value' => $mode->value, 'label' => $mode->label()],
                    BillingMode::cases(),
                ),
                'plans' => array_map(
                    static fn (PlanKey $plan): array => ['value' => $plan->value, 'label' => $plan->value],
                    PlanKey::cases(),
                ),
            ],
        ]);
    }

    public function setMode(SetBillingModeRequest $request, Team $team, SetBillingModeAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $mode = $request->mode();

        $action->handle($team, $mode, $operator);

        // `billing_mode` does not grant a plan, and the screen must say so rather than letting an
        // operator discover it. A team moved off Stripe keeps what it already had — so without a
        // grant it is on the default plan, and if it was trialling it drops there silently when the
        // trial lapses. A success toast that said only "updated" is how that goes unnoticed.
        $needsGrant = $mode !== BillingMode::Stripe
            && ! $this->plans->grantFor($team) instanceof PlanGrant;

        Inertia::flash('toast', $needsGrant
            ? [
                'type' => 'warning',
                'message' => 'Billing mode updated — but there is no plan grant, so this team is '
                    .'on the default plan. Grant the plan their contract says.',
            ]
            : ['type' => 'success', 'message' => 'Billing mode updated.']);

        return to_route('admin.teams.billing', $team);
    }

    public function grant(GrantPlanRequest $request, Team $team, GrantPlanAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $action->handle($team, $request->grant(), $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Plan granted. It outranks anything Stripe says for this team.',
        ]);

        return to_route('admin.teams.billing', $team);
    }

    public function revokeGrant(
        Request $request,
        Team $team,
        PlanGrant $grant,
        RevokePlanGrantAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        // The grant is bound by id, so check it belongs to this tenant. Route binding on a
        // tenant-owned model from outside team scope will happily find another team's row.
        abort_unless((int) $grant->team_id === (int) $team->getKey(), 404);

        $action->handle($grant, $operator);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Grant revoked.']);

        return to_route('admin.teams.billing', $team);
    }

    public function setCapability(
        SetCapabilityOverrideRequest $request,
        Team $team,
        SetCapabilityOverrideAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        $action->handle(
            $team,
            $request->capability(),
            $request->enabled(),
            $request->reason(),
            $request->expiresAt(),
            $operator,
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Override saved.']);

        return to_route('admin.teams.billing', $team);
    }

    public function clearCapability(
        Request $request,
        Team $team,
        string $capability,
        SetCapabilityOverrideAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        $resolved = Capability::tryFrom($capability);

        abort_if($resolved === null, 404);

        $action->clear($team, $resolved, $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Override removed. This team is back to what its plan says.',
        ]);

        return to_route('admin.teams.billing', $team);
    }
}
