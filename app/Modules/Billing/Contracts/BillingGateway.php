<?php

declare(strict_types=1);

namespace App\Modules\Billing\Contracts;

use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\StripeBillingGateway;
use App\Modules\Billing\Testing\FakeBillingGateway;
use App\Modules\Core\Models\Team;

/**
 * Everything Billing does that leaves the building, behind one interface.
 *
 * Two reasons it exists rather than the Actions calling Cashier directly. The first is testing: a
 * test suite that hits Stripe is a test suite that fails when somebody's network does, and the
 * phase's acceptance criterion is explicitly "with Stripe faked" — {@see FakeBillingGateway}
 * records calls instead of making them. The second is the transaction rule (CLAUDE.md §2): every
 * method here is an external call, so having them all in one place makes it obvious that none of
 * them may be invoked inside `DB::transaction()`.
 *
 * The live implementation is {@see StripeBillingGateway}. Every method throws
 * {@see BillingException} rather than letting a Stripe exception reach a controller — Stripe's
 * messages are written for developers and occasionally name the merchant account.
 */
interface BillingGateway
{
    /**
     * A hosted Stripe Checkout URL for starting a subscription to `$plan`.
     *
     * @throws BillingException
     */
    public function checkoutUrl(Team $team, PlanData $plan, string $successUrl, string $cancelUrl): string;

    /**
     * A Stripe customer-portal URL, where a team manages its card, address and cancellation.
     *
     * @throws BillingException
     */
    public function portalUrl(Team $team, string $returnUrl): string;

    /**
     * Move an existing subscription onto a different plan, prorated.
     *
     * @throws BillingException
     */
    public function swapPlan(Team $team, PlanData $plan): void;

    /**
     * Cancel at the end of the paid period, leaving the team working until then.
     *
     * @throws BillingException
     */
    public function cancel(Team $team): void;

    /**
     * Undo a cancellation that is still inside its grace period.
     *
     * @throws BillingException
     */
    public function resume(Team $team): void;

    /**
     * Report metered overage against a price on the team's subscription.
     *
     * `$quantity` is an **increment**, not a running total: Stripe sums the events it is sent over
     * the billing period, so sending a total twice would bill it twice. The caller's job is to send
     * only what it has not sent before, which is what `usage_meters.reported` records.
     *
     * `$idempotencyKey` is sent as the meter event's identifier, which Stripe dedupes on. It must
     * be **deterministic for a given set of units** — the same across every retry of one report,
     * and different for the next one — or a retried call is summed rather than discarded. It is the
     * only thing standing between a timed-out request and a double charge.
     *
     * @throws BillingException `billing.overage_refused` when Stripe definitely did not record it,
     *                          and `billing.gateway_failed` when the outcome is unknown. The
     *                          difference decides whether the caller may safely retry.
     */
    public function reportOverage(Team $team, string $stripePrice, int $quantity, string $idempotencyKey): void;

    /**
     * The team's invoices, newest first. Empty for a team that has never been charged.
     *
     * @return list<InvoiceData>
     *
     * @throws BillingException
     */
    public function invoices(Team $team): array;
}
