<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalWatchlistData;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Validates a batch of names being added to a watchlist (S10).
 *
 * **Bounded to the list's own cap** rather than to some round number: a request that could hold more
 * entries than a list may contain is a request whose tail is guaranteed to be discarded, and the
 * member would have no way to know which half took. The Action then skips what would overflow and
 * reports it, so the screen can say the list is full.
 *
 * The web screen posts a textarea, one name per line, and the API posts the structured form. Both
 * arrive here as `entries[]` — the shaping of the textarea is the controller's job, so the
 * validation rules describe one shape rather than two.
 */
final class AddWatchlistEntriesRequest extends FormRequest
{
    /**
     * Whether this request arrived as a textarea rather than as a structured list.
     *
     * Remembered because it decides where a failure is **shown**: see {@see failedValidation()}.
     */
    private bool $arrivedAsText = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Accept a textarea as well as the structured list.
     *
     * The screen's source for this is a spreadsheet column or an email, so it posts `names` — one
     * per line — and the API posts `entries[]`. Shaping one into the other **here** rather than in
     * the controller is what keeps a single definition of "one name per line": the rules below then
     * describe one shape, and a test of the splitting does not have to go through a screen.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('entries')) {
            return;
        }

        // No `entries` means the textarea posted, **including when it posted nothing**: the global
        // `TrimStrings` and `ConvertEmptyStringsToNull` middleware turn a box holding only blank
        // lines into `null`, so testing for a string here would have sent exactly that case — the
        // commonest mistake somebody can make on this screen — to an error about `entries`, a field
        // their screen does not have.
        $this->arrivedAsText = true;

        $names = $this->input('names');
        $lines = is_string($names) ? preg_split('/\r\n|\r|\n/', $names) ?: [] : [];

        $entries = [];

        foreach ($lines as $line) {
            $name = Str::squish($line);

            if ($name !== '') {
                $entries[] = ['name' => $name];
            }
        }

        $this->merge(['entries' => $entries]);
    }

    /**
     * Put a textarea's failures on the textarea.
     *
     * Validation sees `entries.3.name`, because that is the shape the rules describe — and a
     * member who pasted forty lines into one box cannot be shown an error against a row index in
     * a payload their screen never built. So for the textarea case the bag is collapsed onto
     * `names`, the field they actually typed in; the API, which really did post `entries[]`, keeps
     * the indexed keys that tell a caller which element was wrong.
     */
    protected function failedValidation(Validator $validator): void
    {
        if (! $this->arrivedAsText) {
            parent::failedValidation($validator);
        }

        throw ValidationException::withMessages([
            'names' => $validator->errors()->all(),
        ])->errorBag($this->errorBag)->redirectTo($this->getRedirectUrl());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return SignalWatchlistData::entryRules();
    }

    /**
     * @return list<array{name: string, entity_type?: string|null, aliases?: list<string>|null, domain?: string|null}>
     */
    public function entries(): array
    {
        /** @var list<array{name: string, entity_type?: string|null, aliases?: list<string>|null, domain?: string|null}> $entries */
        $entries = $this->validated()['entries'];

        return $entries;
    }
}
