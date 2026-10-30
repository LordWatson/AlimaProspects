<?php

declare(strict_types=1);

namespace App\Modules\Billing\Http\Resources;

use App\Modules\Billing\Data\MetricUsage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One metric's position this period, as the usage bars render it.
 *
 * `remaining`, `overage` and `headroom` are all derived from the same three numbers and all sent
 * anyway: deriving them in the browser would mean the rule about where a plan stops — allowance or
 * ceiling, depending on whether it meters — living in two languages, and the version that drifts
 * would be the one a customer reads.
 *
 * @mixin MetricUsage
 */
final class UsageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var MetricUsage $usage */
        $usage = $this->resource;

        return [
            'metric' => $usage->metric->value,
            'label' => $usage->metric->label(),
            'used' => $usage->used,
            'included' => $usage->included,
            'remaining' => $usage->remaining(),
            'used_percent' => $usage->usedPercent(),
            'ceiling' => $usage->ceiling,
            'headroom' => $usage->headroom(),
            'overage' => $usage->overage(),
            'overage_allowed' => $usage->overageAllowed,
            'exhausted' => $usage->isExhausted(),
        ];
    }
}
