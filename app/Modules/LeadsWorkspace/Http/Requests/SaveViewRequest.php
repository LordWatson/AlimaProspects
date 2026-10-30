<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\LeadsWorkspace\Enums\LeadSortDirection;
use App\Modules\LeadsWorkspace\Policies\SavedViewPolicy;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a saved leads view: a name, the filter set to keep, and whether to share it with the
 * team. Authorisation lives in the controller via {@see SavedViewPolicy}.
 *
 * The filters are validated under a `filters.` prefix rather than reused from
 * {@see ListLeadsRequest}, because this is a body, not a query string — the two shapes are
 * deliberately separate even though both end up as a {@see LeadFilters}.
 */
final class SaveViewRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:80'],
            'is_shared' => ['nullable', 'boolean'],
            'filters' => ['nullable', 'array'],
            'filters.search' => ['nullable', 'string', 'max:'.LeadFilters::MAX_SEARCH],
            'filters.grades' => ['nullable', 'array', 'max:'.count(ScoreGrade::cases())],
            'filters.grades.*' => ['string', 'distinct', Rule::enum(ScoreGrade::class)],
            'filters.sources' => ['nullable', 'array', 'max:'.count(LeadSource::cases())],
            'filters.sources.*' => ['string', 'distinct', Rule::enum(LeadSource::class)],
            'filters.enrichment_statuses' => ['nullable', 'array', 'max:'.count(EnrichmentStatus::cases())],
            'filters.enrichment_statuses.*' => ['string', 'distinct', Rule::enum(EnrichmentStatus::class)],
            'filters.country' => ['nullable', 'string', 'max:64'],
            'filters.stages' => ['nullable', 'array', 'max:'.(int) config('leads-workspace.max_stages', 20)],
            'filters.stages.*' => ['integer', 'distinct', 'min:1'],
            'filters.tags' => ['nullable', 'array', 'max:20'],
            'filters.tags.*' => ['integer', 'distinct', 'min:1'],
            'filters.assignees' => ['nullable', 'array', 'max:20'],
            'filters.assignees.*' => ['integer', 'distinct', 'min:1'],
            'filters.unassigned' => ['nullable', 'boolean'],
            'filters.sort' => ['nullable', Rule::enum(LeadSort::class)],
            'filters.direction' => ['nullable', Rule::enum(LeadSortDirection::class)],
        ];
    }

    public function name(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function isShared(): bool
    {
        return (bool) $this->validated('is_shared', false);
    }

    public function filters(): LeadFilters
    {
        /** @var array<string, mixed> $filters */
        $filters = $this->validated('filters', []);

        return LeadFilters::fromArray($filters);
    }
}
