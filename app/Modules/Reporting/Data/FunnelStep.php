<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * One step of the prospecting funnel — sourced → qualified → contacted → replied.
 *
 * `available` is what keeps the funnel honest. Outreach (Phase 11) is not built, so "contacted"
 * and "replied" have no source of truth yet: rather than render a confident `0` a team would read
 * as "nobody replied", those steps come down unavailable and the screen says where the number will
 * come from. A `0` must only ever mean zero.
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

    public static function pending(string $key, string $label, string $caption): self
    {
        return new self($key, $label, $caption, null, false);
    }
}
