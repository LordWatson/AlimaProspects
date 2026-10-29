<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Http\Requests;

use App\Modules\BusinessIntelligence\Policies\BusinessIntelReportPolicy;
use Illuminate\Foundation\Http\FormRequest;

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
        ];
    }
}
