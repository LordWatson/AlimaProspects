<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Models\RetentionPolicy;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

/**
 * Cross-tenant compliance activity — **retention runs, exports, erasures and assessments**
 * (Phase 19c), read-only.
 *
 * This closes the pointer `compliance-privacy.md` has carried since Phase 15: "No admin view over
 * compliance activity. A read-only operator view of retention runs, exports and erasures is
 * Phase 19, alongside `signal_runs`."
 *
 * ## Why it is a view over `audit_logs` rather than over tables of its own
 *
 * Because that is where the events already are, and deliberately so. A retention sweep, an export
 * and an erasure each write one audit entry with counts and ids in its properties
 * (`compliance.retention_swept`, `compliance.team_data_exported`,
 * `compliance.subject_data_erased`) and — equally deliberately — **no copy of what was erased or
 * exported**. Giving compliance a second activity table would either duplicate those entries or
 * tempt somebody into recording the contents, which is the one thing `docs/compliance.md` §3.9
 * forbids. So the operator view reads the trail.
 *
 * ## Read-only, structurally
 *
 * There is no Action here and no write route anywhere for these three. An erasure is the *tenant's*
 * decision as data controller — which is why `compliance.dsar.erase` is on the impersonation
 * blocklist too — and an operator honouring somebody else's subject request from the panel would
 * be making a controller's decision for them. The LIA records and retention policies are read the
 * same way and for the same reason: the declaration **is** the legal record, and editing one is
 * signing somebody else's paperwork.
 */
final class ComplianceActivity
{
    /** The audited compliance events this view is about, newest first. */
    public const array ACTIONS = [
        'compliance.retention_swept',
        'compliance.team_data_exported',
        'compliance.subject_data_erased',
        'compliance.subject_data_located',
        'compliance.opt_out_recorded',
        'compliance.lia_saved',
        'compliance.lia_withdrawn',
        'compliance.retention_policy_saved',
    ];

    /**
     * @param  array<string, mixed>  $filters  action, team_id
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, DateRange $range, int $perPage = 50): LengthAwarePaginator
    {
        $query = AuditLog::query()
            ->withoutGlobalScopes()
            ->with(['user:id,name', 'team:id,name'])
            ->whereIn('action', self::ACTIONS);

        $range->constrain($query, 'audit_logs.created_at');

        $action = $filters['action'] ?? null;

        if (is_string($action) && in_array($action, self::ACTIONS, true)) {
            $query->where('action', $action);
        }

        if (is_numeric($filters['team_id'] ?? null)) {
            $query->where('team_id', (int) $filters['team_id']);
        }

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * @param  LengthAwarePaginator<int, AuditLog>  $page
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $page): array
    {
        return array_values(array_map(static fn (AuditLog $entry): array => [
            'id' => (int) $entry->getKey(),
            'action' => $entry->action,
            'at' => $entry->created_at?->toIso8601String(),
            'team' => $entry->team?->name,
            'team_id' => $entry->team_id,
            // Null for the retention sweep and the scheduled notices, which run with nobody behind
            // them. Shown as "system" on the screen rather than left blank, because "who did this"
            // having no answer is itself the answer.
            'actor' => $entry->user?->name,
            // The properties as the owning Action wrote them: counts and ids, never a copy of what
            // was swept or exported. See the class note.
            'properties' => $entry->properties,
        ], $page->items()));
    }

    /**
     * Every tenant's legitimate-interest assessments, with whichever is in force first.
     *
     * The figure an operator is actually looking for here is a **lapsed** assessment: without one
     * in force for its market a tenant cannot source at all (`LegitimateInterestGate`), so a
     * support ticket that says "discovery stopped working" is very often this screen.
     *
     * @return list<array<string, mixed>>
     */
    public function assessments(int $limit = 200): array
    {
        return array_values(LiaRecord::query()
            ->withoutGlobalScopes()
            ->with('team:id,name')
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [LiaStatus::Active->value])
            ->orderBy('review_due_at')
            ->limit($limit)
            ->get()
            ->map(static fn (LiaRecord $record): array => [
                'id' => (int) $record->getKey(),
                'team' => $record->team?->name,
                'team_id' => $record->team_id,
                'market' => $record->market->value,
                'status' => $record->status->value,
                'version' => $record->version,
                'completed_at' => $record->completed_at?->toIso8601String(),
                'review_due_at' => $record->review_due_at?->toIso8601String(),
                'withdrawn_at' => $record->withdrawn_at?->toIso8601String(),
                'in_force' => $record->isInForce(),
                'expired' => $record->hasExpired(),
                'review_due_in_days' => $record->reviewDueInDays(),
            ])
            ->all());
    }

    /**
     * Retention policies across tenants — what each one has asked us to keep, and for how long.
     *
     * @return list<array<string, mixed>>
     */
    public function retentionPolicies(int $limit = 500): array
    {
        return array_values(RetentionPolicy::query()
            ->withoutGlobalScopes()
            ->with('team:id,name')
            ->orderBy('team_id')
            ->limit($limit)
            ->get()
            ->map(static fn (RetentionPolicy $policy): array => [
                'id' => (int) $policy->getKey(),
                'team' => $policy->team?->name,
                'team_id' => $policy->team_id,
                'is_enabled' => $policy->is_enabled,
                'uncontacted_lead_days' => $policy->uncontacted_lead_days,
                'contacted_lead_days' => $policy->contacted_lead_days,
                'last_swept_at' => $policy->last_swept_at?->toIso8601String(),
                'last_deleted_count' => $policy->last_deleted_count,
                'last_anonymised_count' => $policy->last_anonymised_count,
            ])
            ->all());
    }

    /**
     * The filter control's options — the eight audited compliance actions, nothing more.
     *
     * A closed list rather than `DISTINCT action`, unlike the general audit viewer: these eight are
     * the events the compliance view is *for*, and a ninth appearing in the table should be a
     * deliberate addition to {@see self::ACTIONS} rather than a row that quietly starts showing up
     * in a legal-facing screen.
     *
     * @return list<string>
     */
    public function actions(): array
    {
        return self::ACTIONS;
    }
}
