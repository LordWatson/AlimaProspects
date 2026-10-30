<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates who is working a lead — the whole set, so the screen sends what it wants and the Action
 * works out the difference. Each id has to be a member of the caller's own team.
 */
final class SetLeadAssigneesRequest extends FormRequest
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
            'user_ids' => ['present', 'array', 'max:10'],
            'user_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('team_user', 'user_id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    /**
     * @return list<int>
     */
    public function userIds(): array
    {
        /** @var array<int, mixed> $ids */
        $ids = $this->validated('user_ids', []);

        return array_values(array_map(static fn (mixed $id): int => (int) $id, $ids));
    }
}
