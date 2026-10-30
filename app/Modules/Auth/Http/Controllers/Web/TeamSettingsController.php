<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Auth\Models\TeamInvitation;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Renders the Team settings screen: team details, members with their roles, and pending
 * invitations. All management acts on the viewer's active team.
 */
final class TeamSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        $canManage = $user->can('manageMembers', $team);

        return Inertia::render('settings/Team', [
            'team' => [
                'id' => $team->id,
                'name' => $team->name,
                'timezone' => $team->timezone,
                'locale' => $team->locale,
                'market' => $team->market,
            ],
            'members' => $this->members($team, $user),
            'invitations' => $canManage ? $this->invitations($team) : [],
            'roleOptions' => $this->roleOptions(),
            'can' => [
                'updateTeam' => $user->can('update', $team),
                'manageMembers' => $canManage,
                'deleteTeam' => $user->can('delete', $team),
            ],
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function members(Team $team, User $viewer): array
    {
        return $team->users()
            ->orderBy('name')
            ->get()
            ->map(fn (User $member): array => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->getAttribute('pivot')?->getAttribute('role'),
                'is_you' => $member->id === $viewer->id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function invitations(Team $team): array
    {
        return TeamInvitation::query()
            ->where('team_id', $team->getKey())
            ->whereNull('accepted_at')
            ->latest()
            ->get()
            ->map(fn (TeamInvitation $invitation): array => [
                'id' => $invitation->id,
                'email' => $invitation->email,
                'role' => $invitation->role,
                'expires_at' => $invitation->expires_at?->toIso8601String(),
                'is_expired' => $invitation->isExpired(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, string>>
     */
    private function roleOptions(): array
    {
        return array_map(
            static fn (TeamRole $role): array => ['value' => $role->value, 'label' => $role->label()],
            TeamRole::cases(),
        );
    }
}
