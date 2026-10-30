<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use App\Modules\Reporting\Enums\KpiFormat;
use Spatie\LaravelData\Data;

/**
 * One headline number on the dashboard, with the context that makes it mean something: a caption,
 * the change against the previous window, and the weekly series behind it for the tile's
 * sparkline.
 *
 * The value stays an integer and the currency a code — formatting is the frontend's job, because
 * only it knows the active locale (and Arabic keeps Latin digits; see `useFormat`).
 */
final class Kpi extends Data
{
    /**
     * @param  list<int>  $spark  Weekly values, oldest first. Empty when the metric has no history.
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $value,
        public readonly string $caption,
        public readonly KpiFormat $format = KpiFormat::Count,
        /** ISO currency code — set only when `format` is money. */
        public readonly ?string $currency = null,
        /** Change against the previous window of the same length; null when not comparable. */
        public readonly ?int $delta = null,
        /** What the delta is measured against, e.g. "vs last 30 days". */
        public readonly ?string $delta_label = null,
        public readonly array $spark = [],
    ) {}
}
