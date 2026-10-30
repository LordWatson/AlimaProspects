<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Enums;

/**
 * How the preference screen arranges the notification types (Phase 12).
 *
 * Purely presentational, and named for what the member gets out of each group rather than for the
 * module that raises it: "Finding prospects" is a thing they care about, "Lead Sourcing, Scoring
 * and Signals" is our architecture.
 */
enum NotificationGroup: string
{
    case Finding = 'finding';
    case MyWork = 'my_work';
    case Account = 'account';
    case Summary = 'summary';

    public function label(): string
    {
        return match ($this) {
            self::Finding => 'Finding prospects',
            self::MyWork => 'My work',
            self::Account => 'Account',
            self::Summary => 'Round-ups',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $group): string => $group->value, self::cases());
    }
}
