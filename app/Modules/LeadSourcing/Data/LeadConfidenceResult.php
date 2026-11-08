<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Data;

use App\Modules\LeadSourcing\Services\LeadConfidence;
use Spatie\LaravelData\Data;

/**
 * What {@see LeadConfidence} worked out about a lead: how much its evidence is worth, how much of a
 * reason there is to act now, and whether that puts it below the team's line.
 *
 * All three figures are **nullable, and null is not zero.** A lead with no evidence is unjudged,
 * which is a different state from judged-and-found-wanting, and the difference decides whether it
 * is hidden from the list.
 */
final class LeadConfidenceResult extends Data
{
    /**
     * @param  int|null  $confidence  how much the evidence is worth, 0-100; null when there is none
     * @param  int|null  $timingScore  the strongest decayed trigger event, 0-100; null when none
     * @param  bool  $suppressed  below the team's threshold — never true while `confidence` is null
     * @param  int  $sourceCount  distinct sources behind the lead, which is what corroboration counts
     * @param  string|null  $evidenceHash  the identity of the evidence set, as the why-now cache key
     */
    public function __construct(
        public ?int $confidence,
        public ?int $timingScore,
        public bool $suppressed,
        public int $sourceCount = 0,
        public ?string $evidenceHash = null,
    ) {}

    /**
     * The answer for a lead with nothing to judge.
     */
    public static function unjudged(): self
    {
        return new self(confidence: null, timingScore: null, suppressed: false);
    }
}
