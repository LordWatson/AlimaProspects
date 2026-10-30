<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\PlanData;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Exceptions\BillingException;

/**
 * The price list, read from `config('billing.plans')`.
 *
 * Registered as a singleton: the config is read once per request and the resulting value objects
 * are shared, because {@see PlanResolver} asks for a plan on every guard check and parsing the
 * same array each time would be a measurable cost on a hot path for no benefit.
 *
 * A plan missing from config is a deployment error, not a user error — {@see self::get()} throws
 * rather than inventing a default, so a mistyped key fails loudly at the first request instead of
 * quietly entitling somebody to nothing.
 */
final class PlanRegistry
{
    /**
     * @var array<string, PlanData>|null
     */
    private ?array $plans = null;

    /**
     * Every configured plan, cheapest first.
     *
     * @return list<PlanData>
     */
    public function all(): array
    {
        return array_values($this->plans());
    }

    /**
     * The plans a team can buy from the pricing screen. Free has nothing to buy and Enterprise is
     * sold by a person, so neither appears.
     *
     * @return list<PlanData>
     */
    public function purchasable(): array
    {
        return array_values(array_filter($this->all(), static fn (PlanData $plan): bool => $plan->isPurchasable()));
    }

    /**
     * @throws BillingException when the plan isn't in the price list.
     */
    public function get(PlanKey $key): PlanData
    {
        $plan = $this->plans()[$key->value] ?? null;

        if (! $plan instanceof PlanData) {
            throw BillingException::unknownPlan($key->value);
        }

        return $plan;
    }

    /**
     * Resolve a plan from a string that came in over the wire (a form field, a route parameter).
     *
     * @throws BillingException when the string names no plan.
     */
    public function fromKey(string $key): PlanData
    {
        $planKey = PlanKey::tryFrom($key);

        if (! $planKey instanceof PlanKey) {
            throw BillingException::unknownPlan($key);
        }

        return $this->get($planKey);
    }

    /**
     * The plan a Stripe price id belongs to, or null when the price is one we don't sell any more.
     *
     * Null is a real answer, not a failure: a team grandfathered onto a withdrawn price still has a
     * live subscription, and throwing here would lock them out of the screen that lets them move.
     */
    public function fromStripePrice(?string $price): ?PlanData
    {
        if ($price === null || $price === '') {
            return null;
        }

        foreach ($this->plans() as $plan) {
            if ($plan->stripePrice === $price) {
                return $plan;
            }
        }

        return null;
    }

    /**
     * The plan a team falls back to with no subscription and no trial.
     */
    public function default(): PlanData
    {
        return $this->fromKey((string) config('billing.default_plan', PlanKey::Free->value));
    }

    /**
     * The plan whose entitlements a trialling team gets.
     */
    public function trial(): PlanData
    {
        return $this->fromKey((string) config('billing.trial.plan', PlanKey::Team->value));
    }

    /**
     * Drop the memo, so the next read re-parses config.
     *
     * Exists for one reason and it is worth naming: the registry is a singleton that reads config
     * once, so a test — or anything else — that changes a price at runtime would otherwise be
     * talking to a price list parsed before the change.
     */
    public function flush(): void
    {
        $this->plans = null;
    }

    /**
     * @return array<string, PlanData>
     */
    private function plans(): array
    {
        if ($this->plans !== null) {
            return $this->plans;
        }

        $currency = strtoupper((string) config('billing.currency', 'gbp'));
        $configured = (array) config('billing.plans', []);
        $plans = [];

        // Iterate the enum rather than the config array, so the order on the pricing page is the
        // enum's (cheapest first) however the config happens to be written, and an unknown key in
        // config is ignored instead of rendering as a plan nothing in the code knows about.
        foreach (PlanKey::cases() as $key) {
            $config = $configured[$key->value] ?? null;

            if (! is_array($config)) {
                continue;
            }

            $plans[$key->value] = PlanData::fromConfig($key, $config, $currency);
        }

        return $this->plans = $plans;
    }
}
