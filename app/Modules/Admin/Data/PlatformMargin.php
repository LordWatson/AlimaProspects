<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Services\Platform\PlatformMarginBuilder;
use Spatie\LaravelData\Data;

/**
 * One row of the gross-margin report — **the number the business lives on** (Phase 19c).
 *
 * Revenue and cost arrive on different scales and from different currencies, so a margin row is
 * only honest if it carries what was left out of it. {@see self::$unconverted_micros} is that: cost
 * in a currency nobody configured a rate for, shown rather than absorbed. When it is non-zero the
 * margin is a **ceiling** — the real figure is lower — and the screen says so.
 *
 * `margin_ratio` is null rather than zero when there is no revenue to divide by. A tenant on a free
 * plan costs us money and earns none; a margin of "0%" would read as break-even, which is the
 * opposite of true. See {@see PlatformMarginBuilder}.
 */
final class PlatformMargin extends Data
{
    /**
     * @param  array<string, int>  $unconverted_micros  ISO code => cost micro-units with no configured rate
     */
    public function __construct(
        /** `t<id>` for a tenant, a plan key, a `Y-m` cohort, or `unattributed`. */
        public readonly string $key,
        public readonly string $label,
        /** Null for an aggregate row that spans plans, and for the unattributed row. */
        public readonly ?string $plan,
        /** Revenue attributed to the window, in minor units of the reporting currency. */
        public readonly int $revenue_minor,
        /** Provider cost over the window, converted to minor units of the reporting currency. */
        public readonly int $cost_minor,
        /** Cost that could not be converted, by ISO code, in cost micro-units. */
        public readonly array $unconverted_micros,
        public readonly int $margin_minor,
        /** Margin as a fraction of revenue; null when there is no revenue to divide by. */
        public readonly ?float $margin_ratio,
    ) {}

    /** Whether any cost was left out of this row, which makes the margin a ceiling. */
    public function isPartial(): bool
    {
        return $this->unconverted_micros !== [];
    }
}
