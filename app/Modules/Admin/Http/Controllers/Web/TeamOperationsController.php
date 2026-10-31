<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\RestoreTeamAction;
use App\Modules\Admin\Actions\SuspendTeamAction;
use App\Modules\Admin\Http\Requests\AdminMemberRoleRequest;
use App\Modules\Admin\Http\Requests\RestoreTeamRequest;
use App\Modules\Admin\Http\Requests\SuspendTeamRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\TeamOverview;
use App\Modules\Auth\Actions\RemoveTeamMemberAction;
use App\Modules\Auth\Actions\UpdateTeamMemberRoleAction;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The per-tenant page, and the operations that change who controls a tenant (Phase 19a).
 *
 * This is the **bespoke screen** the registry deliberately does not try to be. A team's page needs
 * to decide things — who the owners are, whether a suspension can be lifted, what the account is
 * actually using — and a descriptor that could decide any of that would have stopped being
 * declarative. The registry handles the forty tables nobody needs a judgement about; this handles
 * the one somebody always does.
 *
 * Both membership operations call **Auth's** Actions rather than writing the pivot. That is not
 * tidiness: `RemoveTeamMemberAction` refuses to remove a team's last Owner and moves a member's
 * active team if it was the one they were removed from, and `UpdateTeamMemberRoleAction` refuses to
 * demote the last Owner. A panel that wrote `team_user` directly could leave a tenant with nobody
 * able to administer it, from a screen whose whole purpose is fixing that situation.
 *
 * The members and the usage counts come from {@see TeamOverview}, which reads them **inside the
 * tenant's own context** — so they come back through the same global scope and the same policies
 * the tenant's own dashboard uses. A hand-written `where team_id` would be a second implementation
 * of tenancy, and the day the two disagreed the panel would be the one that was wrong.
 */
final class TeamOperationsController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly TeamOverview $overview,
        private readonly AdminAudit $audit,
    ) {}

    public function show(Team $team): Response
    {
        $this->audit->record(
            AdminAudit::RECORD_VIEWED,
            $team,
            ['resource' => 'teams', 'intent' => 'operations'],
            (int) $team->getKey(),
        );

        return Inertia::render('admin/teams/Show', [
            'navigation' => $this->registry->navigation(),
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'market' => $team->market,
                'timezone' => $team->timezone,
                'locale' => $team->locale,
                'stripe_id' => $team->stripe_id,
                'trial_ends_at' => $team->trial_ends_at?->toIso8601String(),
                'suspended_at' => $team->suspended_at?->toIso8601String(),
                'suspension_reason' => $team->suspension_reason,
                'created_at' => $team->created_at?->toIso8601String(),
            ],
            'members' => $this->overview->members($team),
            'roles' => array_map(
                static fn (TeamRole $role): array => ['value' => $role->value, 'label' => $role->label()],
                TeamRole::cases(),
            ),
            'usage' => $this->overview->usage($team),
        ]);
    }

    public function suspend(SuspendTeamRequest $request, Team $team, SuspendTeamAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $action->handle($team, $request->reason(), $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $team->name.' is suspended. They can still sign in and read their data.',
        ]);

        return to_route('admin.teams.show', $team);
    }

    public function restore(RestoreTeamRequest $request, Team $team, RestoreTeamAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $action->handle($team, $operator, $request->note());

        Inertia::flash('toast', ['type' => 'success', 'message' => $team->name.' is active again.']);

        return to_route('admin.teams.show', $team);
    }

    public function updateMemberRole(
        AdminMemberRoleRequest $request,
        Team $team,
        User $member,
        UpdateTeamMemberRoleAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        // Auth's Action, with the operator as the actor — so the audit entry it writes names the
        // person who did it rather than nobody. It refuses to demote the last Owner.
        $action->handle($team, $member, $request->role(), $operator);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Role updated.']);

        return to_route('admin.teams.show', $team);
    }

    public function removeMember(
        Request $request,
        Team $team,
        User $member,
        RemoveTeamMemberAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        $action->handle($team, $member, $operator);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Member removed.']);

        return to_route('admin.teams.show', $team);
    }
}
