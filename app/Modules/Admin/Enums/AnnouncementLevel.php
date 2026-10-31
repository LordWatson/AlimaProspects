<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

/**
 * How loudly an announcement reads (Phase 19b).
 *
 * Three levels, mapped onto the brand's **status** tokens — never Signal Orange, which is
 * decorative only (brand-tokens.md §2.1). An announcement is a state the product is in, which is
 * exactly what the status tokens are for.
 */
enum AnnouncementLevel: string
{
    /** A notice. A new feature, a price change with notice, a policy update. */
    case Info = 'info';

    /** Something is coming that will affect them: maintenance, a deprecation, a deadline. */
    case Warning = 'warning';

    /** Something is wrong now: an outage, a provider down, data affected. */
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Info => 'Notice',
            self::Warning => 'Heads-up',
            self::Critical => 'Something is wrong',
        };
    }

    /**
     * The `Badge`/banner variant. Named for the semantic token rather than a colour, so a brand
     * revision changes one file and not this one.
     */
    public function variant(): string
    {
        return match ($this) {
            self::Info => 'info',
            self::Warning => 'warning',
            self::Critical => 'error',
        };
    }

    /**
     * Whether a tenant may dismiss it. A `critical` announcement is not dismissible by default —
     * "we are aware of an outage" should not disappear because somebody clicked a cross — but the
     * operator can still override it per announcement, because a long-lived critical banner nobody
     * can clear becomes wallpaper.
     */
    public function defaultDismissible(): bool
    {
        return $this !== self::Critical;
    }
}
