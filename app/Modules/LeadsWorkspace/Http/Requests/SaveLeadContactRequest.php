<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a contact a member types in themselves.
 *
 * Every field is optional on its own, but the row must carry **something to reach them by** — a
 * name with no address and no number can never be used, and the panel it appears on exists to
 * answer "how do I contact this company".
 */
final class SaveLeadContactRequest extends FormRequest
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
            'name' => ['nullable', 'string', 'max:255'],
            'title' => ['nullable', 'string', 'max:255'],
            // `email` and not `email:rfc,dns`: the member is telling us an address they already
            // have, and a DNS lookup in a form submit would refuse a domain registered this
            // morning. The MX check is Enrichment's, and it happens after the row exists.
            'email' => ['nullable', 'email', 'max:255', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:64', 'required_without:email'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.required_without' => __('Add an email address or a phone number.'),
            'phone.required_without' => __('Add an email address or a phone number.'),
        ];
    }

    /**
     * @return array{name: ?string, title: ?string, email: ?string, phone: ?string}
     */
    public function contact(): array
    {
        return [
            'name' => $this->trimmed('name'),
            'title' => $this->trimmed('title'),
            'email' => $this->trimmed('email'),
            'phone' => $this->trimmed('phone'),
        ];
    }

    private function trimmed(string $key): ?string
    {
        $value = $this->validated($key);

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
