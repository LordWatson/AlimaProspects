<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

/**
 * The orderings the leads list offers (Phase 10). Each case maps to a sortable column — or, for
 * {@see self::Fit}, to a correlated sub-query over the lead's score against the active ICP — so a
 * request can never name an arbitrary column. No magic strings.
 */
enum LeadSort: string
{
    case Newest = 'newest';
    case Company = 'company';
    case Fit = 'fit';
    case Freshness = 'freshness';

    public function label(): string
    {
        return match ($this) {
            self::Newest => 'Newest first',
            self::Company => 'Company name',
            self::Fit => 'Best fit',
            self::Freshness => 'Recently updated',
        };
    }

    /**
     * The direction that reads as "most useful first" for this ordering, used when the request
     * doesn't ask for one.
     */
    public function defaultDirection(): LeadSortDirection
    {
        return match ($this) {
            self::Company => LeadSortDirection::Asc,
            self::Newest, self::Fit, self::Freshness => LeadSortDirection::Desc,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $sort): string => $sort->value, self::cases());
    }
}
