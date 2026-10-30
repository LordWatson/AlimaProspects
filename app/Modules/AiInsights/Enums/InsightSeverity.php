<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Enums;

/**
 * How loudly an insight asks to be read (Phase 14), and the order the feed shows them in.
 *
 * Three levels rather than five: a member scanning a feed can tell "do something", "look at this"
 * and "worth knowing" apart at a glance, and a finer scale would be us pretending to a precision
 * the underlying figures don't have.
 *
 * The colours these map to on screen are the semantic `status.*` tokens. **Signal Orange is
 * decorative only and is never a status colour** (brand-tokens.md §2.1) — which is exactly the
 * mistake a "warning" level invites, so it is written down here as well as there.
 */
enum InsightSeverity: string
{
    /** Worth knowing. */
    case Info = 'info';

    /** Something is slipping. */
    case Warning = 'warning';

    /** There is a thing to do, and it is named. */
    case Action = 'action';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Worth knowing',
            self::Warning => 'Slipping',
            self::Action => 'Do this',
        };
    }

    /**
     * Sort weight — highest first, so the feed opens on what to do rather than on trivia.
     */
    public function weight(): int
    {
        return match ($this) {
            self::Action => 3,
            self::Warning => 2,
            self::Info => 1,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $severity): string => $severity->value, self::cases());
    }
}
