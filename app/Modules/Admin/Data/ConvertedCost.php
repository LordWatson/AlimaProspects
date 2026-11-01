<?php

declare(strict_types=1);

namespace App\Modules\Admin\Data;

use App\Modules\Admin\Services\Platform\PlatformMoney;
use Spatie\LaravelData\Data;

/**
 * Provider cost put onto the reporting currency's scale, **with whatever could not be converted
 * still attached** (Phase 19c).
 *
 * The second field is the whole point. {@see PlatformMoney} could have returned an integer and
 * silently dropped a currency nobody configured a rate for; the margin screen would then have
 * reported a healthier margin than the business has, and nothing would have said so. So the
 * unconverted micro-units travel with the total and every screen that shows the one shows the
 * other.
 *
 * Property names are snake_case: this shape is read straight off the Inertia page props.
 */
final class ConvertedCost extends Data
{
    /**
     * @param  array<string, int>  $unconverted  ISO code => cost micro-units with no configured rate
     */
    public function __construct(
        /** Integer minor units of `currency`. */
        public readonly int $minor_units,
        public readonly array $unconverted,
        public readonly string $currency,
    ) {}

    /** Whether any cost was left out of the total. If true, every figure derived from it is a floor. */
    public function hasUnconverted(): bool
    {
        return $this->unconverted !== [];
    }
}
