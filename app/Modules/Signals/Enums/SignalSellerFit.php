<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Whether the company an event is about is one this team would sell to (Signals v3 P1).
 *
 * The classifier's answer, from the article and the seller's own description and nothing else. An
 * enum because the run branches on it: a confident {@see self::NotFit} or {@see self::Competitor}
 * is left out of the feed, and everything else is kept.
 *
 * `Unknown` is the common answer and an honest one. A news snippet rarely says what a company
 * sells or how big it is, and a verdict the article cannot support would be a guess.
 */
enum SignalSellerFit: string
{
    case Fit = 'fit';
    case Unknown = 'unknown';
    case NotFit = 'not_fit';
    case Competitor = 'competitor';

    /** Whether this verdict says the subject is not somebody to approach. */
    public function isRejection(): bool
    {
        return $this === self::NotFit || $this === self::Competitor;
    }

    /** A translation key for the verdict. */
    public function label(): string
    {
        return match ($this) {
            self::Fit => 'Looks like a customer',
            self::Unknown => 'Not enough to tell',
            self::NotFit => 'Probably not a customer',
            self::Competitor => 'Probably a competitor',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $fit): string => $fit->value, self::cases());
    }
}
