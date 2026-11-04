<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Services\PresetUpdateDiffer;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Taking the library's changes into a clone, per field (S15 task 5).
 *
 * **An empty list is valid and meaningful**: "I have seen the update and I am keeping mine", which
 * is the answer that stops the panel asking again. A `required` rule here would make the only way
 * to dismiss an offer be to take something from it.
 *
 * The field names are checked against {@see PresetUpdateDiffer::FIELDS} — a closed list — because
 * they reach `forceFill` as column names. The Action narrows them again against the **actual diff**,
 * so a stale form cannot copy a value over the top of one the team edited in the meantime; this is
 * the belt to that braces, and the one that keeps a request from naming a column at all.
 */
final class AdoptPresetChangesRequest extends FormRequest
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
            'fields' => ['present', 'array', 'max:'.count(PresetUpdateDiffer::FIELDS)],
            'fields.*' => ['string', 'in:'.implode(',', PresetUpdateDiffer::FIELDS)],
        ];
    }

    /**
     * @return list<string>
     */
    public function fields(): array
    {
        $fields = $this->validated('fields');

        if (! is_array($fields)) {
            return [];
        }

        return array_values(array_unique(array_filter($fields, 'is_string')));
    }
}
