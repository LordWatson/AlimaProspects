<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resources;

use App\Modules\Reporting\Data\DashboardReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The dashboard over `/api/v1` (Phase 17), wrapping the same {@see DashboardReport} the web screen
 * renders so the phone and the browser cannot show a team different numbers for the same window.
 *
 * A Resource around a Data object rather than a raw Data response: it keeps the `data` envelope
 * every other endpoint uses, which is the thing a client's deserialiser is written against.
 *
 * @mixin DashboardReport
 */
final class DashboardReportResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DashboardReport $report */
        $report = $this->resource;

        return $report->toArray();
    }
}
