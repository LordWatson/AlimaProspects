<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Services\SeatCounter;
use App\Modules\Billing\Actions\CancelSubscriptionAction;
use App\Modules\Billing\Actions\OpenBillingPortalAction;
use App\Modules\Billing\Actions\RefreshUsageAction;
use App\Modules\Billing\Actions\ResumeSubscriptionAction;
use App\Modules\Billing\Actions\StartCheckoutAction;
use App\Modules\Billing\Actions\SwapPlanAction;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\ChoosePlanRequest;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Http\Resources\UsageResource;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The billing screen, and the four things a team can do from it (Phase 16). Web only — billing is
 * not in the mobile app, which is why there is no `Api/V1` twin of this controller.
 *
 * Thin, as CLAUDE.md §2 requires: authorise via the gate, validate in a FormRequest, call one
 * Action, render or redirect. The one piece of work it does itself is the usage sync on render —
 * cheap (two aggregates and two upserts), and it means the figures an Owner is looking at when
 * they decide to upgrade are the figures as of that moment rather than as of the last scheduled
 * run.
 *
 * Checkout and the portal return **external** redirects, which Inertia cannot follow from an XHR.
 * `Inertia::location()` is what turns them into a full page navigation; without it the browser
 * would quietly do nothing.
 */
final class BillingController extends Controller
{
    public function index(
        Request $request,
        PlanRegistry $plans,
        PlanResolver $resolver,
        RefreshUsageAction $refreshUsage,
        SeatCounter $seats,
        BillingGateway $gateway,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('billing.view', $team);

        $canManage = $user->can('billing.manage', $team);

        $period = BillingPeriod::containing();
        $entitlements = $resolver->for($team);

        // Up-to-date figures, and a warning to the Owners if an allowance is nearly gone — one
        // Action, one pass over the ledgers (see `RefreshUsageAction` for why it runs on a GET).
        $usage = $refreshUsage->handle($team, $period);

        return Inertia::render('billing/Index', [
            'subscription' => SubscriptionResource::make($entitlements)->resolve($request),
            'plans' => array_map(
                fn (PlanData $plan): array => PlanResource::make($plan)->resolve($request),
                $plans->all(),
            ),
            'usage' => array_map(
                fn (MetricUsage $metric): array => UsageResource::make($metric)->resolve($request),
                array_values($usage),
            ),
            'period' => [
                'start' => $period->start->toIso8601String(),
                'end' => $period->end->toIso8601String(),
                'elapsed_percent' => $period->elapsedPercent(),
            ],
            'seats' => [
                'limit' => $seats->limitFor($team),
                'members' => $seats->members($team),
                'pending' => $seats->pendingInvitations($team),
                'remaining' => $seats->remaining($team),
            ],
            // Only fetched for someone who may act on them: an invoice list is a round trip to
            // Stripe, and a Member who cannot open the portal has no use for one.
            'invoices' => array_map(
                fn (InvoiceData $invoice): array => InvoiceResource::make($invoice)->resolve($request),
                $canManage ? $this->invoices($gateway, $team) : [],
            ),
            'overage' => [
                'leads' => (int) config('billing.overage.leads.amount', 0),
                'credits' => (int) config('billing.overage.ai_credits.amount', 0),
                'credits_unit' => (int) config('billing.overage.ai_credits.unit', 1_000),
                'currency' => strtoupper((string) config('billing.currency', 'gbp')),
            ],
            'sales_email' => (string) config('billing.sales_email'),
            'can' => [
                'manage' => $canManage,
            ],
        ]);
    }

    public function checkout(ChoosePlanRequest $request, StartCheckoutAction $action): HttpResponse
    {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $url = $action->handle(
            $team,
            $request->plan(),
            $this->user($request),
            successUrl: route('billing.index').'?checkout=success',
            cancelUrl: route('billing.index').'?checkout=cancelled',
        );

        return Inertia::location($url);
    }

    public function swap(ChoosePlanRequest $request, SwapPlanAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $plan = $request->plan();

        $action->handle($team, $plan, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('You’re on the :plan plan. The change is on your next invoice.', ['plan' => $plan->name]),
        ]);

        return back();
    }

    public function cancel(Request $request, CancelSubscriptionAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $action->handle($team, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your subscription will end when the period you’ve paid for does. Nothing stops before then.'),
        ]);

        return back();
    }

    public function resume(Request $request, ResumeSubscriptionAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $action->handle($team, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Your subscription is back on. Nothing was interrupted.'),
        ]);

        return back();
    }

    public function portal(Request $request, OpenBillingPortalAction $action): HttpResponse
    {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $url = $action->handle($team, $this->user($request), returnUrl: route('billing.index'));

        return Inertia::location($url);
    }

    /**
     * Past invoices, or none when Stripe can't be reached.
     *
     * The only part of this screen that needs Stripe to be up, and the least important part of it.
     * Letting the failure bubble would mean an Owner trying to find out why discovery stopped
     * gets a payment-provider error instead of their usage — so the history goes missing and the
     * rest of the page still answers the question they came with.
     *
     * @return list<InvoiceData>
     */
    private function invoices(BillingGateway $gateway, Team $team): array
    {
        try {
            return $gateway->invoices($team);
        } catch (BillingException $e) {
            Log::channel('billing')->warning('Invoice history unavailable; rendering without it.', [
                'team_id' => $team->getKey(),
                'error_code' => $e->errorCode(),
            ]);

            return [];
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }
}
