<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Data\SubjectRequestData;
use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Core\Support\ContactIdentity;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the public privacy-request form (PL5).
 *
 * **Unauthenticated**, so `authorize()` is true and every other guard is somewhere else: the
 * throttle in the provider, the challenge in the controller, and the verification email as the real
 * authentication. Nothing a person types here is believed until they click the link.
 *
 * The email is normalised through {@see ContactIdentity} before it reaches the Action, because every
 * downstream use of it is an identity comparison — the duplicate check, the global suppression
 * entry, the per-team locate. One stored as typed would suppress one identity and search for
 * another.
 *
 * `details` is bounded but generous: a rectification request has to be able to say what is wrong.
 */
final class SubmitSubjectRequestRequest extends FormRequest
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
            'type' => ['required', Rule::in(SubjectRequestType::values())],
            'email' => ['required', 'string', 'email', 'max:254'],
            'name' => ['nullable', 'string', 'max:120'],
            'company' => ['nullable', 'string', 'max:160'],
            'details' => ['nullable', 'string', 'max:2000'],
            'phone' => ['nullable', 'string', 'max:40'],
            // Present only when a challenge is configured; the controller decides whether it
            // matters, because "is Turnstile set up" is a deployment fact and not a validation
            // rule.
            'turnstile_token' => ['nullable', 'string', 'max:4000'],
        ];
    }

    /**
     * Named `toData()` rather than `data()`: Laravel's own `InteractsWithData::data()` is a getter
     * with a different signature, and overriding it would be a quiet trap for the next reader.
     */
    public function toData(): SubjectRequestData
    {
        return new SubjectRequestData(
            type: SubjectRequestType::from((string) $this->validated('type')),
            // Falls back to the raw value only if the normaliser refuses it, which `email`
            // validation has already ruled out — so this is belt and braces rather than a path.
            email: ContactIdentity::email((string) $this->validated('email')) ?? (string) $this->validated('email'),
            name: $this->text('name'),
            company: $this->text('company'),
            details: $this->text('details'),
            phone: $this->text('phone'),
        );
    }

    public function challengeToken(): ?string
    {
        return $this->text('turnstile_token');
    }

    private function text(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
