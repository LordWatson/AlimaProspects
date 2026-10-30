<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use App\Modules\Outreach\Actions\SuppressIdentityAction;
use App\Modules\Outreach\Enums\SuppressionType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates an entry a team is adding to its own do-not-contact list.
 *
 * Note what the rules do *not* include: a `scope`. A member cannot choose to write a global entry —
 * that scope belongs to the platform acting on a person's own words (see
 * {@see SuppressIdentityAction}), and offering it as a field would
 * mean validating a request that must never be honoured.
 *
 * The value's shape is checked by the type's own normaliser rather than here: `email` would reject
 * a pasted URL for a domain entry, and `regex` can't express "digits, however they are spaced".
 * An unusable value comes back as a domain exception the member can read.
 */
final class SuppressIdentityRequest extends FormRequest
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
            'type' => ['required', Rule::in(SuppressionType::values())],
            'value' => ['required', 'string', 'max:254'],
            'note' => ['nullable', 'string', 'max:180'],
        ];
    }

    public function type(): SuppressionType
    {
        return SuppressionType::from((string) $this->validated('type'));
    }

    public function value(): string
    {
        return (string) $this->validated('value');
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
