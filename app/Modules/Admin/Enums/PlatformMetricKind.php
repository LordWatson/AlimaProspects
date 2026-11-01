<?php

declare(strict_types=1);

namespace App\Modules\Admin\Enums;

use App\Modules\Admin\Services\Platform\PlatformMoney;
use App\Modules\Reporting\Enums\ColumnFormat;

/**
 * What the integer in `platform_daily_metrics.value` actually is (Phase 19c).
 *
 * One column holds every figure, so the unit has to travel with the metric rather than with the
 * row — and it has to be stated, not inferred. The two that get confused are the last two: a
 * **minor unit** is a penny and a **cost micro-unit** is a millionth of a major unit, so they are
 * four orders of magnitude apart. The Phase 19a dashboard already labels its AI figure "micros"
 * for exactly this reason; this enum is what makes the distinction checkable instead of a comment.
 */
enum PlatformMetricKind: string
{
    /** A whole number of things — teams, calls, prospects. */
    case Count = 'count';

    /** Money in **integer minor units** of the platform currency (CLAUDE.md §2). */
    case Money = 'money';

    /**
     * Provider cost in **micro-units** of some currency — what `ai_requests.cost_micros` and
     * `discovery_runs.cost_micros` store. 1,000,000 micros is one major unit, so a micro figure is
     * 10,000x a minor-unit one and mixing the two is a four-order-of-magnitude error.
     */
    case Micros = 'micros';

    /** Milliseconds, summed. Divided by a call count to get a mean; never read on its own. */
    case Milliseconds = 'ms';

    /**
     * How a column of this kind is rendered on screen and in the CSV.
     *
     * Micros map to {@see ColumnFormat::Money} only after conversion, which is
     * {@see PlatformMoney}'s job — a micro value handed
     * straight to a money column would be printed as ten thousand times itself, so the raw kind
     * reports as a count and the builders convert deliberately.
     */
    public function format(): ColumnFormat
    {
        return match ($this) {
            self::Count, self::Micros, self::Milliseconds => ColumnFormat::Count,
            self::Money => ColumnFormat::Money,
        };
    }
}
