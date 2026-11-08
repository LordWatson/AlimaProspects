<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Actions\StartTopUpCheckoutAction;
use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\LedgerDirection;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookReceived;

/**
 * Turns a paid Checkout session into allowance (PL4).
 *
 * The other half of {@see StartTopUpCheckoutAction}: that one takes
 * nobody's word for a payment, and this is where the payment actually arrives.
 * `checkout.session.completed` with `payment_status = paid` is the only thing in the system that
 * grants a top-up.
 *
 * **Idempotent on the Stripe event id.** Stripe redelivers — a timeout, a 500, an operator
 * replaying an event from the dashboard — and `usage_ledger.idempotency_key` is unique, so a second
 * delivery of the same event grants nothing. The check and the uniqueness are both needed: the
 * check so the redelivery is a quiet no-op rather than a constraint violation Stripe reads as a 500
 * and retries for three days, and the constraint because the check alone loses a race.
 *
 * Keyed on the **event** id rather than the session id, deliberately. They are one-to-one here, and
 * the event id is the thing Stripe's own idempotency is expressed in — so an event replayed from
 * the dashboard is recognised, where a key built from the session would only catch a redelivery.
 *
 * A sibling of {@see SyncSubscriptionFromStripe} rather than a branch inside it: that one re-reads
 * a subscription and this one writes a ledger row, they share no code beyond finding the team, and
 * one listener doing both would be one listener where a failure in either half loses the other.
 *
 * **`WebhookReceived`, not `WebhookHandled`** — and the difference is not cosmetic. Cashier fires
 * `WebhookHandled` only for the event types it has a handler method for, and
 * `checkout.session.completed` is not one of them, so a listener on that event would never run at
 * all: a paid top-up granting nothing, silently, with a 200 going back to Stripe. The sibling
 * listener can use `WebhookHandled` because every event it acts on *is* one Cashier handles, and it
 * needs Cashier to have synced the subscription row before it re-reads the plan.
 */
final class GrantTopUpFromStripe
{
    public function __construct(
        private readonly UsageAllowance $allowance,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly StripeSwitch $stripe,
    ) {}

    public function handle(WebhookReceived $event): void
    {
        $payload = $event->payload;

        if (($payload['type'] ?? null) !== 'checkout.session.completed') {
            return;
        }

        // Same reasoning as the sibling listener: a webhook that slipped through while Stripe is
        // switched off must not change a team's entitlements. Returning rather than throwing —
        // Cashier has already answered 200, so the honest move is to leave it unprocessed.
        if (! $this->stripe->isEnabled()) {
            Log::channel('billing')->warning('Ignored a Stripe top-up webhook: Stripe is switched off.');

            return;
        }

        $session = $payload['data']['object'] ?? null;

        if (! is_array($session)) {
            return;
        }

        // A session can complete unpaid — an asynchronous payment method still clearing. Allowance
        // follows the money, so an unpaid session grants nothing and the later
        // `checkout.session.async_payment_succeeded` is deliberately not listened for: we sell to
        // cards, and acting on a method we do not accept would be a grant against a payment that
        // may still fail.
        if (($session['payment_status'] ?? null) !== 'paid') {
            return;
        }

        $metadata = is_array($session['metadata'] ?? null) ? $session['metadata'] : [];
        $metric = BillingMetric::tryFrom((string) ($metadata['alima_topup_metric'] ?? ''));
        $quantity = (int) ($metadata['alima_topup_quantity'] ?? 0);

        // Not one of ours: a subscription checkout, or a session created by something else against
        // the same Stripe account. Nothing to say about it.
        if ($metric === null || $quantity < 1) {
            return;
        }

        $eventId = (string) ($payload['id'] ?? '');

        if ($eventId === '') {
            // Without an id there is no idempotency, and a redelivery would grant twice. Refusing
            // is the safe direction: the units are recoverable by hand, a double grant is not.
            Log::channel('billing')->error('Stripe top-up webhook had no event id; not granted.', [
                'metric' => $metric->value,
            ]);

            return;
        }

        $customer = $session['customer'] ?? null;
        $team = is_string($customer) && $customer !== ''
            ? Team::query()->where('stripe_id', $customer)->first()
            : null;

        if (! $team instanceof Team) {
            Log::channel('billing')->info('Stripe top-up for an unknown customer; ignored.', [
                'stripe_customer' => is_string($customer) ? $customer : null,
            ]);

            return;
        }

        // One transaction around the grant **and** its audit entry: without it a failure writing
        // the audit row leaves the units granted, and Stripe's retry then reads "already granted"
        // and writes no audit row either — a change to what a team may spend with no record of it.
        $this->teamContext->run($team, fn () => DB::transaction(function () use ($team, $metric, $quantity, $eventId): void {
            $granted = $this->allowance->grant(
                $team,
                $metric,
                $quantity,
                LedgerDirection::TopUp,
                idempotencyKey: 'stripe:'.$eventId,
                meta: ['stripe_event' => $eventId],
            );

            if (! $granted) {
                Log::channel('billing')->info('Stripe top-up already granted; redelivery ignored.', [
                    'team_id' => $team->getKey(),
                    'metric' => $metric->value,
                ]);

                return;
            }

            // Audited because it changes what a team may spend with nobody in the app doing it —
            // the same reason the subscription sync is audited.
            $this->audit->log('billing.topup_granted', $team, [
                'metric' => $metric->value,
                'quantity' => $quantity,
            ]);
        }));
    }
}
