<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a note. Long enough for a real account of a call, short enough that the field can't be
 * used as a document store.
 */
final class SaveLeadNoteRequest extends FormRequest
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
            'body' => ['required', 'string', 'min:1', 'max:5000'],
        ];
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
