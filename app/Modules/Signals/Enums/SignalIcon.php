<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * The icon a signal definition may wear (ADR 0018 §8, S7).
 *
 * An **allowlist, not a free string**, and that is a correctness point rather than a tidiness one:
 * the client maps this value to an imported Lucide component, so an arbitrary string from a
 * customer-authored definition would render nothing at best. Keeping the set closed means the Vue
 * side can hold one exhaustive map and `vue-tsc` will fail the build if a case is added here and
 * forgotten there.
 *
 * Values are the Lucide icon names in kebab-case, which is also how the frontend map is keyed.
 */
enum SignalIcon: string
{
    /** The default: a signal arriving. */
    case Satellite = 'satellite';

    /** Money changing hands. */
    case Handshake = 'handshake';

    /** Growth, a round raised. */
    case TrendingUp = 'trending-up';

    /** A move, a relocation. */
    case Plane = 'plane';

    /** A building, a new office or head office. */
    case Building = 'building-2';

    /** Hiring, people. */
    case Users = 'users';

    /** A filing, a document, a registry entry. */
    case FileText = 'file-text';

    /** Something going wrong. */
    case TriangleAlert = 'triangle-alert';

    /** A listing, a milestone. */
    case Landmark = 'landmark';

    /** An award, a win, a tender result. */
    case Trophy = 'trophy';

    public function label(): string
    {
        return match ($this) {
            self::Satellite => 'Signal',
            self::Handshake => 'Deal',
            self::TrendingUp => 'Growth',
            self::Plane => 'Move',
            self::Building => 'Office',
            self::Users => 'People',
            self::FileText => 'Filing',
            self::TriangleAlert => 'Warning',
            self::Landmark => 'Listing',
            self::Trophy => 'Award',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $icon): string => $icon->value, self::cases());
    }
}
