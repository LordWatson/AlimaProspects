<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadsWorkspace\Data\LeadFilters;
use App\Modules\LeadsWorkspace\Models\SavedView;
use Illuminate\Support\Facades\DB;

/**
 * Saves (or replaces) a member's named leads view (Phase 10). Saving under a name the member
 * already used **replaces** its filters rather than failing — re-saving a view after tweaking the
 * list is the common case, and a unique-constraint error would be a poor way to say so.
 *
 * Filters are stored in their canonical form, so a view written today still opens after the filter
 * shape grows: {@see LeadFilters::fromArray()} drops whatever it no longer recognises.
 */
final class SaveViewAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function handle(Team $team, User $user, string $name, LeadFilters $filters, bool $isShared = false): SavedView
    {
        return DB::transaction(function () use ($team, $user, $name, $filters, $isShared): SavedView {
            // `team_id` is part of the lookup, not just the payload: a user can belong to several
            // teams (ADR 0006), and their "Grade A in the UK" in one team is a different view from
            // the one in another — matching the table's (team, user, name) unique key.
            $view = SavedView::query()->updateOrCreate(
                ['team_id' => $team->getKey(), 'user_id' => $user->getKey(), 'name' => $name],
                [
                    'filters' => $filters->toParams(),
                    'is_shared' => $isShared,
                ],
            );

            // In the same transaction as the write, so the trail can't disagree with the data.
            $this->audit->log('leads.view_saved', $view, [
                'shared' => $isShared,
                // The filter *keys* say what kind of view this is without copying the team's search
                // terms into the audit trail.
                'filters' => array_keys($filters->toParams()),
            ], $user);

            return $view;
        });
    }
}
