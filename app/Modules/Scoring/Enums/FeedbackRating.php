<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Enums;

use App\Modules\Scoring\Models\ScoreFeedback;

/**
 * A member's verdict on a {@see ScoreFeedback} row: was this grade right or wrong? Deliberately
 * binary for the MVP — it is a training signal for weight/prompt tuning, not a rating scale.
 *
 * Values are stable storage keys; {@see self::label()} is the display name.
 */
enum FeedbackRating: string
{
    case Good = 'good';
    case Bad = 'bad';

    public function label(): string
    {
        return match ($this) {
            self::Good => 'Looks right',
            self::Bad => 'Looks wrong',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $rating): string => $rating->value, self::cases());
    }
}
