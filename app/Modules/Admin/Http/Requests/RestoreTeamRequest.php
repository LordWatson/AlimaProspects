<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Lifting a suspension. The note is optional, unlike the reason for applying one (Phase 19a).
 *
 * The asymmetry is deliberate. Switching a customer off needs a justification somebody else can
 * read; switching them back on is the default state of a tenant, and demanding a sentence for it
 * would make the easy, correct action the slower one.
 */
final class RestoreTeamRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        return is_string($note) && trim($note) !== '' ? trim($note) : null;
    }
}
