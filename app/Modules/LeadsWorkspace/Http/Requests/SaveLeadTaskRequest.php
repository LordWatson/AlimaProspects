<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Data\LeadTaskData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a task. The assignee has to be a member of the caller's **own** team, which the
 * existence check scopes through the `team_user` pivot — otherwise a task could be pinned on
 * someone outside the team entirely.
 */
final class SaveLeadTaskRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:160'],
            'assigned_to' => [
                'nullable',
                'integer',
                Rule::exists('team_user', 'user_id')->where('team_id', $this->user()?->currentTeamId()),
            ],
            'due_at' => ['nullable', 'date'],
            // A reminder with no due date is still a reminder ("chase this on Friday"), so the two
            // are validated independently rather than one requiring the other.
            'remind_at' => ['nullable', 'date'],
        ];
    }

    public function taskData(): LeadTaskData
    {
        return LeadTaskData::fromValidated($this->validated());
    }
}
