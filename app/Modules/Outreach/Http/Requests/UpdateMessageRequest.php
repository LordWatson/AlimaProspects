<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates an edit to a draft's text — the member rewriting what the model wrote, which is the
 * expected workflow rather than an exception to it.
 *
 * Only the words are editable. Channel, tone, locale and the chosen contact are not: changing any
 * of them changes what the guard has already decided about, and a member who wants a different
 * channel is asking for a different draft.
 */
final class UpdateMessageRequest extends FormRequest
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
            'subject' => ['nullable', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:8000'],
        ];
    }

    public function subject(): ?string
    {
        $subject = $this->validated('subject');

        return is_string($subject) && trim($subject) !== '' ? trim($subject) : null;
    }

    public function body(): string
    {
        return trim((string) $this->validated('body'));
    }
}
