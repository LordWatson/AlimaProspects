<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Requests;

use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Enums\PlaybookActionKind;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a playbook preview (S14).
 *
 * **Conditions only, and the steps as bare kinds.** The preview evaluates and executes nothing, so
 * it has no use for a step's parameters — and asking for them would mean a member could not preview
 * a rule until they had finished filling in every stage and title, which is the wrong way round:
 * the first question is whether the conditions match the right stories at all.
 *
 * The kinds are still wanted, because they decide whether the preview warns about a per-job spend
 * ceiling. A rule that matches ninety signals is fine until it converts them.
 */
final class PreviewSignalPlaybookRequest extends FormRequest
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
            ...SignalPlaybookConditions::rules(),
            'kinds' => ['nullable', 'array', 'max:'.max(1, (int) config('signals.playbooks.max_actions', 8))],
            'kinds.*' => ['string', 'in:'.implode(',', PlaybookActionKind::values())],
        ];
    }

    public function conditions(): SignalPlaybookConditions
    {
        $conditions = $this->validated('conditions') ?? [];

        return SignalPlaybookConditions::fromStored(is_array($conditions) ? $conditions : []);
    }

    /**
     * @return list<PlaybookActionKind>
     */
    public function kinds(): array
    {
        $kinds = $this->validated('kinds') ?? [];

        if (! is_array($kinds)) {
            return [];
        }

        $resolved = [];

        foreach ($kinds as $kind) {
            $case = is_string($kind) ? PlaybookActionKind::tryFrom($kind) : null;

            if ($case instanceof PlaybookActionKind && ! in_array($case, $resolved, strict: true)) {
                $resolved[] = $case;
            }
        }

        return $resolved;
    }
}
