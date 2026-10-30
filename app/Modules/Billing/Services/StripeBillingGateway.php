<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Invoice;
use Laravel\Cashier\Subscription;
use Laravel\Cashier\SubscriptionItem;
use Stripe\Exception\ApiConnectionException;
use Stripe\Exception\ApiErrorException;
use Throwable;

/**
 * The live gateway: Cashier and Stripe.
 *
 * Every method here is an external call, so none of them may be used inside a transaction
 * (CLAUDE.md §2) — the Actions that call them do their database work before or after, never
 * around. Every one of them also catches {@see Throwable} and rethrows a
 * {@see BillingException}: Stripe's own messages are written for developers, change without
 * notice, and occasionally name the merchant account, so the detail goes to the `billing` log
 * channel and the member gets a sentence they can act on.
 */
final class StripeBillingGateway implements BillingGateway
{
    public function __construct(private readonly PlanResolver $plans) {}

    public function checkoutUrl(Team $team, PlanData $plan, string $successUrl, string $cancelUrl): string
    {
        if (! $plan->isPurchasable() || $plan->stripePrice === null) {
            throw BillingException::planNotPurchasable($plan->key->value);
        }

        return $this->attempt($team, 'checkout', function () use ($team, $plan, $successUrl, $cancelUrl): string {
            $builder = $team->newSubscription(PlanResolver::TYPE, $this->pricesFor($plan));

            // Carry any remaining card-less trial onto the subscription, so subscribing on day
            // three of a fortnight's trial doesn't throw away the other eleven days. A team that
            // upgrades early should not be worse off than one that waits.
            if ($team->onGenericTrial() && $team->trial_ends_at !== null) {
                $builder = $builder->trialUntil($team->trial_ends_at);
            }

            $checkout = $builder->checkout([
                'success_url' => $successUrl,
                'cancel_url' => $cancelUrl,
            ]);

            return (string) $checkout->asStripeCheckoutSession()->url;
        });
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        return $this->attempt(
            $team,
            'portal',
            static fn (): string => $team->billingPortalUrl($returnUrl),
        );
    }

    public function swapPlan(Team $team, PlanData $plan): void
    {
        if (! $plan->isPurchasable() || $plan->stripePrice === null) {
            throw BillingException::planNotPurchasable($plan->key->value);
        }

        $subscription = $this->subscriptionOrFail($team);

        $this->attempt($team, 'swap', function () use ($subscription, $plan): null {
            $subscription->swap($this->pricesFor($plan));

            return null;
        });
    }

    public function cancel(Team $team): void
    {
        $subscription = $this->subscriptionOrFail($team);

        $this->attempt($team, 'cancel', static function () use ($subscription): null {
            // At period end, not immediately: the team has paid for the rest of the month and
            // taking it away the moment they click cancel is a refund we are not giving them.
            $subscription->cancel();

            return null;
        });
    }

    public function resume(Team $team): void
    {
        $subscription = $this->subscriptionOrFail($team);

        $this->attempt($team, 'resume', static function () use ($subscription): null {
            $subscription->resume();

            return null;
        });
    }

    /**
     * Send one meter event, with our own identifier.
     *
     * **Not** `Subscription::reportUsageFor()`, which is otherwise exactly right: it stamps every
     * event with a fresh `Str::uuid()`, so a request that times out after Stripe has recorded the
     * event becomes a second, distinct event on the retry — and the customer is billed twice for
     * units they used once. The identifier is the only thing that prevents that, and it has to come
     * from the caller, who is the only one that knows which units these are.
     *
     * The call itself mirrors Cashier's: the v2 Billing meter-events API, keyed on the meter's
     * event name. Cashier caches that name on the `subscription_items` row when the subscription is
     * created, so the lookup below is normally free; when it is missing we resolve it the same way
     * Cashier does and cache it the same way, so this stays a drop-in.
     */
    public function reportOverage(Team $team, string $stripePrice, int $quantity, string $idempotencyKey): void
    {
        if ($quantity <= 0) {
            return;
        }

        $subscription = $this->subscriptionOrFail($team);

        $this->attempt($team, 'report_overage', function () use ($team, $subscription, $stripePrice, $quantity, $idempotencyKey): null {
            $item = $subscription->findItemOrFail($stripePrice);

            $team->stripe()->v2->billing->meterEvents->create([
                'event_name' => $this->meterEventName($team, $item),
                'payload' => [
                    'stripe_customer_id' => (string) $team->stripe_id,
                    'value' => (string) $quantity,
                ],
                'timestamp' => CarbonImmutable::now('UTC')->format('c'),
                'identifier' => $idempotencyKey,
            ]);

            return null;
        });
    }

