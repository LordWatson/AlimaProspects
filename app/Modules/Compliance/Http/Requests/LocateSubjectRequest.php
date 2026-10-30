<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a data-subject search (docs/compliance.md §3.5).
 *
 * One field, and the only rule that matters is the minimum length: a two-character query is not
 * about one person, and answering it would be a disclosure rather than a response. The service
 * refuses as well — validation is the message, the service is the guarantee.
 *
 * `confirm` is the erasure's second gate. Erasure is irreversible and the screen shows what will go
 * before it goes, so the request that performs it has to say "yes, that list" rather than being one
 * mis-click away from a search.
 */
final class LocateSubjectRequest extends FormRequest
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
        $minimum = max(1, (int) config('compliance.dsar.min_query_length', 3));

        return [
            'query' => ['required', 'string', 'min:'.$minimum, 'max:254'],
            'confirm' => ['sometimes', 'accepted'],
            // The records the member actually saw and ticked. Required in substance for a name
            // match — see `EraseSubjectDataAction` — and harmless for an exact identity.
            'records' => ['sometimes', 'array', 'max:500'],
            'records.*' => ['integer', 'min:1'],
        ];
    }

    public function subjectQuery(): string
    {
        return trim((string) $this->validated('query'));
    }

    /**
     * The confirmed record ids, or null when none were sent.
     *
     * @return list<int>|null
     */
    public function confirmedIds(): ?array
    {
        $records = $this->validated('records');

        if (! is_array($records)) {
            return null;
        }

        return array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $records)));
    }
}
