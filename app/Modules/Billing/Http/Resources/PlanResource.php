<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Data\PlanData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One plan, as the pricing cards render it.
 *
 * Money stays in **minor units** all the way to the browser, with the currency beside it, and is
 * formatted there by the shared `useFormat` composable. Formatting here would bake the server's
 * locale into a bilingual product and produce "£49.00" on an Arabic page.
 *
 * @mixin PlanData
 */
final class PlanResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PlanData $plan */
        $plan = $this->resource;

        return [
            'key' => $plan->key->value,
            'name' => $plan->name,
            'blurb' => $plan->blurb,
            // Null means price on application, which the card renders as "Talk to us" rather than
            // as a missing number.
            'amount' => $plan->amount,
            'currency' => $plan->currency,
            'seats' => $plan->seats,
            'leads' => $plan->leads,
            'credits' => $plan->credits,
            'overage' => $plan->overage,
            'features' => $plan->features,
            'purchasable' => $plan->isPurchasable(),
        ];
    }
}
