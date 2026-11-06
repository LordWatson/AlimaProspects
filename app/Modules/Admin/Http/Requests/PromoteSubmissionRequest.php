<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Signals\Actions\PromoteSignalDefinitionAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * Answering a library submission (S15 task 4).
 *
 * **The note is required**, and that is a product decision rather than a technical one: a rejection
 * with no reason is the platform taking somebody's contribution and saying nothing back, and a
 * promotion with no note leaves the team with no idea what we changed. The Action accepts a null
 * note — a future caller may genuinely have no words, for a type whose team has vanished — so the
 * requirement lives here, at the one door a person comes through.
 *
 * `key` is optional and means something precise when given: *fold this into that existing preset*.
 * Left out, the Action derives a key from the submitted type and never lets it collide, because two
 * teams choosing the same obvious name must not silently overwrite the library.
 */
final class PromoteSubmissionRequest extends FormRequest
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
            'note' => ['required', 'string', 'min:3', 'max:'.PromoteSignalDefinitionAction::MAX_NOTE],
            // Shaped like every other preset key: lower case, underscores, bounded.
            'key' => ['nullable', 'string', 'max:48', 'regex:/^[a-z0-9_]+$/'],
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

    public function key(): ?string
    {
        $key = $this->validated('key');

        if (! is_string($key)) {
            return null;
        }

        $trimmed = trim($key);

        return $trimmed === '' ? null : $trimmed;
    }
}
