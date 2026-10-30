<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Data\Entitlements;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The state of a team's subscription, as the billing screen's header renders it.
 *
 * Note what is **not** here: no Stripe customer id, no price id, no payment-method details. The
 * screen never needs them, and an identifier that reaches the browser is an identifier in a support
 * screenshot.
 *
 * @mixin Entitlements
 */
final class SubscriptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Entitlements $entitlements */
        $entitlements = $this->resource;

        return [
            'plan' => PlanResource::make($entitlements->plan)->resolve($request),
            'state' => $entitlements->state->value,
            'state_label' => $entitlements->state->label(),
            'needs_attention' => $entitlements->state->needsAttention(),
            'is_subscribed' => $entitlements->isSubscribed(),
            'is_trialing' => $entitlements->isTrialing(),
            'trial_ends_at' => $entitlements->trialEndsAt?->toIso8601String(),
            'trial_days_left' => $entitlements->trialDaysLeft(),
            'ends_at' => $entitlements->endsAt?->toIso8601String(),
        ];
    }
}
