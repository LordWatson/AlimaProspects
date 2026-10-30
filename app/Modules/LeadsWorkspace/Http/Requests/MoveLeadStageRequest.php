<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadSourcing\Policies\LeadPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a drag between board columns. Authorisation lives in the controller via
 * {@see LeadPolicy::work()}; the stage's existence check is scoped to the caller's team, so you
 * can't move your lead into another team's column.
 */
final class MoveLeadStageRequest extends FormRequest
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
            'pipeline_stage_id' => [
                'required',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    public function stageId(): int
    {
        return (int) $this->validated('pipeline_stage_id');
    }
}
