<?php

declare(strict_types=1);

namespace App\Modules\Onboarding\Enums;

use App\Modules\Onboarding\Actions\CompleteOnboardingAction;

/**
 * The ordered steps of the onboarding wizard. The first five capture Business Profile data; the
 * final `Review` step is a read-only confirmation before {@see CompleteOnboardingAction}.
 *
 * Ordering here is authoritative — the wizard UI, the resumable `current_step` and the
 * "completed steps" set all key off these values. No magic strings elsewhere.
 */
enum OnboardingStep: string
{
    case Company = 'company';
    case Industry = 'industry';
    case Locations = 'locations';
    case Offering = 'offering';
    case Pricing = 'pricing';
    case Review = 'review';

    public function label(): string
    {
        return match ($this) {
            self::Company => 'Your company',
            self::Industry => 'Industry & size',
            self::Locations => 'Where you operate',
            self::Offering => 'What you offer',
            self::Pricing => 'Pricing & customers',
            self::Review => 'Review & finish',
        };
    }

    /**
     * Data-capture steps (everything except the final review).
     *
     * @return list<self>
     */
    public static function dataSteps(): array
    {
        return [self::Company, self::Industry, self::Locations, self::Offering, self::Pricing];
    }

    /**
     * Whether this step captures Business Profile data (vs. the review step).
     */
    public function isDataStep(): bool
    {
        return $this !== self::Review;
    }

    /**
     * 1-based position in the full flow (used by the progress rail).
     */
    public function position(): int
    {
        return array_search($this, self::cases(), strict: true) + 1;
    }

    public function next(): ?self
    {
        return self::cases()[array_search($this, self::cases(), strict: true) + 1] ?? null;
    }

    public static function first(): self
    {
        return self::Company;
    }

    /**
     * The backing values of the data-capture steps (for route constraints / validation).
     *
     * @return list<string>
     */
    public static function dataValues(): array
    {
        return array_map(static fn (self $step): string => $step->value, self::dataSteps());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $step): string => $step->value, self::cases());
    }
}
