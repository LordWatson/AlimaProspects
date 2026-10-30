<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Data\LiaRecordData;
use App\Modules\Core\Enums\Market;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a legitimate-interest assessment being saved or signed off.
 *
 * The three tests are **required to be present but only checked for substance when signing**. A
 * team should be able to save half an assessment and come back to it; what must not happen is a
 * lawful basis being affirmed over three empty boxes, which is why `sign` tightens the minimum
 * length rather than the save doing it (the Action refuses as well — validation is the message, the
 * Action is the guarantee).
 */
final class SaveLiaRecordRequest extends FormRequest
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
        $limit = max(200, (int) config('compliance.lia.section_limit', 4000));
        $minimum = max(1, (int) config('compliance.lia.section_minimum', 40));
        $signing = $this->boolean('sign');

        $section = $signing
            ? ['required', 'string', 'min:'.$minimum, 'max:'.$limit]
            : ['nullable', 'string', 'max:'.$limit];

        return [
            'market' => ['required', Rule::in(Market::values())],
            'purpose' => $section,
            'necessity' => $section,
            'balancing' => $section,
            'safeguards' => ['nullable', 'string', 'max:'.$limit],
            'signals_scope' => ['nullable', 'string', 'max:'.$limit],
            'sign' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'purpose.min' => __('Say why you are processing this data and whose interest it serves — this is the record that justifies holding it.'),
            'necessity.min' => __('Say why this is the way to achieve that purpose.'),
            'balancing.min' => __('Weigh the prospect’s interests against yours. This is the test a regulator reads first.'),
        ];
    }

    public function liaData(): LiaRecordData
    {
        return LiaRecordData::fromValidated($this->validated());
    }
}
