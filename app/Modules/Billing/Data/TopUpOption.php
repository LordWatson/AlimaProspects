<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\TopUpCatalogue;
use Spatie\LaravelData\Data;

/**
 * One thing a team can buy outright: so many units of one metric, for a price (PL4).
 *
 * Read from `config('billing.topups')` by {@see TopUpCatalogue} and
 * never persisted — like {@see PlanData}, and for the same reason: what a top-up costs is a price
 * list, and a row holding a copy of it is the copy that is wrong after the price changes.
 *
 * `index` is the option's position in its metric's list, and it is what the browser sends back. The
 * client never names `stripePrice`: a request that could name a price could name a cheaper one.
 */
final class TopUpOption extends Data
{
    public function __construct(
        public readonly BillingMetric $metric,
        public readonly int $index,
        public readonly int $quantity,
        /** Price in minor units of the billing currency. */
        public readonly int $amount,
        public readonly string $stripePrice,
    ) {}
}
