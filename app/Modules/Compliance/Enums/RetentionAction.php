<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Enums;

/**
 * What the retention sweep does to one lead (docs/compliance.md §3.4).
 *
 * The two cases are not alike, and the distinction is the substance of the policy rather than a
 * convenience:
 *
 * - A lead **nobody ever approached** is a company record held on a legitimate-interest basis that
 *   was never acted on. There is nothing to account for, so it goes.
 * - A lead the team **did approach** is anonymised: the personal data goes and the company record
 *   and the fact of each approach stay. Deleting it would destroy the team's own evidence of who
 *   they contacted and when — which is the accountability the retention rule sits beside, not
 *   something to trade away for a tidier database.
 */
enum RetentionAction: string
{
    case Deleted = 'deleted';

    case Anonymised = 'anonymised';

    public function label(): string
    {
        return match ($this) {
            self::Deleted => 'Deleted',
            self::Anonymised => 'Personal data removed',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $action): string => $action->value, self::cases());
    }
}
