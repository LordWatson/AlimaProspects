<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

/**
 * The UI locales the product supports (EN + AR, ADR 0007). Single source of
 * truth for locale codes and their writing direction — no magic strings.
 */
enum SupportedLocale: string
{
    case English = 'en';
    case Arabic = 'ar';

    /**
     * Writing direction for the locale.
     */
    public function direction(): TextDirection
    {
        return match ($this) {
            self::Arabic => TextDirection::Rtl,
            default => TextDirection::Ltr,
        };
    }

    /**
     * English label (for admin/internal use).
     */
    public function label(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Arabic => 'Arabic',
        };
    }

    /**
     * Endonym — the language's own name, shown in the switcher.
     */
    public function native(): string
    {
        return match ($this) {
            self::English => 'English',
            self::Arabic => 'العربية',
        };
    }

    public static function default(): self
    {
        return self::English;
    }

    public static function tryFromString(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $locale): string => $locale->value, self::cases());
    }
}
