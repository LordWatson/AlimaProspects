<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Data;

use Spatie\LaravelData\Data;

/**
 * A labelled count in a one-dimensional breakdown — a pipeline stage, a lead source, a signal
 * event type. The bar charts on the dashboard all speak this shape, so one chart component renders
 * every one of them.
 */
final class BreakdownSlice extends Data
{
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly int $value,
        /** Optional second line under the label — a stage outcome, a provider note. */
        public readonly ?string $hint = null,
    ) {}
}
