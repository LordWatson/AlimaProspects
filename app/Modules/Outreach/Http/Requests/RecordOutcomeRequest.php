<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Outreach\Enums\ContactOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a change to a logged attempt's outcome — the common case, since the answer arrives days
 * after the approach.
 */
final class RecordOutcomeRequest extends FormRequest
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
            'outcome' => ['required', Rule::in(ContactOutcome::values())],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function outcome(): ContactOutcome
    {
        return ContactOutcome::from((string) $this->validated('outcome'));
    }

    /**
     * Null leaves whatever the member wrote at the time alone — changing an outcome from a dropdown
     * is not a request to erase the note.
     */
    public function notes(): ?string
    {
        $notes = $this->validated('notes');

        return is_string($notes) && trim($notes) !== '' ? trim($notes) : null;
    }
}
