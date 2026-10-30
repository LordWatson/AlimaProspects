<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\Signal;

/**
 * The lifecycle of a single {@see Signal}. A signal is created
 * {@see self::New} when a watch run keeps it, then either {@see self::Dismissed} by a team member
 * (with a reason, for tuning) or {@see self::Converted} into a standard `Lead`. Dismissed signals
 * can be restored to New; a converted signal keeps its one-way link to the lead it created.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum SignalStatus: string
{
    case New = 'new';
    case Dismissed = 'dismissed';
    case Converted = 'converted';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Dismissed => 'Dismissed',
            self::Converted => 'Converted',
        };
    }

    /**
     * Whether the signal has already become a lead. Readers branch on this rather than comparing
     * the case, because "converted" is the one status with consequences elsewhere — a converted
     * signal can't be dismissed or restored, isn't pruned by the retention sweep, and isn't erased
     * on its own by a data-subject request.
     */
    public function isConverted(): bool
    {
        return $this === self::Converted;
    }

    /**
     * Whether a member can still act on it: convert it, or dismiss it (again, with a new reason).
     */
    public function isActionable(): bool
    {
        return ! $this->isConverted();
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
