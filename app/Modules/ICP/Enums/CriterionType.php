<?php

declare(strict_types=1);

namespace App\Modules\ICP\Enums;

/**
 * The dimensions an ICP criterion can describe. The first group are positive, **weighted** signals
 * that raise a lead's fit score (consumed by the deterministic scorer in Scoring & Matching); the
 * final {@see self::Exclusion} is a hard disqualifier (unweighted) — competitors, existing
 * customers, blocked domains and the like.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings
 * elsewhere.
 */
enum CriterionType: string
{
    case Industry = 'industry';
    case CompanySize = 'company_size';
    case Geography = 'geography';
    case JobTitle = 'job_title';
    case Seniority = 'seniority';
    case Technographic = 'technographic';
    case BuyingSignal = 'buying_signal';
    case Exclusion = 'exclusion';

    public function label(): string
    {
        return match ($this) {
            self::Industry => 'Industry',
            self::CompanySize => 'Company size',
            self::Geography => 'Geography',
            self::JobTitle => 'Decision-maker title',
            self::Seniority => 'Seniority',
            self::Technographic => 'Technology used',
            self::BuyingSignal => 'Buying signal',
            self::Exclusion => 'Exclusion',
        };
    }

    /**
     * A short input hint for the editor, so the value field guides rather than puzzles.
     */
    public function placeholder(): string
    {
        return match ($this) {
            self::Industry => 'e.g. SaaS, Logistics, Hospitality',
            self::CompanySize => 'e.g. 11–50 employees',
            self::Geography => 'e.g. London, Dubai, UAE',
            self::JobTitle => 'e.g. Head of Marketing',
            self::Seniority => 'e.g. Director or above',
            self::Technographic => 'e.g. Uses Shopify',
            self::BuyingSignal => 'e.g. Recently raised funding',
            self::Exclusion => 'e.g. acme.com, existing customers',
        };
    }

    /**
     * Whether this is a hard exclusion (disqualifier) rather than a weighted positive signal.
     */
    public function isExclusion(): bool
    {
        return $this === self::Exclusion;
    }

    /**
     * The positive, weighted signal types (everything except {@see self::Exclusion}).
     *
     * @return list<self>
     */
    public static function signals(): array
    {
        return array_values(array_filter(
            self::cases(),
            static fn (self $type): bool => ! $type->isExclusion(),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $type): string => $type->value, self::cases());
    }
}
