<?php

declare(strict_types=1);

namespace App\Modules\Admin\Services\Platform;

use App\Modules\Admin\Enums\AuditCategory;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Reporting\Support\DateRange;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/**
 * The cross-tenant audit viewer (Phase 19c).
 *
 * **Read-only, and structurally so.** `audit_logs` is append-only (see {@see AuditLog}), so this
 * service exposes queries and nothing else — there is no update, no delete, and the resource
 * descriptor forbids both. An audit trail an operator can edit is not an audit trail, and the
 * protection has to be the absence of a code path rather than a permission somebody could grant.
 *
 * Two things it does that a resource index cannot:
 *
 * - **Categories.** Impersonation sessions and operator reads are the entries that matter when
 *   reviewing operator behaviour, and they are four rows in ten thousand. {@see AuditCategory}
 *   makes them a filter rather than something you find by already knowing the action string.
 * - **A window.** The registry's filters are equality-only by design, and "what did this operator
 *   do last Tuesday" needs a range. It reuses {@see DateRange} and its UTC rule, so the panel's
 *   windows mean the same thing everywhere.
 *
 * The rows it returns carry **ids, actions and properties as stored** — the properties were
 * written by {@see AdminAudit} and its callers under the rule that an
 * audit entry about personal data must not be a copy of it. This class adds nothing and resolves
 * nothing that was deliberately left out.
 */
final class AuditTrail
{
    /**
     * @param  array<string, mixed>  $filters  action, category, user_id, team_id, subject_type, subject_id
     * @return LengthAwarePaginator<int, AuditLog>
     */
    public function paginate(array $filters, DateRange $range, int $perPage = 50): LengthAwarePaginator
    {
        $query = AuditLog::query()
            // Explicit, even though `PlatformContext` has already cleared the team scope: this is
            // the one table whose whole value is that it shows entries from **every** tenant, and
            // a scope silently applied by a future change would make the viewer look empty rather
            // than broken.
            ->withoutGlobalScopes()
            ->with(['user:id,name,email', 'team:id,name']);

        $range->constrain($query, 'audit_logs.created_at');

        $this->applyFilters($query, $filters);

        return $query
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage)
            ->withQueryString();
    }

    /**
     * One page of rows, flattened for the screen.
     *
     * @param  LengthAwarePaginator<int, AuditLog>  $page
     * @return list<array<string, mixed>>
     */
    public function rows(LengthAwarePaginator $page): array
    {
        return array_values(array_map(static function (AuditLog $entry): array {
            $category = AuditCategory::of($entry->action);

            return [
                'id' => (int) $entry->getKey(),
                'action' => $entry->action,
                'category' => $category->value,
                'category_label' => $category->label(),
                'at' => $entry->created_at?->toIso8601String(),
                'actor' => $entry->user?->name,
                'actor_id' => $entry->user_id,
                'team' => $entry->team?->name,
                'team_id' => $entry->team_id,
                'subject_type' => $entry->subject_type === null
                    ? null
                    : class_basename($entry->subject_type),
                'subject_id' => $entry->subject_id,
                'ip_address' => $entry->ip_address,
                'request_id' => $entry->request_id,
                'properties' => $entry->properties,
            ];
        }, $page->items()));
    }

    /**
     * The filter control's options.
     *
     * Actions come from the **table**, not from a hand-maintained list: every module writes its own
     * and a list here would be out of date by one release. Capped, and ordered by how often each
     * appears, so a table with thousands of distinct actions still produces a usable control.
     *
     * @return array<string, mixed>
     */
    public function options(int $teamLimit = 500): array
    {
        $actions = AuditLog::query()
            ->withoutGlobalScopes()
            ->selectRaw('action, COUNT(*) as aggregate')
            ->groupBy('action')
            ->orderByDesc('aggregate')
            ->limit(200)
            ->pluck('action')
            ->map(static fn (mixed $action): string => (string) $action)
            ->sort()
            ->values()
            ->all();

        return [
            'actions' => $actions,
            'categories' => array_map(
                static fn (AuditCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ],
                AuditCategory::cases(),
            ),
            // The same cap the registry's index uses, for the same reason: past a few hundred
            // tenants a picker is worse than typing an id into the query string.
            'teams' => Team::query()
                ->orderBy('name')
                ->limit($teamLimit)
                ->get(['id', 'name'])
                ->map(static fn (Team $team): array => [
                    'id' => (int) $team->getKey(),
                    'name' => (string) $team->name,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Builder<AuditLog>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters): void
    {
        $action = $filters['action'] ?? null;

        if (is_string($action) && $action !== '') {
            // Exact match, not a `LIKE`: the action is chosen from the options above, and a
            // substring match would make `leads.note_added` also match nothing predictable.
            $query->where('action', $action);
        }

        $category = $filters['category'] ?? null;

        if (is_string($category) && ($enum = AuditCategory::tryFrom($category)) !== null) {
            $patterns = $enum->likePatterns();

            if ($enum === AuditCategory::Other) {
                // "Uncategorised" is the complement of every other category, which cannot be
                // expressed as a prefix — so it is expressed as the absence of all of them.
                $query->where(function (Builder $nested): void {
                    foreach (AuditCategory::cases() as $other) {
                        foreach ($other->likePatterns() as $pattern) {
                            $nested->where('action', 'not like', $pattern);
                        }
                    }
                });
            } else {
                $query->where(function (Builder $nested) use ($patterns): void {
                    foreach ($patterns as $pattern) {
                        $nested->orWhere('action', 'like', $pattern);
                    }
                });
            }
        }

        foreach (['user_id', 'team_id', 'subject_id'] as $column) {
            $value = $filters[$column] ?? null;

            if (is_numeric($value)) {
                $query->where($column, (int) $value);
            }
        }

        $subjectType = $filters['subject_type'] ?? null;

        if (is_string($subjectType) && $subjectType !== '') {
            // Matched on the class basename an operator can read off the screen, which is what the
            // rows expose — as a **suffix**, with no escaped backslash in the pattern.
            //
            // Both halves of that are deliberate. A suffix match cannot confuse `Lead` with
            // `LeadNote`, because `...Models\LeadNote` does not end in `Lead`. And a pattern
            // containing a backslash would not mean the same thing on both engines: MySQL reads
            // `\` in a `LIKE` as its escape character and SQLite does not, so `%\Lead` matches on
            // one and nothing on the other — the class of bug that bit the Signals exporter.
            $query->where('subject_type', 'like', '%'.Str::studly($subjectType));
        }
    }
}