    public function invoices(Team $team): array
    {
        if ($team->stripe_id === null) {
            return [];
        }

        return $this->attempt($team, 'invoices', static function () use ($team): array {
            $invoices = $team->invoices()
                ->map(static function (Invoice $invoice): InvoiceData {
                    $stripe = $invoice->asStripeInvoice();

                    return new InvoiceData(
                        id: (string) $stripe->id,
                        number: $stripe->number === null ? null : (string) $stripe->number,
                        date: CarbonImmutable::createFromTimestampUTC($stripe->created),
                        total: (int) $stripe->total,
                        currency: strtoupper((string) $stripe->currency),
                        status: (string) ($stripe->status ?? 'open'),
                        pdfUrl: $stripe->invoice_pdf === null ? null : (string) $stripe->invoice_pdf,
                    );
                })
                ->all();

            // `array_values` rather than the collection's `values()`: the contract promises a list,
            // and a list is what callers index into.
            return array_values($invoices);
        });
    }

    /**
     * The prices one subscription to `$plan` is made of: the plan itself, plus a metered item per
     * overage metric when the plan permits overage.
     *
     * The metered items are attached at subscribe time rather than when the first overage happens,
     * because Stripe only accepts usage against an item that already exists — adding one at the
     * moment a team goes over would mean a write to Stripe in the path of the guard that is trying
     * to decide whether to let them past.
     *
     * @return list<string>
     */
    private function pricesFor(PlanData $plan): array
    {
        $prices = [(string) $plan->stripePrice];

        if (! $plan->overage) {
            return $prices;
        }

        foreach (BillingMetric::cases() as $metric) {
            $price = config('billing.overage.'.$metric->value.'.stripe_price');

            if (is_string($price) && $price !== '') {
                $prices[] = $price;
            }
        }

        return $prices;
    }

    /**
     * The meter event name a price reports against, resolved the way Cashier resolves it and cached
     * on the same column — so the two never disagree about which meter a price belongs to.
     *
     * A price with no meter cannot take usage at all, and that is a misconfiguration of the product
     * rather than something to retry: it is the one case here that will not come right on its own.
     */
    private function meterEventName(Team $team, SubscriptionItem $item): string
    {
        if (is_string($item->meter_event_name) && $item->meter_event_name !== '') {
            return $item->meter_event_name;
        }

        $meterId = $item->meter_id;

        if (! is_string($meterId) || $meterId === '') {
            $price = $team->stripe()->prices->retrieve($item->stripe_price);
            $meterId = $price->recurring->meter ?? null;

            if (! is_string($meterId) || $meterId === '') {
                throw BillingException::overagePriceHasNoMeter($item->stripe_price);
            }
        }

        $eventName = (string) $team->stripe()->billing->meters->retrieve($meterId)->event_name;

        $item->forceFill(['meter_id' => $meterId, 'meter_event_name' => $eventName])->save();

        return $eventName;
    }

    private function subscriptionOrFail(Team $team): Subscription
    {
        // Through the resolver, not Cashier's `subscription()`: that helper reads a lazy, instance-
        // cached relation, which throws under `preventLazyLoading` on a team loaded in a collection
        // and answers with a stale subscription right after a swap. See `PlanResolver::subscriptionFor`.
        $subscription = $this->plans->subscriptionFor($team);

        if (! $subscription instanceof Subscription) {
            throw BillingException::noSubscription();
        }

        return $subscription;
    }

    /**
     * Run one Stripe call, turning any failure into a {@see BillingException} and logging what
     * actually happened.
     *
     * The returned exception says **whether Stripe definitely did not do it**. For most operations
     * that distinction is academic — a failed checkout is a failed checkout — but for a usage
     * report it decides whether the caller may safely send the same units again, so a refusal
     * (a 4xx: Stripe answered, and the answer was no) is reported as `billing.overage_refused`
     * while anything else keeps the honest `billing.gateway_failed`, which means "we do not know".
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $call
     * @return TReturn
     */
    private function attempt(Team $team, string $operation, callable $call): mixed
    {
        try {
            return $call();
        } catch (BillingException $e) {
            throw $e;
        } catch (Throwable $e) {
            $refused = $this->wasRefused($e);

            Log::channel('billing')->error('Stripe call failed.', [
                'team_id' => $team->getKey(),
                'operation' => $operation,
                'exception' => $e::class,
                'refused' => $refused,
                'message' => $e->getMessage(),
            ]);

            throw $operation === 'report_overage' && $refused
                ? BillingException::overageRefused()
                : BillingException::gatewayFailed();
        }
    }

    /**
     * Whether Stripe answered and the answer was no.
     *
     * A 4xx means the request was rejected and nothing was recorded. A connection error, a timeout
     * or a 5xx means the opposite of certainty: the request may well have been processed and the
     * response lost on the way back, which is exactly the case a usage report must not retry.
     */
    private function wasRefused(Throwable $e): bool
    {
        if ($e instanceof ApiConnectionException) {
            return false;
        }

        if (! $e instanceof ApiErrorException) {
            // Not Stripe's at all — a bug of ours, or a serialisation error. Treating it as refused
            // would be a guess about something that never reached Stripe *or* never came back.
            return false;
        }

        $status = $e->getHttpStatus();

        return $status !== null && $status >= 400 && $status < 500;
    }
}
