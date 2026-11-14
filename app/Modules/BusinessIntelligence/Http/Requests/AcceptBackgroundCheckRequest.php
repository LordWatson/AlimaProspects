<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Http\Requests;

use App\Modules\BusinessIntelligence\Policies\BusinessIntelReportPolicy;
use App\Modules\BusinessIntelligence\Support\ProfileMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates the accept choices for a background-check report. Authorisation is handled in the
 * controller via {@see BusinessIntelReportPolicy}.
 */
final class AcceptBackgroundCheckRequest extends FormRequest
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
            'apply_to_profile' => ['boolean'],
            'seed_icp' => ['boolean'],
            // Fields where the team's own entry differs from the finding and the member chose the
            // finding. Anything not named here keeps what the team typed.
            'overrides' => ['array'],
            'overrides.*' => ['string', Rule::in(ProfileMapping::fields())],
        ];
    }
}
