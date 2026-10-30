<?php

declare(strict_types=1);

namespace App\Modules\Billing\Testing;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Billing\Services\PlanResolver;
use App\Modules\Core\Models\Team;
use Laravel\Cashier\Subscription;
use Throwable;

/**
 * Stripe, faked. Records what it was asked to do instead of doing it, so the whole of Billing can
 * be tested without a network — the phase's acceptance criterion, and the only way the suite stays
 * green on a laptop with no Stripe keys.
 *
 * Bound over {@see BillingGateway} in the test suite (see `tests/Pest.php`). It is deliberately not
 * a mock: the assertions tests actually want are "was a checkout started for the Scale plan" and
 * "was this overage reported exactly once", which read far better against recorded calls than
 * against expectation chains.
 *
 * Mirrors `LeadSourcing\Testing\FakeLeadSourceProvider` and `AiPlatform\Testing\FakeAiTransport`.
 */
final class FakeBillingGateway implements BillingGateway
{
    /**
     * Every call made, in order: `['operation' => ..., 'team_id' => ..., ...]`.
     *
     * @var list<array<string, mixed>>
     */
    public array $calls = [];

    /**
     * Invoices {@see self::invoices()} will return.
     *
     * @var list<InvoiceData>
     */
    public array $invoices = [];

    /**
     * When set, every method throws this instead of recording — for testing the failure path.
     */
    public ?Throwable $failWith = null;

    /**
     * When set, a usage report is **accepted and then** throws this — the nastiest real failure
     * there is: Stripe recorded the event and the response was lost on the way back. The only
     * defence is the identifier, so this is how a test proves there is one.
     */
    public ?Throwable $failAfterAcceptingOverage = null;

    /**
     * Meter-event identifiers already seen, which Stripe dedupes on.
     *
     * @var list<string>
     */
    public array $seenIdempotencyKeys = [];

    public function checkoutUrl(Team $team, PlanData $plan, string $successUrl, string $cancelUrl): string
    {
        $this->record('checkout', $team, [
            'plan' => $plan->key->value,
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
        ]);

        if (! $plan->isPurchasable()) {
            throw BillingException::planNotPurchasable($plan->key->value);
        }

        return 'https://checkout.stripe.test/session/'.$plan->key->value;
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        $this->record('portal', $team, ['return_url' => $returnUrl]);

        return 'https://billing.stripe.test/portal/'.$team->getKey();
    }

    /**
     * Records the swap **and moves the subscription row**, because Cashier's `swap()` does.
     *
     * A fake that only recorded the call would leave the team on its old price, and then nothing
     * downstream of a swap could be tested at all — the usage snapshot the Action takes immediately
     * afterwards would look correct while reading the plan the team had just left.
     */
    public function swapPlan(Team $team, PlanData $plan): void
    {
        $this->record('swap', $team, ['plan' => $plan->key->value]);

        $this->subscriptionOf($team)?->forceFill(['stripe_price' => $plan->stripePrice])->save();
    }

    /**
     * Cancels the way Cashier does: at period end, so the team keeps working until the date it
     * paid for and `SubscriptionState::GracePeriod` is what the screen shows.
     */
    public function cancel(Team $team): void
    {
        $this->record('cancel', $team);

        $this->subscriptionOf($team)?->forceFill(['ends_at' => now()->addMonth()])->save();
    }

    public function resume(Team $team): void
    {
        $this->record('resume', $team);

        $this->subscriptionOf($team)?->forceFill(['ends_at' => null])->save();
    }

    public function reportOverage(Team $team, string $stripePrice, int $quantity, string $idempotencyKey): void
    {
        // Recorded like Stripe would treat it: an identifier already seen is **discarded**, not
        // summed. Without that the fake would happily accept a retried report and no test could
        // tell a working idempotency key from a broken one.
        if (in_array($idempotencyKey, $this->seenIdempotencyKeys, strict: true)) {
            $this->record('report_overage_deduped', $team, [
                'stripe_price' => $stripePrice,
                'quantity' => $quantity,
                'idempotency_key' => $idempotencyKey,
            ]);

            return;
        }

        $this->record('report_overage', $team, [
            'stripe_price' => $stripePrice,
            'quantity' => $quantity,
            'idempotency_key' => $idempotencyKey,
        ]);

        $this->seenIdempotencyKeys[] = $idempotencyKey;

        if ($this->failAfterAcceptingOverage instanceof Throwable) {
            throw $this->failAfterAcceptingOverage;
        }
    }

    public function invoices(Team $team): array
    {
        $this->record('invoices', $team);

        return $this->invoices;
    }

    /**
     * Calls of one kind, in order.
     *
     * @return list<array<string, mixed>>
     */
    public function callsOf(string $operation): array
    {
        return array_values(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['operation'] === $operation,
        ));
    }

    /**
     * The total quantity reported against one overage price — what an idempotency test asserts on.
     */
    public function reportedQuantityFor(string $stripePrice): int
    {
        return array_sum(array_map(
            static fn (array $call): int => (int) $call['quantity'],
            array_filter(
                $this->callsOf('report_overage'),
                static fn (array $call): bool => $call['stripe_price'] === $stripePrice,
            ),
        ));
    }

    /**
     * The team's subscription, read the way {@see PlanResolver::subscriptionFor()} reads it — by
     * query, so this never hands back a row the instance cached before the last change.
     */
    private function subscriptionOf(Team $team): ?Subscription
    {
        return app(PlanResolver::class)->subscriptionFor($team);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function record(string $operation, Team $team, array $context = []): void
    {
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }

        $this->calls[] = ['operation' => $operation, 'team_id' => (int) $team->getKey()] + $context;
    }
}
