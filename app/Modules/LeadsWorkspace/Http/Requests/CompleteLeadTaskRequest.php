<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates ticking a task off — or putting it back. `done` is explicit rather than a toggle, so a
 * retried request lands on the same state instead of flipping it back.
 */
final class CompleteLeadTaskRequest extends FormRequest
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
            'done' => ['required', 'boolean'],
        ];
    }

    public function done(): bool
    {
        return (bool) $this->validated('done');
    }
}
