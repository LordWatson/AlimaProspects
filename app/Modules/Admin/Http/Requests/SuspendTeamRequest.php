<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspending a tenant needs a reason, and the reason is required (Phase 19a).
 *
 * Not an optional note. A suspension with no reason is one nobody can safely lift: the next
 * operator to look finds an account switched off by a colleague who has gone home, and the only
 * safe thing to do with it is leave it alone — which means the customer stays switched off because
 * of a missing sentence.
 *
 * The minimum length is there for the same reason: "abuse" is not a reason, it is a category.
 */
final class SuspendTeamRequest extends FormRequest
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
            'reason' => ['required', 'string', 'min:10', 'max:500'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.min' => 'Say what happened in enough detail that a colleague could decide whether to lift it.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->validated('reason'));
    }
}
