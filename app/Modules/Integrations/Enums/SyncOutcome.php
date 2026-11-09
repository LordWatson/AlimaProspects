<?php

declare(strict_types=1);

namespace App\Modules\Integrations\Enums;

/**
 * What one push to a CRM did (PL7).
 *
 * The plan asked for an `action` (create/update/skip) beside an `outcome`. One column, because the
 * two were answering the same question and the pair allowed states that mean nothing — "created"
 * and "failed" together, or "skipped" and "failed". A reader of the log wants one word per row.
 *
 * `Skipped` and `Refused` are deliberately apart. Skipped means **nothing had changed**, which is
 * the framework working as intended and the thing that stops a nightly sync re-pushing four
 * thousand unchanged organisations. Refused means we **declined** to push: the lead's company is on
 * the team's do-not-contact list, or their sync allowance is spent. One is noise and the other is
 * an answer a member needs, and a shared word would have buried the second in the first.
 */
enum SyncOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';

    /** Nothing had changed since the last push (`synced_hash`). */
    case Skipped = 'skipped';

    /** We declined: suppression, or a spent allowance. */
    case Refused = 'refused';

    /** The provider, or we, broke. */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Created => 'Created',
            self::Updated => 'Updated',
            self::Skipped => 'Already up to date',
            self::Refused => 'Not sent',
            self::Failed => 'Failed',
        };
    }

    /**
     * Whether this outcome means a record actually moved, which is what gets metered (PL4).
     *
     * A skip costs us nothing and a refusal is us saying no — charging a team's CRM allowance for
     * either would be charging them for the framework being careful.
     */
    public function isBilled(): bool
    {
        return match ($this) {
            self::Created, self::Updated => true,
            self::Skipped, self::Refused, self::Failed => false,
        };
    }
}
