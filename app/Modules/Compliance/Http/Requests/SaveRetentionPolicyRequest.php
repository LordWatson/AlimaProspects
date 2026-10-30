<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Requests;

use App\Modules\Compliance\Data\RetentionPolicyData;
use App\Modules\Compliance\Services\DataRetention;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Validates a team's retention windows.
 *
 * The bounds come from {@see DataRetention}, which is also what the sweep reads — so the number a
 * form will accept and the number the sweep will apply cannot drift apart. The Action clamps again
 * and refuses inverted windows: validation is the message a member reads, the Action is the
 * guarantee.
 */
final class SaveRetentionPolicyRequest extends FormRequest
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
        $retention = app(DataRetention::class);

        $window = ['required', 'integer', 'min:'.$retention->minDays(), 'max:'.$retention->maxDays()];

        return [
            'is_enabled' => ['required', 'boolean'],
            'uncontacted_lead_days' => $window,
            'contacted_lead_days' => $window,
        ];
    }

    public function policyData(): RetentionPolicyData
    {
        return RetentionPolicyData::fromValidated($this->validated());
    }
}
