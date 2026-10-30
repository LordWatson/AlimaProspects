<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Requests;

use App\Modules\Reporting\Data\ReportFilters;
use App\Modules\Reporting\Enums\ReportRange;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the dashboard's date filter (Phase 13). Nothing here authorises anything — the policy
 * does that — it exists so a malformed filter is a 422 the caller can read rather than a silently
 * different set of numbers.
 *
 * {@see ReportFilters} narrows the values a second time on the way into the aggregations. That is
 * not belt-and-braces for its own sake: the same filters are also built straight from a query
 * string by the mobile app and a future `/api/v1/reports`, neither of which passes through here.
 */
class DashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'range' => ['nullable', Rule::enum(ReportRange::class)],
            // `date_format` rather than `date`: the filter sends a plain day in the team's
            // timezone, and accepting a full timestamp would let a caller smuggle an offset into a
            // window that is deliberately cut on the team's clock.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ];
    }

    public function filters(): ReportFilters
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return ReportFilters::fromArray($validated);
    }
}
