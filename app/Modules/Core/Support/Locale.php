<?php

declare(strict_types=1);

namespace App\Modules\Core\Support;

use App\Modules\Core\Enums\SupportedLocale;
use Illuminate\Support\Facades\File;

/**
 * Locale helpers over the {@see SupportedLocale} enum: direction lookup, the
 * switcher option list, and the translation bundle shared to the front end.
 * The enum is the source of truth; this class is the app-facing convenience.
 */
final class Locale
{
    public static function default(): string
    {
        $configured = config('app.locale');

        return is_string($configured) && self::supported($configured)
            ? $configured
            : SupportedLocale::default()->value;
    }

    public static function supported(string $locale): bool
    {
        return SupportedLocale::tryFrom($locale) !== null;
    }

    /**
     * Writing direction for a locale ('ltr' or 'rtl'); defaults to 'ltr'.
     */
    public static function direction(string $locale): string
    {
        return (SupportedLocale::tryFrom($locale) ?? SupportedLocale::default())
            ->direction()->value;
    }

    /**
     * The locales offered in the UI switcher.
     *
     * @return list<array{code: string, label: string, native: string, dir: string}>
     */
    public static function options(): array
    {
        return array_map(
            static fn (SupportedLocale $locale): array => [
                'code' => $locale->value,
                'label' => $locale->label(),
                'native' => $locale->native(),
                'dir' => $locale->direction()->value,
            ],
            SupportedLocale::cases(),
        );
    }

    /**
     * The JSON translation bundle for a locale, shared to Inertia for the
     * front-end `useI18n()` composable. The default-locale bundle is used as the
     * base so a key missing from a secondary locale falls back to it; a missing
     * file yields the base alone. Malformed JSON is treated as empty.
     *
     * @return array<string, string>
     */
    public static function messages(string $locale): array
    {
        $base = self::readBundle(self::default());

        if ($locale === self::default()) {
            return $base;
        }

        return array_merge($base, self::readBundle($locale));
    }

    /**
     * @return array<string, string>
     */
    private static function readBundle(string $locale): array
    {
        $path = lang_path($locale.'.json');

        if (! File::exists($path)) {
            return [];
        }

        /** @var array<string, string> $messages */
        $messages = json_decode((string) File::get($path), true) ?: [];

        return $messages;
    }
}
