<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Enums\TokenAbility;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Guest request: exchange credentials for a Sanctum personal access token (mobile sign-in).
 */
final class IssueApiTokenRequest extends FormRequest
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
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device_name' => ['required', 'string', 'max:255'],
            // Checked against the catalogue, not merely "a string": an ability nothing enforces
            // is a scope that reads as a restriction and isn't one (Phase 17). Omit the key
            // entirely for a full-access token.
            'abilities' => ['sometimes', 'array'],
            'abilities.*' => ['string', Rule::in(TokenAbility::issuable())],
        ];
    }
}
