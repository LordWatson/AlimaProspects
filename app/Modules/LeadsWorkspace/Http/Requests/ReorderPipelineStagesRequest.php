<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a board reorder: the stage ids in the order they should appear. Ids that aren't the
 * team's own are dropped by the Action rather than rejected here, so a stale board still reorders
 * what it knew about.
 */
final class ReorderPipelineStagesRequest extends FormRequest
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
        $max = max(1, (int) config('leads-workspace.max_stages', 20));

        return [
            'stages' => ['required', 'array', 'min:1', 'max:'.$max],
            'stages.*' => ['integer', 'distinct', 'min:1'],
        ];
    }

    /**
     * @return list<int>
     */
    public function orderedIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('stages', []);

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
