<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\SignalMatchConfidence;

/**
 * The lead a signal turned out to be about, and how sure we are (S10).
 *
 * A plain value object rather than a `Data` DTO: nothing validates it, because nothing outside
 * `SignalLeadMatcher` constructs one.
 *
 * `reason` is a machine key — `watchlist`, `name`, `name_without_legal_form`,
 * `name_and_known_contact` — and it is **not** shown to a member; the confidence is what they read.
 * It exists so a log line and a timeline entry can say *why* two things were called the same
 * company, which is the question somebody asks when a match turns out to be wrong.
 */
final readonly class SignalMatch
{
    public function __construct(
        public Lead $lead,
        public SignalMatchConfidence $confidence,
        public string $reason,
        /** The name that matched: the article's, or the watchlist entry's. */
        public string $matchedName,
    ) {}
}
