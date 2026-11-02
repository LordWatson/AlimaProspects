<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Services\Platform\ComplianceActivity;
use Illuminate\Validation\Rule;

/**
 * The cross-tenant compliance activity view's filters (Phase 19c).
 *
 * `action` is validated against {@see ComplianceActivity::ACTIONS} — a **closed** list, unlike the
 * general audit viewer's open one. These eight are the events this screen is for, and a ninth
 * appearing in a legal-facing view should be a deliberate addition to that constant rather than
 * something that starts showing up because a module named an action with the same prefix.
 */
final class ComplianceActivityRequest extends PlatformWindowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'action' => ['nullable', Rule::in(ComplianceActivity::ACTIONS)],
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
            'action' => $validated['action'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
        ];
    }
}
