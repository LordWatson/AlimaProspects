<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\Locale;
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
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $user,
                'currentTeam' => $user instanceof User ? $this->currentTeam($user) : null,
                'teams' => $user instanceof User ? $this->teams($user) : [],
            ],
            'locale' => [
                'current' => $locale,
                'dir' => Locale::direction($locale),
                'available' => Locale::options(),
                // The active bundle, evaluated lazily so it is sent on full page
                // loads but omitted from partial reloads that don't need it.
                'translations' => fn (): array => Locale::messages($locale),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            // For the handful of places that need a **real browser form POST** rather than an
            // Inertia visit — a file download, where an XHR response cannot reach the user's disk
            // (Compliance's team export, Phase 15). Inertia's own visits carry the token in a
            // header and never read this.
            //
            // Lazy, like `translations`: it is sent on a full page load and omitted from partial
            // reloads, and a public page with no session never touches the session at all.
            'csrfToken' => fn (): ?string => $request->hasSession() ? $request->session()->token() : null,
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
            'timezone' => $team->timezone,
            'locale' => $team->locale,
            'market' => $team->market,
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
