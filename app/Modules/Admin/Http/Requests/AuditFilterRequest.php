<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Requests;

use App\Modules\Admin\Enums\AuditCategory;
use App\Modules\Admin\Services\Platform\AuditTrail;
use App\Modules\Admin\Support\PlatformWindow;
use App\Modules\Reporting\Enums\ReportRange;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Validation\Rule;

/**
 * The audit viewer's filters, on top of the window (Phase 19c).
 *
 * Extends {@see PlatformWindowRequest} so the window is cut in UTC by the same code every platform
 * screen uses — a second copy of that decision is a second chance to get it wrong.
 *
 * `action` is validated as a bounded string rather than against a list: the actions in the table
 * are written by eighteen modules and a closed list here would be out of date by one release.
 * {@see AuditTrail} matches it exactly, so an action that does not exist returns nothing rather
 * than everything.
 */
final class AuditFilterRequest extends PlatformWindowRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return parent::rules() + [
            'action' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', Rule::enum(AuditCategory::class)],
            'user_id' => ['nullable', 'integer', 'min:1'],
            'team_id' => ['nullable', 'integer', 'min:1'],
            'subject_type' => ['nullable', 'string', 'max:64'],
            'subject_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /**
     * The window, defaulting to the **last 7 days** rather than thirty.
     *
     * Different from every other platform screen on purpose. `audit_logs` is the busiest table in
     * the product — every note, stage move and tag change writes a row — so a thirty-day default
     * would open the viewer on a page of last month's noise. An operator asking an audit question
     * almost always means "today or yesterday".
     */
    public function window(): DateRange
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        $range = ReportRange::tryFrom((string) ($validated['range'] ?? '')) ?? ReportRange::Last7Days;

        return PlatformWindow::for(
            $range,
            isset($validated['from']) ? (string) $validated['from'] : null,
            isset($validated['to']) ? (string) $validated['to'] : null,
        );
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
            'category' => $validated['category'] ?? null,
            'user_id' => $validated['user_id'] ?? null,
            'team_id' => $validated['team_id'] ?? null,
            'subject_type' => $validated['subject_type'] ?? null,
            'subject_id' => $validated['subject_id'] ?? null,
        ];
    }
}
