<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Contracts\BillingGateway;
use App\Modules\Billing\Data\InvoiceData;
use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Exceptions\BillingException;
use App\Modules\Core\Models\Team;
use Illuminate\Support\Facades\Log;

/**
 * The gateway bound when Stripe is switched off (Phase 19b).
 *
 * Every method that would reach Stripe refuses with `billing.disabled`. The screens are not
 * supposed to reach any of them — `BillingController` sends a `capabilities` payload so a button
 * that would throw is never rendered — so an exception from here means something called a write
 * path without asking, and it is far better for that to be a loud, named refusal than a connection
 * timeout to an API we have deliberately stopped using.
 *
 * `invoices()` is the exception, and returns **an empty list rather than throwing**. It is a read,
 * the billing screen calls it unconditionally, and "we cannot reach Stripe, so we cannot show you
 * your invoices" is a true statement that should render as an empty table with a note rather than
 * taking the page down. Logged, because an empty invoice list is otherwise indistinguishable from a
 * customer who has never been charged.
 */
final class DisabledBillingGateway implements BillingGateway
{
    public function checkoutUrl(Team $team, PlanData $plan, string $successUrl, string $cancelUrl): string
    {
        throw BillingException::stripeDisabled();
    }

    public function portalUrl(Team $team, string $returnUrl): string
    {
        throw BillingException::stripeDisabled();
    }

    public function swapPlan(Team $team, PlanData $plan): void
    {
        throw BillingException::stripeDisabled();
    }

    public function cancel(Team $team): void
    {
        throw BillingException::stripeDisabled();
    }

    public function resume(Team $team): void
    {
        throw BillingException::stripeDisabled();
    }

    public function reportOverage(Team $team, string $stripePrice, int $quantity, string $idempotencyKey): void
    {
        throw BillingException::stripeDisabled();
    }

    /**
     * @return list<InvoiceData>
     */
    public function invoices(Team $team): array
    {
        Log::channel('billing')->info('Invoices not fetched: Stripe is switched off.', [
            'team_id' => $team->getKey(),
        ]);

        return [];
    }
}
