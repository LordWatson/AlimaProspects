<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Models\Team;

/**
 * The trading markets Alima supports (ADR 0007). A team's primary market drives regional defaults
 * — locale/timezone hints, currency, and (later) the compliance regime (UK GDPR/PECR vs UAE
 * PDPL/TDRA). Mirrors the `market` column on {@see Team}; kept as a
 * backed enum so there are no magic strings when onboarding sets the default.
 */
enum Market: string
{
    case Uk = 'uk';
    case Uae = 'uae';

    public function label(): string
    {
        return match ($this) {
            self::Uk => 'United Kingdom',
            self::Uae => 'United Arab Emirates',
        };
    }

    /**
     * The currency that market trades in by default.
     */
    public function defaultCurrency(): Currency
    {
        return match ($this) {
            self::Uk => Currency::Gbp,
            self::Uae => Currency::Aed,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $market): string => $market->value, self::cases());
    }
}
