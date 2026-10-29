<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Enums;

/**
 * Employee-count bands for a business. A band (not an exact headcount) is enough for ICP fit and
 * keeps the question light. Values are stable keys; {@see self::label()} is the display range.
 */
enum CompanySize: string
{
    case Micro = 'micro';
    case Small = 'small';
    case Medium = 'medium';
    case Large = 'large';
    case Enterprise = 'enterprise';

    public function label(): string
    {
        return match ($this) {
            self::Micro => '1–10 employees',
            self::Small => '11–50 employees',
            self::Medium => '51–200 employees',
            self::Large => '201–1,000 employees',
            self::Enterprise => '1,000+ employees',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $size): string => $size->value, self::cases());
    }
}
