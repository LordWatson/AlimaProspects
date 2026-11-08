<?php

declare(strict_types=1);

namespace App\Modules\Billing\Services;

use App\Modules\Billing\Data\TopUpOption;
use App\Modules\Billing\Enums\BillingMetric;

/**
 * What can be bought outright, read from `config('billing.topups')` (PL4).
 *
 * {@see PlanRegistry}'s small sibling, and the same job: turn a config array into typed objects once
 * so that nothing else reads the array. The one rule it enforces is that **an option with no Stripe
 * price does not exist** — a deployment that has not created the prices yet simply has no "Buy
 * more" button, rather than a button that takes somebody to a Checkout session Stripe refuses with
 * their card details already typed in.
 *
 * It also refuses an option on a metric that is not toppable ({@see BillingMetric::isToppable()}),
 * so a config edit cannot quietly start selling export rows — which would be charging a team for
 * their own data on its way out.
 */
final class TopUpCatalogue
{
    /**
     * Every buyable option, keyed by metric value. Metrics with nothing to sell are absent.
     *
     * @return array<string, list<TopUpOption>>
     */
    public function all(): array
    {
        $catalogue = [];

        foreach (BillingMetric::cases() as $metric) {
            $options = $this->for($metric);

            if ($options !== []) {
                $catalogue[$metric->value] = $options;
            }
        }

        return $catalogue;
    }

    /**
     * @return list<TopUpOption>
     */
    public function for(BillingMetric $metric): array
    {
        if (! $metric->isToppable()) {
            return [];
        }

        $configured = config('billing.topups.'.$metric->value, []);

        if (! is_array($configured)) {
            return [];
        }

        $options = [];

        // Indexed by position in the **configured** list, not by position among the valid ones, so
        // an option whose price is missing does not silently renumber the ones after it — a client
        // holding an index from a page rendered a moment earlier would otherwise buy the wrong one.
        foreach (array_values($configured) as $index => $option) {
            $parsed = $this->parse($metric, $index, $option);

            if ($parsed instanceof TopUpOption) {
                $options[] = $parsed;
            }
        }

        return $options;
    }

    /**
     * One option by the index the browser sent back, or null when there is no such thing.
     */
    public function find(BillingMetric $metric, int $index): ?TopUpOption
    {
        foreach ($this->for($metric) as $option) {
            if ($option->index === $index) {
                return $option;
            }
        }

        return null;
    }

    private function parse(BillingMetric $metric, int $index, mixed $option): ?TopUpOption
    {
        if (! is_array($option)) {
            return null;
        }

        $price = $option['stripe_price'] ?? null;
        $quantity = $option['quantity'] ?? null;

        if (! is_string($price) || $price === '' || ! is_numeric($quantity) || (int) $quantity < 1) {
            return null;
        }

        return new TopUpOption(
            metric: $metric,
            index: $index,
            quantity: (int) $quantity,
            amount: is_numeric($option['amount'] ?? null) ? (int) $option['amount'] : 0,
            stripePrice: $price,
        );
    }
}
