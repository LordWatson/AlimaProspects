<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\PlatformReport;
use Illuminate\Validation\Rule;

/**
 * Which platform report to download, over which window (Phase 19c).
 *
 * `report` is **required and enum-validated**, which is what makes "any report" a closed set: a
 * slug that is not a {@see PlatformReport} is a 422 rather than an empty file, and a new report
 * cannot ship unexportable because the builder has to produce a table for every case.
 */
final class ExportPlatformReportRequest extends PlatformWindowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'report' => ['required', Rule::enum(PlatformReport::class)],
        ];
    }

    public function report(): PlatformReport
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return PlatformReport::from((string) $validated['report']);
    }
}
