<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Events\WebhookHandled;

/**
 * Finishes the job after a Stripe webhook (Phase 16).
 *
 * **Stripe is the source of truth for subscription state, and this is the only way a lot of it
 * arrives.** A plan changed in the customer portal, a card that expired, a payment that failed its
 * last retry — none of those pass through the app at all, so without a webhook a team's
 * entitlements would stay as they were until somebody happened to click something.
 *
 * Cashier's own webhook controller does the syncing of `subscriptions` and `subscription_items`;
 * this listens for the event it fires **afterwards** and adds the three things Cashier has no
 * reason to know about:
 *
 *  - dropping {@see PlanResolver}'s memo, so nothing answers with the plan the team was on;
 *  - re-snapshotting the usage meters, so `included` reflects the allowance the team now has;
 *  - an audit entry, because a subscription changing with nobody in the app doing it is exactly
 *    the event an Owner later asks us to explain.
 *
 * A listener rather than a subclassed controller, so Cashier keeps its own route, its own signature
 * verification and its own handlers, and this module adds to them instead of reimplementing them.
 *
 * Synchronous on purpose: the work is two queries and a row, Stripe's 20-second timeout is ample,
 * and a queued listener would mean the webhook that says "this team is now on Scale" is still
 * pending when the Owner's browser lands back on the billing screen.
 */
final class SyncSubscriptionFromStripe
{
    /**
     * Events worth re-syncing for: anything that can change what a team is entitled to.
     *
     * An allow-list, because Cashier fires this for every webhook Stripe sends — including
     * `invoice.payment_succeeded` for every invoice of every customer — and re-reading a team's
     * plan on each one would be a handful of queries per event for no change.
     *
     * @var list<string>
     */
    private const array EVENTS = [
        'customer.subscription.created',
        'customer.subscription.updated',
        'customer.subscription.deleted',
        'customer.subscription.paused',
        'customer.subscription.resumed',
    ];

    public function __construct(
        private readonly PlanResolver $plans,
        private readonly UsageLedger $ledger,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(WebhookHandled $event): void
    {
        $payload = $event->payload;
        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';

        if (! in_array($type, self::EVENTS, strict: true)) {
            return;
        }

        $data = $payload['data']['object'] ?? null;
        $customer = is_array($data) ? ($data['customer'] ?? null) : null;

        if (! is_string($customer) || $customer === '') {
            return;
        }

        $team = Team::query()->where('stripe_id', $customer)->first();

        if (! $team instanceof Team) {
            // A test event, or a customer belonging to another environment pointed at this
            // endpoint. Logged and ignored rather than thrown: an exception here becomes a 500,
            // and Stripe retries a 500 for three days.
            Log::channel('billing')->info('Stripe webhook for an unknown customer; ignored.', [
                'stripe_customer' => $customer,
                'event' => $type,
            ]);

            return;
        }

        $this->plans->forget($team);
        $team->unsetRelation('subscriptions');
        $entitlements = $this->plans->for($team);

        $this->teamContext->run($team, function () use ($team, $type, $entitlements, $data): void {
            $this->ledger->sync($team);

            // No actor: nobody in the app did this. The audit row records the source instead, which
            // is what makes "why did my plan change on the 4th" an answerable question.
            $this->audit->log('billing.subscription_synced', $team, [
                'source' => 'stripe_webhook',
                'event' => $type,
                'stripe_status' => is_string($data['status'] ?? null) ? $data['status'] : null,
                'plan' => $entitlements->plan->key->value,
                'state' => $entitlements->state->value,
            ]);
        });

        Log::channel('billing')->info('Subscription synced from Stripe.', [
            'team_id' => $team->getKey(),
            'event' => $type,
            'plan' => $entitlements->plan->key->value,
            'state' => $entitlements->state->value,
        ]);
    }
}
