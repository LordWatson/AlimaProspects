<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Data\BulkLeadRequestData;
use App\Modules\LeadsWorkspace\Enums\BulkLeadAction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a bulk action over a selection of leads.
 *
 * Each action's extra field is **required only when that action is the one asked for**, so a
 * caller can't move leads to a stage while claiming to tag them, and can't be asked for a stage id
 * when tagging. The lead-id count is capped at `leads-workspace.max_bulk_leads` here as well as in
 * the Action, so a caller is told rather than silently truncated.
 */
final class BulkLeadActionRequest extends FormRequest
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
        $teamId = $this->user()?->currentTeamId();
        $max = max(1, (int) config('leads-workspace.max_bulk_leads', 500));
        $action = BulkLeadAction::tryFrom((string) $this->input('action'));

        return [
            'action' => ['required', Rule::enum(BulkLeadAction::class)],
            'lead_ids' => ['required', 'array', 'min:1', 'max:'.$max],
            'lead_ids.*' => ['integer', 'distinct', 'min:1'],

            'tags' => [Rule::requiredIf($action?->requires() === 'tags'), 'array', 'max:20'],
            'tags.*' => ['string', 'max:40'],

            'user_id' => [
                Rule::requiredIf($action?->requires() === 'user_id'),
                'integer',
                Rule::exists('team_user', 'user_id')->where('team_id', $teamId),
            ],

            'pipeline_stage_id' => [
                Rule::requiredIf($action?->requires() === 'pipeline_stage_id'),
                'integer',
                Rule::exists('pipeline_stages', 'id')->where('team_id', $teamId),
            ],
        ];
    }

    public function bulkData(): BulkLeadRequestData
    {
        return BulkLeadRequestData::fromValidated($this->validated());
    }
}
