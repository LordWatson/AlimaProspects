<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Data\CreateLeadData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a hand-added lead. The rules come from {@see CreateLeadData::rules()} so the web form
 * and `/api/v1` accept exactly the same thing, with one addition that can only be checked here:
 * `pipeline_stage_id` is scoped to the member's own team, so a tampered form can't file a lead in
 * another team's column. Authorisation lives in the controller via `LeadPolicy::create`.
 *
 * `company_name` is the only required field — see {@see CreateLeadData} for why the rest are not.
 */
final class StoreLeadRequest extends FormRequest
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
            ...CreateLeadData::rules(),
            'pipeline_stage_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    public function lead(): CreateLeadData
    {
        return CreateLeadData::fromValidated($this->validated());
    }
}
