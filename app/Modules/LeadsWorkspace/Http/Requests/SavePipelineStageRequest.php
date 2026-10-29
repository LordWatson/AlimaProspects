<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Requests;

use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Policies\PipelineStagePolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a board column. Authorisation lives in the controller via {@see PipelineStagePolicy}.
 * The slug isn't accepted from the caller: it is derived once on creation and never changes, so a
 * rename can't break a saved view or a report that referred to the stage.
 */
final class SavePipelineStageRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:60'],
            'outcome' => ['nullable', Rule::enum(StageOutcome::class)],
            'is_default' => ['nullable', 'boolean'],
        ];
    }

    public function stageName(): string
    {
        return trim((string) $this->validated('name'));
    }

    public function outcome(): ?StageOutcome
    {
        $outcome = $this->validated('outcome');

        return is_string($outcome) && $outcome !== '' ? StageOutcome::from($outcome) : null;
    }

    public function isDefault(): bool
    {
        return (bool) $this->validated('is_default', false);
    }
}
