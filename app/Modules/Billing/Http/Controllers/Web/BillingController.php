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
use App\Modules\Billing\Actions\StartTopUpCheckoutAction;
use App\Modules\Billing\Actions\SwapPlanAction;
use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Data\BillingPeriod;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\MetricUsage;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\BillingMode;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Http\Requests\BuyTopUpRequest;
use App\Modules\Billing\Http\Requests\ChoosePlanRequest;
use App\Modules\Billing\Http\Resources\InvoiceResource;
use App\Modules\Billing\Http\Resources\PlanResource;
use App\Modules\Billing\Http\Resources\SubscriptionResource;
use App\Modules\Billing\Http\Resources\UsageResource;
use App\Modules\Billing\Services\PlanRegistry;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\TopUpCatalogue;
use App\Modules\Core\Contracts\CapabilityGate;
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
        StripeSwitch $stripe,
        CapabilityGate $capabilities,
        TopUpCatalogue $topups,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('billing.view', $team);

        $canManage = $user->can('billing.manage', $team);

        $period = BillingPeriod::containing();
        $entitlements = $resolver->for($team);

        // Self-serve needs **both**: Stripe switched on for the deployment, and this tenant billed
        // through it. An invoiced customer has no card to change and nothing to cancel.
        $mode = BillingMode::of($team);
        $selfServe = $stripe->isEnabled() && $mode->allowsSelfServe();

        // Up-to-date figures, and a warning to the Owners if an allowance is nearly gone — one
        // Action, one pass over the ledgers (see `RefreshUsageAction` for why it runs on a GET).
        $usage = $refreshUsage->handle($team, $period);

        return Inertia::render('billing/Index', [
            'subscription' => SubscriptionResource::make($entitlements)->resolve($request),
            'plans' => array_map(
                fn (PlanData $plan): array => PlanResource::make($plan)->resolve($request),
                $plans->all(),
            ),
            // **Only the metrics this team's plan can actually reach** (`/code-review`). The meter
            // keeps a row for every metric whatever the plan — it is a billing record, and a gap in
            // one is worse than a zero — but a team whose plan has no Signals has no business
            // reading "Signal searches 0 / 100" and a sentence about watches stopping. The
            // capability is asked of the one gate (ADR 0015); the filter is here rather than in
            // `UsageLedger`, because what to *show* is a screen's question and what to *record* is
            // not.
            'usage' => array_map(
                fn (MetricUsage $metric): array => UsageResource::make($metric)->resolve($request),
                array_values(array_filter(
                    $usage,
                    function (MetricUsage $metric) use ($capabilities, $team): bool {
                        $required = $metric->metric->requiresCapability();

                        return $required === null || $capabilities->allows($team, $required);
                    },
                )),
            ),
            // PL4. What this team can buy more of, keyed by metric — read by the usage bars so a
            // team that has run out of reveals is offered the thing that fixes it rather than an
            // upgrade to a plan they may not need. Empty for anybody who cannot spend money, and
            // empty when Stripe is off or its top-up prices are not configured: the catalogue's
            // own rule, so a deployment without them has no button rather than a broken one.
            'topups' => $canManage && $selfServe ? $topups->all() : [],
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
            // **Keyed by metric, derived from the enum** (S16). It used to be three flat keys —
            // `leads`, `credits`, `credits_unit` — which the screen read through a two-way ternary;
            // adding a third metric would have priced signal searches as AI credits on the one
            // screen whose whole job is to tell a team what things cost. A map cannot have that bug.
            'overage' => [
                'metrics' => $this->overagePricing(),
                'currency' => strtoupper((string) config('billing.currency', 'gbp')),
            ],
            'sales_email' => (string) config('billing.sales_email'),
            'can' => [
                'manage' => $canManage,
                // **Whether self-serve billing is available at all** (Phase 19b).
                //
                // The screen must not offer a button that will throw. Stripe can be switched off
                // globally by an operator, and a tenant can be on `manual` or `disabled` billing —
                // in all three cases checkout, the portal, a swap and a cancellation refuse with
                // `billing.disabled`. Sending the answer as a prop means "Stripe is off" renders as
                // a product state with a sentence, rather than as a 503 the member meets after
                // clicking.
                //
                // `manage` stays as it was: it is about the member's role, and conflating the two
                // would tell an Owner they lack permission when in fact we are not taking payments.
                'checkout' => $canManage && $selfServe,
                'portal' => $canManage && $selfServe && $entitlements->isSubscribed(),
            ],
            'billing_mode' => $mode->value,
            'self_serve' => $selfServe,
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
     * Buy more of one metric (PL4).
     *
     * Thin like its three siblings: gate, FormRequest, one Action, external redirect. The one
     * branch is a withdrawn option — a price that existed when the page rendered and does not now —
     * which is a 404 rather than a 422, because the request was well formed and the thing it names
     * has gone.
     */
    public function topUp(
        BuyTopUpRequest $request,
        TopUpCatalogue $catalogue,
        StartTopUpCheckoutAction $action,
    ): HttpResponse {
        $team = $this->team($request);

        $this->authorize('billing.manage', $team);

        $option = $request->option($catalogue);

        abort_if($option === null, HttpResponse::HTTP_NOT_FOUND);

        $url = $action->handle(
            $team,
            $option,
            $this->user($request),
            successUrl: route('billing.index'),
            cancelUrl: route('billing.index'),
        );

        return Inertia::location($url);
    }

    /**
     * What a unit of overage costs, per metric (S16).
     *
     * Over `BillingMetric::cases()` rather than a hand-written list, so a metric added to the enum
     * is priced on the screen without anybody remembering to come here — the same reason
     * `BillingMetric::subjectLabel()` exists one module along.
     *
     * `unit` is how many of the metric one billed unit covers: leads singly, credits in thousands,
     * signal searches in hundreds. A metric with no metered price in Stripe still appears with its
     * list price, because the sentence under the bars is about what a team *would* pay rather than
     * about what we are currently able to bill.
     *
     * **`price_line` is a whole sentence, not a noun** ({@see BillingMetric::overagePriceLine()}).
     * The first version sent the metric's `label()` and let the client interpolate it into a shared
     * ":price per :thing" template, which the brand review caught: a shared template fixes English
     * word order, and Arabic needs its own construction per noun. One complete key per metric is
     * what lets the translator own the sentence (ADR 0007).
     *
     * @return array<string, array{amount: int, unit: int, label: string, price_line: string}>
     */
    private function overagePricing(): array
    {
        $pricing = [];

        foreach (BillingMetric::cases() as $metric) {
            $pricing[$metric->value] = [
                'amount' => (int) config('billing.overage.'.$metric->value.'.amount', 0),
                'unit' => $metric->overageUnit(),
                // Both are translation keys, like every label crossing this boundary (ADR 0007).
                'label' => $metric->label(),
                'price_line' => $metric->overagePriceLine(),
            ];
        }

        return $pricing;
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
