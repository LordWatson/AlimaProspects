<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Signals\Enums\SignalRunStatus;
use Illuminate\Validation\Rule;

/**
 * The cross-tenant `signal_runs` view's filters (Phase 19c).
 *
 * Read-only by construction: this request has no fields that change anything, and the view it
 * serves has no write route. See `SignalRunOversight` for why an operator must not re-run somebody
 * else's watch.
 */
final class SignalRunFilterRequest extends PlatformWindowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'status' => ['nullable', Rule::enum(SignalRunStatus::class)],
            'team_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function filters(): array
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        return [
            'status' => $validated['status'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
        ];
    }
}
