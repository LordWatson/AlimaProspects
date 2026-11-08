<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Enums;

/**
 * What one call to one vendor came to (PL3).
 *
 * **Three kinds of nothing, and keeping them apart is the whole value of the attempts table.** A
 * miss is a vendor doing its job and not knowing; an error is a vendor letting us down; a skip is a
 * call we never made. Folding them together would make an outage look like a vendor that is bad at
 * its job — and would make a vendor's hit rate, which is the number a renewal conversation turns
 * on, a figure that quietly includes the days it was down.
 *
 * Only {@see self::Hit} and {@see self::Miss} belong in a hit rate. Only `Hit`, `Miss` and
 * {@see self::Error} can have cost anything.
 */
enum AttemptOutcome: string
{
    /** The vendor answered the question. */
    case Hit = 'hit';

    /** The vendor looked and did not know. The ordinary outcome, and not a fault. */
    case Miss = 'miss';

    /** The vendor failed: unreachable, unreadable, or unconfigured. */
    case Error = 'error';

    /** Not called: we had already asked too often this minute. Cost nothing. */
    case SkippedRateLimited = 'skipped_rate_limited';

    /** Not called: its breaker was open. Cost nothing, and says nothing about the vendor. */
    case SkippedCircuitOpen = 'skipped_circuit_open';

    /** Not called: the query had nothing to go on, so asking would have been paying for nothing. */
    case SkippedUnanswerable = 'skipped_unanswerable';

    public function label(): string
    {
        return match ($this) {
            self::Hit => 'Found',
            self::Miss => 'Not found',
            self::Error => 'Failed',
            self::SkippedRateLimited => 'Skipped — rate limited',
            self::SkippedCircuitOpen => 'Skipped — provider paused',
            self::SkippedUnanswerable => 'Skipped — not enough to go on',
        };
    }

    /**
     * Whether this attempt belongs in a provider's hit rate.
     *
     * A skip does not: we never asked, so it is evidence about us rather than about the vendor. An
     * error does not either — a 503 is not a vendor failing to know an address.
     */
    public function countsTowardsHitRate(): bool
    {
        return $this === self::Hit || $this === self::Miss;
    }

    /**
     * Whether we actually called the vendor — and so whether this attempt could have cost anything.
     */
    public function wasCalled(): bool
    {
        return $this === self::Hit || $this === self::Miss || $this === self::Error;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $outcome): string => $outcome->value, self::cases());
    }

    /**
     * The storage values that belong in a hit rate, for the queries that ask this in SQL.
     *
     * @return list<string>
     */
    public static function hitRateValues(): array
    {
        return array_values(array_map(
            static fn (self $outcome): string => $outcome->value,
            array_filter(self::cases(), static fn (self $o): bool => $o->countsTowardsHitRate()),
        ));
    }
}
