<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Models\Signal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a conversion. The rules come from {@see ConvertSignalData::rules()} so the web form
 * (S5) and the API (S6) accept exactly the same thing, with one addition that can only be checked
 * here: `pipeline_stage_id` is scoped to the member's own team, so a tampered form can't file a
 * lead in another team's column. Authorisation lives in the controller via `SignalPolicy`.
 *
 * `company_name` is **required** — the one rule the whole screen hangs off. A relocation signal
 * often names a person and no company, and a lead in this product is a company, so the form asks
 * for the person's business or holding entity rather than inventing one.
 */
final class ConvertSignalRequest extends FormRequest
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
            ...ConvertSignalData::rules(),
            'pipeline_stage_id' => [
                'nullable',
                'integer',
                Rule::exists('pipeline_stages', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
        ];
    }

    public function conversion(Signal $signal): ConvertSignalData
    {
        return ConvertSignalData::fromValidated($this->validated(), $signal);
    }
}
