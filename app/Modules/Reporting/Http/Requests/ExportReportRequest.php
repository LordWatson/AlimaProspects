<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Enums\ReportKey;
use Illuminate\Validation\Rule;

/**
 * Validates a CSV export request (Phase 13): the same window as the dashboard, plus which report.
 *
 * `report` is **required and enum-validated**, which is what keeps the filename and the audit
 * entry out of the caller's hands: both are derived from the enum case, never from the string that
 * arrived.
 */
final class ExportReportRequest extends DashboardRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'report' => ['required', Rule::enum(ReportKey::class)],
        ];
    }

    public function report(): ReportKey
    {
        return ReportKey::from((string) $this->validated('report'));
    }
}
