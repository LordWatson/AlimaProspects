<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\LeadSort;
use App\Modules\LeadsWorkspace\Enums\LeadSortDirection;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\Scoring\Enums\ScoreGrade;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the leads list's query string (Phase 10). Nothing here authorises anything — a member
 * may always list their own team's leads — it exists so a bad filter is a 422 the caller can read
 * rather than a silently empty list, and so `per_page` can't be used to ask for the whole table.
 *
 * {@see LeadFilters} narrows the values a second time on the way into the query. That is not
 * belt-and-braces for its own sake: the same filters also arrive from a stored {@see SavedView},
 * which never passes through this request at all.
 */
final class ListLeadsRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:'.LeadFilters::MAX_SEARCH],
            'grades' => ['nullable', 'array', 'max:'.count(ScoreGrade::cases())],
            'grades.*' => ['string', 'distinct', Rule::enum(ScoreGrade::class)],
            'sources' => ['nullable', 'array', 'max:'.count(LeadSource::cases())],
            'sources.*' => ['string', 'distinct', Rule::enum(LeadSource::class)],
            'enrichment_statuses' => ['nullable', 'array', 'max:'.count(EnrichmentStatus::cases())],
            'enrichment_statuses.*' => ['string', 'distinct', Rule::enum(EnrichmentStatus::class)],
            'country' => ['nullable', 'string', 'max:64'],

            // The Phase 10b filters. Deliberately **not** `exists` rules scoped to the team: an id
            // that isn't the team's own narrows the list to nothing (the query reads through
            // team-scoped relations), which is a better answer for a stale bookmark or a saved view
            // than a 422. The caps bound the work one request can ask for.
            'stages' => ['nullable', 'array', 'max:'.(int) config('leads-workspace.max_stages', 20)],
            'stages.*' => ['integer', 'distinct', 'min:1'],
            'tags' => ['nullable', 'array', 'max:20'],
            'tags.*' => ['integer', 'distinct', 'min:1'],
            'assignees' => ['nullable', 'array', 'max:20'],
            'assignees.*' => ['integer', 'distinct', 'min:1'],
            'unassigned' => ['nullable', 'boolean'],

            'sort' => ['nullable', Rule::enum(LeadSort::class)],
            'direction' => ['nullable', Rule::enum(LeadSortDirection::class)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'view' => ['nullable', 'integer'],
        ];
    }

    public function filters(): LeadFilters
    {
        return LeadFilters::fromArray($this->validated());
    }

    public function perPage(int $default = 25): int
    {
        $perPage = $this->validated('per_page');

        return is_numeric($perPage) ? (int) $perPage : $default;
    }

    /**
     * The saved view the caller asked to open, if any. The controller resolves and authorises it;
     * an id that isn't the team's own simply yields no view rather than an error, because a stale
     * bookmark shouldn't break the leads list.
     */
    public function savedViewId(): ?int
    {
        $id = $this->validated('view');

        return is_numeric($id) ? (int) $id : null;
    }
}
