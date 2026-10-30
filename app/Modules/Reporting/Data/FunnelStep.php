<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * One step of the prospecting funnel — sourced → qualified → contacted → replied.
 *
 * `available` is what keeps the funnel honest. A step with no source of truth comes down
 * unavailable and the screen draws an em dash, a padlock and where the number will come from —
 * rather than a confident `0` a team would read as "nobody replied". **A `0` must only ever mean
 * zero.**
 *
 * As of Phase 13 every step has a source of truth: "contacted" and "replied" are real counts off
 * `contact_attempts`, which Outreach (Phase 11) built. {@see self::pending()} is kept all the same
 * — it is the constructor for that state, and the next step to arrive before its data (a meetings
 * step wanting a calendar integration, say) should reach for it rather than reinvent the
 * distinction. Do not let a later change quietly turn an unavailable step into a zero.
 */
final class FunnelStep extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $caption,
        /** Null when the step has no source of truth yet (see `available`). */
        public readonly ?int $value,
        public readonly bool $available = true,
    ) {}

    /**
     * A step whose data does not exist yet. See the class docblock: nothing on the dashboard is in
     * this state today, and the mechanism stays because the next one will be.
     */
    public static function pending(string $key, string $label, string $caption): self
    {
        return new self($key, $label, $caption, null, false);
    }
}
