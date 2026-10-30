<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Resources;

use App\Modules\Signals\Models\SignalRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One run of a watch: what it cost and what it found. Mirrors `DiscoveryRunResource`.
 *
 * `error` is already the user-safe message the run recorded (the exception's details went to the
 * `signals` log, not here), so it is sent as-is for the screen to show.
 *
 * @mixin SignalRun
 */
final class SignalRunResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SignalRun $run */
        $run = $this->resource;

        return [
            'id' => $run->id,
            'status' => $run->status->value,
            'status_label' => $run->status->label(),
            'queries_count' => $run->queries_count,
            'results_count' => $run->results_count,
            'signals_created' => $run->signals_created,
            // Integer micro-units; the screen formats it in the team's currency.
            'cost_micros' => $run->cost_micros,
            'error' => $run->error,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }
}
