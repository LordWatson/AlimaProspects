<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Support;

use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;
use App\Modules\Core\Enums\Currency;
use App\Modules\Onboarding\Models\BusinessProfile;

/**
 * How a background check's findings map onto the team's Business Profile — the one definition the
 * review screen previews and the accept Action applies, so what a member is shown is what happens.
 *
 * Three states, and the rule behind them (ADR 0036): **the team's own entries win.**
 *
 * - `fill` — the profile field is blank. Accepting writes the finding.
 * - `same` — the profile already says it. Nothing to do.
 * - `conflict` — the team entered something else. It is **shown, never overwritten**: the finding
 *   replaces the team's value only for a field the member explicitly ticks.
 *
 * `customer_examples` is the one field that merges rather than replaces: a customer the site names
 * is added to the ones the team typed, and since that takes nothing away it is a `fill`, never a
 * `conflict`. A price in a different currency from the team's is always a `conflict`.
 */
final class ProfileMapping
{
    public const string FILL = 'fill';

    public const string SAME = 'same';

    public const string CONFLICT = 'conflict';

    /**
     * Every profile field a finding can reach, in the order the review screen lists them.
     *
     * @return list<string>
     */
    public static function fields(): array
    {
        return [
            'industry', 'value_proposition', 'customer_examples', 'customer_industries', 'target_customers',
            'buyer_titles', 'competitors', 'problems_solved', 'buying_triggers', 'anti_customers',
            'pricing', 'delivery_model', 'customer_size_range',
        ];
    }

    /**
     * One row per field the check has something to say about.
     *
     * @return list<array{field: string, current: ?string, proposed: string, state: string}>
     */
    public function rows(BusinessProfile $profile, BackgroundCheckFindings $findings): array
    {
        $rows = [];

        foreach ($this->proposed($profile, $findings) as $field => $proposed) {
            $current = $this->current($profile, $field);

            if ($this->blank($proposed)) {
                continue;
            }

            $rows[] = [
                'field' => $field,
                'current' => $this->blank($current) ? null : $this->display($current, $profile->pricing_currency),
                'proposed' => $this->display($proposed, $findings->pricingCurrency ?? $profile->pricing_currency),
                'state' => match (true) {
                    // A price in another currency is a different price, whatever the numbers say.
                    $field === 'pricing' && $this->currenciesDiffer($profile, $findings) => self::CONFLICT,
                    $this->blank($current) => self::FILL,
                    $this->normalise($current) === $this->normalise($proposed) => self::SAME,
                    // Adding customers the site names to the ones the team typed takes nothing
                    // away, so there is nothing to ask about.
                    $field === 'customer_examples' => self::FILL,
                    default => self::CONFLICT,
                },
            ];
        }

        return $rows;
    }

    /**
     * Write the findings onto the profile: every `fill`, and each `conflict` the member chose.
     *
     * @param  list<string>  $overrides  fields where the member said "use Alima's instead"
     * @return list<string> the fields that were written
     */
    public function apply(BusinessProfile $profile, BackgroundCheckFindings $findings, array $overrides): array
    {
        $proposed = $this->proposed($profile, $findings);
        $written = [];

        foreach ($this->rows($profile, $findings) as $row) {
            $field = $row['field'];

            if ($row['state'] === self::SAME || ($row['state'] === self::CONFLICT && ! in_array($field, $overrides, true))) {
                continue;
            }

            if ($field === 'pricing') {
                /** @var array{min: ?int, max: ?int} $band */
                $band = $proposed['pricing'];
                $profile->forceFill([
                    'pricing_min' => $band['min'],
                    'pricing_max' => $band['max'],
                    'pricing_currency' => $findings->pricingCurrency ?? $profile->pricing_currency,
                ]);
            } else {
                $profile->forceFill([$field => $proposed[$field]]);
            }

            $written[] = $field;
        }

        if ($written !== []) {
            $profile->save();
        }

        return $written;
    }

