<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Enums\ExportPreset;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an export request (PL6): the same filters as the list, plus what goes in the file.
 *
 * The filter rules come from {@see ListLeadsRequest::filterRules()} rather than being copied — an
 * export is the list's own question asked for a file, and a filter validated in one place and not
 * the other is a filter that reaches the query unchecked from whichever screen was forgotten.
 *
 * `columns` is only read for the custom preset, and every key is checked against
 * {@see LeadCsvExporter::ALL_COLUMNS}: a column name is a key in a keyed row rather than a column
 * in a query, so an unknown one would be a blank header rather than an injection — but a request
 * that silently produces a file with a column nobody asked for is still wrong, and the list is
 * free to check.
 */
final class StartLeadExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // The controller asks `LeadPolicy::export`.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...ListLeadsRequest::filterRules(),
            'preset' => ['nullable', Rule::enum(ExportPreset::class)],
            'columns' => ['nullable', 'array', 'max:'.count(LeadCsvExporter::ALL_COLUMNS)],
            'columns.*' => ['string', 'distinct', Rule::in(LeadCsvExporter::ALL_COLUMNS)],
        ];
    }

    public function filters(): LeadFilters
    {
        return LeadFilters::fromArray($this->validated());
    }

    /**
     * The preset, defaulting to the importable one — the file a team can read back into Alima is
     * the safest thing to produce when nobody said otherwise.
     */
    public function preset(): ExportPreset
    {
        $preset = $this->validated('preset');

        return is_string($preset) ? ExportPreset::from($preset) : ExportPreset::LeadsOnly;
    }

    /**
     * The member's own column set, in the order they are listed in the catalogue rather than the
     * order they happened to tick them: a file whose columns move between two exports of the same
     * preset is a file somebody's spreadsheet breaks on.
     *
     * @return list<string>
     */
    public function columns(): array
    {
        $columns = $this->validated('columns');

        if (! is_array($columns) || $columns === []) {
            return [];
        }

        /** @var list<string> $strings */
        $strings = array_values(array_filter($columns, 'is_string'));

        return array_values(array_intersect(LeadCsvExporter::ALL_COLUMNS, $strings));
    }
}
