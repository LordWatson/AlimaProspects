<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Enums;

/**
 * The orderings the leads list offers (Phase 10). Each case maps to a sortable column — or, for
 * {@see self::Fit}, to a correlated sub-query over the lead's score against the active ICP — so a
 * request can never name an arbitrary column. No magic strings.
 *
 * {@see self::Fit} and {@see self::Readiness} are the two different questions a lead answers (Q4):
 * whether we should want them, and whether we can actually reach them. They are separate orderings
 * because an A-grade lead with no contact details and a fully enriched poor fit are both real, and
 * a member sorting the list is asking one of the two.
 *
 * PL1 adds the other two questions, and the set is now four rather than one figure dressed four
 * ways: **fit** is whether we should want them, **readiness** is whether we can reach them,
 * **timing** is whether now is the moment, and **confidence** is how much of this we actually know.
 * A lead can be strong on any three and weak on the fourth, which is why none of them collapses
 * into the others and why the list offers all four.
 */
enum LeadSort: string
{
    case Newest = 'newest';
    case Company = 'company';
    case Fit = 'fit';
    case Readiness = 'readiness';
    case Freshness = 'freshness';
    /** How much of a reason there is to act NOW: the strongest decayed trigger event (PL1). */
    case Timing = 'timing';
    /** How much the lead's evidence is worth (PL1) — about our data, not about the company. */
    case Confidence = 'confidence';

    public function label(): string
    {
        return match ($this) {
            self::Newest => 'Newest first',
            self::Company => 'Company name',
            self::Fit => 'Best fit',
            self::Readiness => 'Ready to contact',
            self::Freshness => 'Recently updated',
            self::Timing => 'Most urgent',
            self::Confidence => 'Best evidence',
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
            self::Newest, self::Fit, self::Readiness, self::Freshness,
            self::Timing, self::Confidence => LeadSortDirection::Desc,
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