    /**
     * What the check proposes for each field, in the field's own type.
     *
     * @return array<string, mixed>
     */
    private function proposed(BusinessProfile $profile, BackgroundCheckFindings $findings): array
    {
        return [
            // The team's OWN trade. Never `customerIndustries` — that is who buys from them.
            'industry' => $findings->ownIndustry,
            'value_proposition' => $findings->offering,
            // Merged, never replaced: the customers the site names, added to the ones the team typed.
            'customer_examples' => $findings->proofCustomers === []
                ? []
                : $this->union($profile->customer_examples ?? [], $findings->proofCustomers),
            'customer_industries' => $findings->customerIndustries,
            'target_customers' => $findings->targetCustomers,
            'buyer_titles' => $findings->decisionMakerTitles,
            'competitors' => $findings->competitors,
            'problems_solved' => $findings->problemsSolved,
            'buying_triggers' => $findings->buyingTriggers,
            'anti_customers' => $findings->antiCustomers,
            // A band needs a currency to mean anything: the check's, else the one the team uses.
            'pricing' => ($findings->pricingCurrency ?? $profile->pricing_currency) === null
                ? null
                : ['min' => $findings->pricingMin, 'max' => $findings->pricingMax],
            'delivery_model' => $findings->deliveryModel,
            'customer_size_range' => $findings->customerSizeRange,
        ];
    }

    private function currenciesDiffer(BusinessProfile $profile, BackgroundCheckFindings $findings): bool
    {
        return $profile->pricing_currency !== null
            && $findings->pricingCurrency !== null
            && $profile->pricing_currency !== $findings->pricingCurrency;
    }

    private function current(BusinessProfile $profile, string $field): mixed
    {
        return $field === 'pricing'
            ? ['min' => $profile->pricing_min, 'max' => $profile->pricing_max]
            : $profile->getAttribute($field);
    }

    private function blank(mixed $value): bool
    {
        return match (true) {
            is_array($value) => array_filter($value, static fn (mixed $v): bool => $v !== null && $v !== '') === [],
            default => $value === null || $value === '',
        };
    }

    /**
     * For comparison only: case- and order-insensitive, so "SaaS, Retail" and "retail, saas" agree.
     */
    private function normalise(mixed $value): mixed
    {
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        if (is_array($value) && array_is_list($value)) {
            $items = array_map(static fn (mixed $v): string => mb_strtolower(trim((string) $v)), $value);
            sort($items);

            return $items;
        }

        return is_string($value) ? mb_strtolower(trim($value)) : $value;
    }

    private function display(mixed $value, ?Currency $currency): string
    {
        if ($value instanceof \BackedEnum) {
            return method_exists($value, 'label') ? (string) $value->label() : (string) $value->value;
        }

        if (is_array($value) && array_is_list($value)) {
            return implode(', ', array_map(strval(...), $value));
        }

        if (is_array($value)) {
            $major = static fn (?int $minor): ?string => $minor === null || $currency === null
                ? null
                : number_format($minor / $currency->minorUnitFactor());
            $code = $currency?->code() ?? '';

            return trim(match (true) {
                ($value['min'] ?? null) === null && ($value['max'] ?? null) === null => '',
                ($value['min'] ?? null) === null => 'up to '.$major($value['max'] ?? null),
                ($value['max'] ?? null) === null => 'from '.$major($value['min']),
                default => $major($value['min']).' to '.$major($value['max']),
            }.' '.$code);
        }

        return (string) $value;
    }

    /**
     * @param  list<string>  $existing
     * @param  list<string>  $found
     * @return list<string>
     */
    private function union(array $existing, array $found): array
    {
        $seen = [];
        $merged = [];

        foreach ([...$existing, ...$found] as $name) {
            $key = mb_strtolower(trim($name));

            if ($key !== '' && ! isset($seen[$key])) {
                $seen[$key] = true;
                $merged[] = $name;
            }
        }

        return $merged;
    }
}
