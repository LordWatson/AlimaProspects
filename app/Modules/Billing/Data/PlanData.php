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
        /**
         * Paid signal searches a month (S16). Read from the plan's own `signals` block rather than
         * from a key beside `leads` and `credits`, because the other seven figures a plan sets for
         * Signals live there too and one of them split out would be the one that drifts.
         */
        public readonly ?int $signalQueries,
        /**
         * The six contact-data allowances (PL4), keyed by {@see BillingMetric} value. Read from the
         * plan's own `contacts` block, for the reason `signalQueries` is read from `signals`.
         *
         * **An absent key means none**, not unlimited — see {@see BillingMetric}'s note. Every one
         * of these costs us money per unit, so there is deliberately no way for a plan to be
         * unlimited in them: an Enterprise contract gets a large number in `config/billing.php`,
         * which is a number somebody chose, where a null would be a vendor bill nobody capped.
         *
         * @var array<string, int>
         */
        public readonly array $contacts,
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
            signalQueries: self::nullableInt(
                is_array($config['signals'] ?? null) ? ($config['signals']['monthly_source_queries'] ?? null) : null,
            ),
            contacts: self::contactsFrom($config['contacts'] ?? null),
            overage: (bool) ($config['overage'] ?? false),
            features: $features,
        );
    }

    /**
     * The plan's `contacts` block, sanitised to a map of metric value => non-negative integer.
     *
     * @return array<string, int>
     */
    private static function contactsFrom(mixed $block): array
    {
        if (! is_array($block)) {
            return [];
        }

        $contacts = [];

        foreach ($block as $meter => $quantity) {
            if (is_string($meter) && is_numeric($quantity)) {
                $contacts[$meter] = max(0, (int) $quantity);
            }
        }

        return $contacts;
    }

    /**
     * The same plan with one or more allowances replaced by a grant's (Phase 19b).
     *
     * This is how Enterprise finally gets real numbers. The price list gives that plan `null`
     * seats, leads and credits, which this class reads as *unlimited* — but a signed contract says
     * 25 seats and 40,000 leads. A null argument here therefore means **leave the plan's own
     * value**, not "unlimited"; the two readings of null are different and keeping them apart is
     * the whole reason this is a method rather than a constructor flag.
     */
    public function withAllowances(?int $seats = null, ?int $leads = null, ?int $credits = null): self
    {
        if ($seats === null && $leads === null && $credits === null) {
            return $this;
        }

        return new self(
            key: $this->key,
            name: $this->name,
            blurb: $this->blurb,
            amount: $this->amount,
            currency: $this->currency,
            stripePrice: $this->stripePrice,
            seats: $seats ?? $this->seats,
            leads: $leads ?? $this->leads,
            credits: $credits ?? $this->credits,
            // **Not grantable, deliberately**: `plan_grants` has a column per allowance and signal
            // searches has none, so there is nothing to read. A bespoke signal allowance is a line
            // in `config/billing.php` under an Enterprise plan today; adding a column here without
            // one to read would be a method that silently did nothing.
            signalQueries: $this->signalQueries,
            // Not grantable either, and for the same reason: `plan_grants` has no column for them.
            contacts: $this->contacts,
            overage: $this->overage,
            features: $this->features,
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
            BillingMetric::SignalQueries => $this->signalQueries,
            // The six ledgered metrics (PL4), from the plan's `contacts` block, keyed by the
            // metric's own value. Listed case by case rather than behind a `default`, so a tenth
            // metric is a PHPStan error here instead of an allowance quietly read as *none*.
            BillingMetric::PeopleSearch,
            BillingMetric::EmailReveal,
            BillingMetric::PhoneReveal,
            BillingMetric::EmailVerification,
            BillingMetric::ExportRows,
            BillingMetric::CrmSync => $this->contacts[$metric->value] ?? null,
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
