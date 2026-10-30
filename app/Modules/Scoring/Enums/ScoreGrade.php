<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Enums;

use App\Modules\Scoring\Models\LeadScore;

/**
 * The A–D grade of a {@see LeadScore}: a coarse, sortable bucket over the hybrid 0–100 fit score so
 * a member can scan a list without reading numbers. Thresholds come from `config/scoring.php` (not
 * hard-coded) so a team's expectations can be tuned without a migration.
 *
 * Values are stable storage keys; {@see self::label()} is the display name. No magic strings.
 */
enum ScoreGrade: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';

    public function label(): string
    {
        return match ($this) {
            self::A => 'A — strong fit',
            self::B => 'B — good fit',
            self::C => 'C — partial fit',
            self::D => 'D — weak fit',
        };
    }

    /**
     * A one-line explanation of what the grade means, shown beside the badge.
     */
    public function description(): string
    {
        return match ($this) {
            self::A => 'Matches the profile closely — worth contacting first.',
            self::B => 'Matches most of the profile.',
            self::C => 'Matches part of the profile — check it before reaching out.',
            self::D => 'Little or no match with the profile.',
        };
    }

    /**
     * The grade for a blended 0–100 fit score, using the `scoring.grades` thresholds.
     */
    public static function fromScore(int $score): self
    {
        if ($score >= self::threshold('a')) {
            return self::A;
        }

        if ($score >= self::threshold('b')) {
            return self::B;
        }

        return $score >= self::threshold('c') ? self::C : self::D;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $grade): string => $grade->value, self::cases());
    }

    /**
     * The configured minimum score for a grade, clamped to 0–100 so a bad config can't invert the
     * scale or push a grade out of reach.
     */
    private static function threshold(string $grade): int
    {
        $defaults = ['a' => 80, 'b' => 60, 'c' => 40];

        return max(0, min(100, (int) config('scoring.grades.'.$grade, $defaults[$grade])));
    }
}
