<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Requests;

use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Policies\DiscoveryRunPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validates a "start discovery" payload. Authorisation lives in the controller via
 * {@see DiscoveryRunPolicy}. `sources` is one or more live providers (the run fans out to one
 * {@see DiscoveryRun} per source); `icp_id`, if given, is checked to
 * belong to the team. `limit` is bounded by config.
 */
final class StartDiscoveryRequest extends FormRequest
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
        $maxLimit = (int) config('lead-sourcing.defaults.max_limit', 100);
        $available = LeadSource::availableValues();

        return [
            'sources' => ['required', 'array', 'min:1', 'max:'.count($available)],
            'sources.*' => ['string', 'distinct', Rule::in($available)],
            'icp_id' => [
                'nullable',
                'integer',
                // Scope the existence check to the current team so you can't point at another team's ICP.
                Rule::exists('icps', 'id')->where('team_id', $this->user()?->currentTeamId()),
            ],
            'terms' => ['nullable', 'string', 'max:200'],
            'location' => ['nullable', 'string', 'max:120'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$maxLimit],
        ];
    }
}
