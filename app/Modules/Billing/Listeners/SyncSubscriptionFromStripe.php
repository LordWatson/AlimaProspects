<?php

declare(strict_types=1);

namespace App\Modules\Billing\Listeners;

use App\Modules\Billing\Contracts\StripeSwitch;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
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
        // Phase 19b: the event that tells a team their card was declined. Added to the allow-list
        // because it is the only place the app learns of it — Stripe's own dunning email goes to
        // the customer and tells us nothing.
        'invoice.payment_failed',
    ];

    public function __construct(
        private readonly PlanResolver $plans,
        private readonly UsageLedger $ledger,
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
        private readonly StripeSwitch $stripe,
        private readonly NotificationDispatcher $notifications,
    ) {}

    /**
     * @param  array<string, mixed>|null  $invoice
     */
    private function notifyPaymentFailed(Team $team, ?array $invoice): void
    {
        $attempts = is_numeric($invoice['attempt_count'] ?? null) ? (int) $invoice['attempt_count'] : 1;

        $this->teamContext->run($team, function () use ($team, $attempts): void {
            // `toAdmins`, not `toTeam`: it is money, and a Member can neither act on it nor be
            // expected to care. The type's own `isAdminOnly()` says so too — this is the dispatcher
            // method that matches.
            $this->notifications->toAdmins($team, NotificationType::PaymentFailed, [
                'attempts' => $attempts,
            ]);
        });
    }

    public function handle(WebhookHandled $event): void
    {
        // **The actual protection behind the Stripe kill switch** (Phase 19b). The middleware that
        // 404s the webhook route is defence in depth; this is the thing a webhook changes, so this
        // is where “switched off” has to mean something. A webhook that slipped through — a
        // misconfigured proxy, a route cached before the switch flipped — must not quietly change a
        // team's entitlements while an operator believes Stripe is not being listened to.
        //
        // Returning rather than throwing: Stripe reads a 2xx as delivered and a 5xx as a retry, and
        // a retry is what we want. Cashier has already answered 200 by the time this runs, so the
        // honest move is to leave the event unprocessed and let the operator re-sync when they
        // switch Stripe back on.
        if (! $this->stripe->isEnabled()) {
            Log::channel('billing')->warning('Ignored a Stripe webhook: Stripe is switched off.', [
                'type' => is_string($event->payload['type'] ?? null) ? $event->payload['type'] : null,
            ]);

            return;
        }

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

        // **Tell the Owners their payment failed** (Phase 19b) — the gap `billing-plans.md`
        // recorded. Stripe's dunning email was the only signal, so a team whose card had expired
        // heard it from a third party or not at all.
        //
        // Raised on the invoice event rather than on the subscription becoming `past_due`, because
        // Stripe sends one `invoice.payment_failed` per retry: the notification therefore arrives
        // once per attempt, with the attempt count, rather than once when the status first changes
        // and then never again through a fortnight of retries.
        if ($type === 'invoice.payment_failed') {
            $this->notifyPaymentFailed($team, $data);
        }

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
