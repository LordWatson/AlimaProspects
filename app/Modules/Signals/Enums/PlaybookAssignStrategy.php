<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * How a playbook decides who gets the lead (S14).
 *
 * Two cases, because the two real answers are "this person" and "share these out". An enum rather
 * than an inferred shape — "a list of one means named, a list of many means round-robin" would be
 * a rule nobody could read off the screen, and a team of three naming all three deliberately would
 * get round-robin by accident.
 *
 * Round-robin is **stateless**: the signal's own id decides the position in the list. A counter on
 * the playbook would be a write per signal and a contended row per team, and it would hand the same
 * person two leads whenever a retry re-read it. Keying on the signal means a retry lands on the
 * same member, which is also what the once-per-signal guarantee wants.
 */
enum PlaybookAssignStrategy: string
{
    /** Everybody named, all on the lead. */
    case Named = 'named';

    /** One of the named members, chosen by the signal's id so a retry picks the same person. */
    case RoundRobin = 'round_robin';

    public function label(): string
    {
        return match ($this) {
            self::Named => 'Everyone I name',
            self::RoundRobin => 'Share between them, one each',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $case): string => $case->value, self::cases());
    }
}
