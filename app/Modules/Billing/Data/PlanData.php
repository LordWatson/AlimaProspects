<?php

declare(strict_types=1);

namespace App\Modules\Billing\Data;

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanRegistry;
use Spatie\LaravelData\Data;

/**
 * One plan, as read from `config('billing.plans')` by {@see PlanRegistry}. Immutable, and never
 * persisted: the plan a team is on is derived from its Stripe subscription, not stored alongside it,
 * so there is no row to drift out of step with the price list.
 *
 * `null` means **unlimited** for `seats`, `leads` and `credits`, and **price on application** for
 * `amount`. Both readings are deliberate and both are the Enterprise plan's; nothing else uses them.
 */
final class PlanData extends Data
{
    /**
     * @param  list<string>  $features
     */
    public function __construct(
        public readonly PlanKey $key,
        public readonly string $name,
        public readonly string $blurb,
        /** Monthly price in minor units (pence). 0 = free, null = on application. */
        public readonly ?int $amount,
        public readonly string $currency,
        public readonly ?string $stripePrice,
        public readonly ?int $seats,
        public readonly ?int $leads,
        public readonly ?int $credits,
        /** Whether work continues past the allowance and is metered, or simply stops. */
        public readonly bool $overage,
        public readonly array $features,
    ) {}

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(PlanKey $key, array $config, string $currency): self
    {
        /** @var list<string> $features */
        $features = array_values(array_map(strval(...), (array) ($config['features'] ?? [])));

        return new self(
            key: $key,
            name: (string) ($config['name'] ?? $key->value),
            blurb: (string) ($config['blurb'] ?? ''),
            amount: self::nullableInt($config['amount'] ?? null),
            currency: $currency,
            stripePrice: self::nullableString($config['stripe_price'] ?? null),
            seats: self::nullableInt($config['seats'] ?? null),
            leads: self::nullableInt($config['leads'] ?? null),
            credits: self::nullableInt($config['credits'] ?? null),
            overage: (bool) ($config['overage'] ?? false),
            features: $features,
        );
    }

    /**
     * The monthly allowance for one metric, or null when the plan is unlimited in it.
     */
    public function allowanceFor(BillingMetric $metric): ?int
    {
        return match ($metric) {
            BillingMetric::Leads => $this->leads,
            BillingMetric::AiCredits => $this->credits,
        };
    }

    /**
     * The hard ceiling for one metric: the point past which work stops however much overage the
     * plan permits. Null means no ceiling (an unlimited plan).
     *
     * A plan without overage stops at its allowance. One with overage stops at the configured
     * multiple of it — a plan that bills for every extra unit forever is a way to hand somebody an
     * invoice they never agreed to, and the first they would hear of it is the invoice.
     */
    public function ceilingFor(BillingMetric $metric): ?int
    {
        $allowance = $this->allowanceFor($metric);

        if ($allowance === null) {
            return null;
        }

        if (! $this->overage) {
            return $allowance;
        }

        $multiplier = max(1.0, (float) config('billing.overage.multiplier', 2.0));

        return (int) ceil($allowance * $multiplier);
    }

    /**
     * Whether this plan can be bought from the pricing screen without talking to sales.
     */
    public function isPurchasable(): bool
    {
        return $this->key->isSelfServe() && $this->stripePrice !== null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : (string) $value;
    }
}
