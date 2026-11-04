<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Actions\SubmitSignalDefinitionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Offering a signal type to the shared library (S15 task 4).
 *
 * One optional field. Everything that decides whether a type *may* be offered — a preset cannot be
 * offered back, a type with no searches is nothing to start from, one open offer per type — lives in
 * {@see SubmitSignalDefinitionAction}, because the API posts here too and a rule in a request is a
 * rule only one caller obeys.
 *
 * The note is **prose**, which makes it the exception to this codebase's keys-not-sentences rule and
 * for the obvious reason: there is nothing to translate about one person's own words to another.
 * Bounded in characters, because it reaches a review screen and an operator reading an essay is an
 * operator who stops reading.
 */
final class SubmitSignalDefinitionRequest extends FormRequest
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
            'note' => ['nullable', 'string', 'max:'.SubmitSignalDefinitionAction::MAX_NOTE],
        ];
    }

    public function note(): ?string
    {
        $note = $this->validated('note');

        if (! is_string($note)) {
            return null;
        }

        $trimmed = Str::squish($note);

        return $trimmed === '' ? null : $trimmed;
    }
}
