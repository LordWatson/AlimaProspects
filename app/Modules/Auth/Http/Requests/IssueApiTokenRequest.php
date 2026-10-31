<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Requests;

use App\Modules\Auth\Data\TokenExchangeData;
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
            // is a scope that reads as a restriction and isn't one (Phase 17).
            //
            // `min:1` matters more than it looks. An **absent** key means "no scope asked for" and
            // issues a full-access token, which is the right default; an **empty array** is a
            // caller that meant to limit the token and named nothing, and silently handing that
            // request full access would be the opposite of what it asked for. So the two are told
            // apart here rather than collapsed.
            'abilities' => ['sometimes', 'array', 'min:1'],
            'abilities.*' => ['string', Rule::in(TokenAbility::issuable())],
            // The second factor, when the account has one (Phase 18). Both are optional here
            // because a client cannot know whether this account needs one until it has asked: a
            // first post without either gets a 423 naming what to send next, and whether what
            // came back is enough is the TwoFactorChallenge service's answer, not a rule's.
            // Constraining `code` to six digits would also refuse a recovery code typed into the
            // wrong box with the wrong sentence.
            'code' => ['nullable', 'string', 'max:64'],
            'recovery_code' => ['nullable', 'string', 'max:128'],
        ];
    }

    public function exchange(): TokenExchangeData
    {
        /** @var list<string> $abilities */
        $abilities = $this->validated('abilities', ['*']);

        return new TokenExchangeData(
            email: (string) $this->validated('email'),
            password: (string) $this->validated('password'),
            deviceName: (string) $this->validated('device_name'),
            abilities: $abilities,
            code: $this->stringOrNull('code'),
            recoveryCode: $this->stringOrNull('recovery_code'),
        );
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) ? $value : null;
    }
}
