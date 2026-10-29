<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * Currencies the product understands. Monetary amounts are always stored as integer minor units
 * (CLAUDE.md money rule); this enum names the currency and knows how many minor units make one
 * major unit, so display code can format consistently.
 */
enum Currency: string
{
    case Gbp = 'gbp';
    case Aed = 'aed';
    case Usd = 'usd';
    case Eur = 'eur';

    public function label(): string
    {
        return match ($this) {
            self::Gbp => 'British Pound (£)',
            self::Aed => 'UAE Dirham (د.إ)',
            self::Usd => 'US Dollar ($)',
            self::Eur => 'Euro (€)',
        };
    }

    /**
     * ISO 4217 code, as the Intl formatters on the front end expect.
     */
    public function code(): string
    {
        return strtoupper($this->value);
    }

    /**
     * Minor units per major unit (all supported currencies use 2 decimal places).
     */
    public function minorUnitFactor(): int
    {
        return 100;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $currency): string => $currency->value, self::cases());
    }
}
