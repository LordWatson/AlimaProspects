<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateTeamRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'timezone'],
            'locale' => ['required', Rule::in(['en', 'ar'])],
            'market' => ['required', Rule::in(['uk', 'uae'])],
            // Bounded by the same range the slider offers (PL1): a value outside it would hide a
            // whole team's leads, or none of them, with no way back from the screen.
            'min_lead_confidence' => [
                'nullable',
                'integer',
                'min:'.(int) config('evidence.min_threshold', 30),
                'max:'.(int) config('evidence.max_threshold', 90),
            ],
        ];
    }
}
