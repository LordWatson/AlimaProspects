<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Models\Team;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'currentTeam' => $user instanceof User ? $this->currentTeam($user) : null,
                'teams' => $user instanceof User ? $this->teams($user) : [],
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function currentTeam(User $user): ?array
    {
        if ($user->currentTeamId() === null) {
            return null;
        }

        $team = $user->currentTeam()->first();

        if (! $team instanceof Team) {
            return null;
        }

        return [
            'id' => $team->id,
            'name' => $team->name,
            'role' => $user->roleOn($team)?->value,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function teams(User $user): array
    {
        return $user->teams()
            ->get()
            ->map(fn (Team $team): array => [
                'id' => $team->id,
                'name' => $team->name,
                'role' => $team->getAttribute('pivot')?->getAttribute('role'),
                'is_current' => $team->id === $user->currentTeamId(),
            ])
            ->values()
            ->all();
    }
}
