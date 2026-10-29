<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

use App\Modules\ICP\Enums\CriterionType;
use Spatie\LaravelData\Data;

/**
 * One validated criterion on its way into an ICP write Action. `weight` is 1–100 for positive
 * signals and null for exclusions. Display order is the position in the list (applied by the
 * Action via the array index), so it isn't carried per-row here.
 */
final class CriterionData extends Data
{
    public function __construct(
        public CriterionType $type,
        public string $value,
        public ?int $weight,
    ) {}
}
