<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Support;

use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Support\Str;

/**
 * The team's own business, as every prompt that needs to know the **seller** should read it.
 *
 * There were three of these — the ICP composer squashed four fields into 500 characters, the query
 * shaper carried eight, the scorer carried none — and each lost something different: nothing that
 * finds or grades a prospect ever saw the price band or the size of the business. One renderer, so
 * what the product knows about the seller reaches the composer, the shaper, the candidate judge and
 * the scorer in the same words.
 *
 * Every field is stated, `unknown` where blank, so a model cannot quietly fill one in. The text is
 * the team's typing and accepted background-check findings; callers wrap it as untrusted content.
 */
final class SellerBrief
{
    private const int MAX_LIST = 12;

    private const int MAX_VALUE = 300;

    public static function for(?BusinessProfile $profile): ?string
    {
        if (! $profile instanceof BusinessProfile) {
            return null;
        }

        return implode("\n", [
            'Our company: '.self::text($profile->company_name),
            'What we sell: '.self::items($profile->products_services),
            'Why customers choose us: '.self::text($profile->value_proposition),
            'Problems we solve: '.self::items($profile->problems_solved),
            'Our own industry (this is US, not a customer — a company in it is a competitor unless the '
                .'lines below show we sell to our own trade): '.self::text($profile->industry),
            'Our size: '.self::text($profile->company_size?->label()),
            'What a typical job or contract costs: '.self::priceBand($profile),
            'How we deliver: '.self::text($profile->delivery_model?->label()),
            'Where we operate: '.self::items($profile->locations),
            'Regions we serve: '.self::items($profile->regions_served),
            'Customers we already have (the best examples of who to find more of): '.self::items($profile->customer_examples),
            'Industries our customers are in: '.self::items($profile->customer_industries),
            'Kinds of organisation that buy from us: '.self::items($profile->target_customers),
            'How big our customers are: '.self::text($profile->customer_size_range),
            'Who decides at a customer: '.self::items($profile->buyer_titles),
            'What makes a customer ready to buy: '.self::items($profile->buying_triggers),
            'Who is NOT a customer: '.self::items($profile->anti_customers),
            'Named competitors (never prospects): '.self::items($profile->competitors),
        ]);
    }

    /**
     * In major units with the currency code, because "250000" means nothing to a reader and the
     * floor is what tells a prompt that a three-person firm cannot be a customer.
     */
    private static function priceBand(BusinessProfile $profile): string
    {
        $currency = $profile->pricing_currency;

        if ($currency === null || ($profile->pricing_min === null && $profile->pricing_max === null)) {
            return 'unknown';
        }

        $major = static fn (?int $minor): ?string => $minor === null
            ? null
            : number_format($minor / $currency->minorUnitFactor());

        return match (true) {
            $profile->pricing_min === null => 'up to '.$major($profile->pricing_max).' '.$currency->code(),
            $profile->pricing_max === null => 'from '.$major($profile->pricing_min).' '.$currency->code(),
            default => $major($profile->pricing_min).' to '.$major($profile->pricing_max).' '.$currency->code(),
        };
    }

    /**
     * @param  list<string>|null  $values
     */
    private static function items(?array $values): string
    {
        return self::text($values === null ? null : implode(', ', array_slice($values, 0, self::MAX_LIST)));
    }

    private static function text(?string $value): string
    {
        $value = Str::squish((string) $value);

        return $value === '' ? 'unknown' : Str::limit($value, self::MAX_VALUE, '');
    }
}
