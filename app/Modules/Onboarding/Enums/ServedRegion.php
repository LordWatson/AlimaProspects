<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Enums;

use App\Modules\Core\Enums\Market;

/**
 * Regions a business sells into. A superset of the trading {@see Market}
 * markets plus `Other`, because a UK/UAE-based team may also serve customers elsewhere. Stored as
 * a list on the Business Profile; the single primary {@see Market} drives
 * compliance defaults.
 */
enum ServedRegion: string
{
    case Uk = 'uk';
    case Uae = 'uae';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Uk => 'United Kingdom',
            self::Uae => 'United Arab Emirates',
            self::Other => 'Elsewhere',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $region): string => $region->value, self::cases());
    }
}
