<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CreateTeamRequest extends FormRequest
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
            'timezone' => ['sometimes', 'string', 'timezone'],
            'locale' => ['sometimes', Rule::in(['en', 'ar'])],
            'market' => ['sometimes', Rule::in(['uk', 'uae'])],
        ];
    }
}
