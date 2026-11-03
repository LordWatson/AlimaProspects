<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Who a playbook's notify step tells (S14).
 *
 * The audience is the thing most often got wrong about a notification (the Notifications module
 * doc says so), which is why it is a named choice on the step rather than a list of ids with a
 * convention around it.
 *
 * {@see self::LeadOwners} is the interesting case and usually the right one: an event about a
 * company somebody is working is news for *that* person, and telling the whole team would make it
 * everybody's to ignore — the same reasoning that gave S10 its own notification type. It falls back
 * to the team when nobody owns the lead, which is deliberate rather than a shrug: an unowned lead
 * with an acquisition against it is exactly the lead somebody should pick up.
 */
enum PlaybookNotifyAudience: string
{
    /** Everybody in the team. */
    case Team = 'team';

    /** Owners and Admins only. */
    case Admins = 'admins';

    /** Whoever is working the lead, falling back to the team when nobody is. */
    case LeadOwners = 'lead_owners';

    /** The members the rule names. */
    case Named = 'named';

    public function label(): string
    {
        return match ($this) {
            self::Team => 'The whole team',
            self::Admins => 'Owners and admins',
            self::LeadOwners => 'Whoever is working the lead',
            self::Named => 'People I name',
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
