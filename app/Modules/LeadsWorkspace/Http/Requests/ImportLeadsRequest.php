<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Actions\ImportLeadsAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;

/**
 * Validates a CSV import: the file, plus the member's column mapping.
 *
 * The mapping's **keys** are the fields the import may write, checked against
 * {@see ImportLeadsAction::FIELDS} — so a crafted payload can't name a column on `leads` that an
 * import has no business setting (`team_id`, `dedupe_key`, an enrichment flag). The values are the
 * team's own CSV headings, and a heading the file doesn't actually have is dropped by the reader.
 *
 * `mimes:csv,txt` rather than a MIME sniff: a CSV is plain text and browsers disagree wildly about
 * what they report for it, so the extension plus the reader's own parsing is the honest check.
 */
final class ImportLeadsRequest extends FormRequest
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
        $maxKb = max(1, (int) config('leads-workspace.csv.max_upload_kb', 5_120));

        return [
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:'.$maxKb],
            'mapping' => ['required', 'array', 'min:1'],
            'mapping.*' => ['nullable', 'string', 'max:255'],
            // Only these keys may appear, and `company_name` must be one of them — a lead can't be
            // created without it.
            'mapping.company_name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                /** @var array<string, mixed> $mapping */
                $mapping = $this->input('mapping', []);

                foreach (array_keys($mapping) as $field) {
                    if (! in_array($field, ImportLeadsAction::FIELDS, strict: true)) {
                        $validator->errors()->add('mapping', __('“:field” isn’t something Alima can import.', [
                            'field' => (string) $field,
                        ]));

                        return;
                    }
                }
            },
        ];
    }

    public function csv(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }

    /**
     * The mapping, narrowed to importable fields with a non-empty column name.
     *
     * @return array<string, string>
     */
    public function mapping(): array
    {
        /** @var array<string, mixed> $mapping */
        $mapping = $this->validated('mapping', []);

        $clean = [];

        foreach ($mapping as $field => $column) {
            if (! is_string($column)) {
                continue;
            }

            $column = trim($column);

            if ($column !== '' && in_array($field, ImportLeadsAction::FIELDS, strict: true)) {
                $clean[$field] = $column;
            }
        }

        return $clean;
    }
}
