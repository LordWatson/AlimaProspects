<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Actions\FindDiscoveryRunAction;
use App\Modules\LeadSourcing\Actions\GetDiscoveryRunsAction;
use App\Modules\LeadSourcing\Actions\StartDiscoveryRunAction;
use App\Modules\LeadSourcing\Data\StartDiscoveryData;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Http\Requests\StartDiscoveryRequest;
use App\Modules\LeadSourcing\Http\Resources\DiscoveryRunResource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Policies\DiscoveryRunPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The lead-discovery workspace (Inertia). Thin: authorise via {@see DiscoveryRunPolicy},
 * delegate to the Start/Get/Find Actions, and render the runs list + new-run form and the run
 * detail with its leads. Live progress arrives over Reverb on the team channel.
 */
final class DiscoveryController extends Controller
{
    public function index(Request $request, GetDiscoveryRunsAction $getRuns, GetIcpsAction $getIcps): Response
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientRun($team));

        $runs = $getRuns->handle($team);
        $canManage = $request->user()?->can('create', $this->transientRun($team)) ?? false;

        return Inertia::render('lead-sourcing/Index', [
            'runs' => DiscoveryRunResource::collection($runs)->resolve($request),
            'pagination' => [
                'current_page' => $runs->currentPage(),
                'last_page' => $runs->lastPage(),
                'total' => $runs->total(),
            ],
            'sources' => $this->sources(),
            'icps' => $getIcps->handle($team)->map(static fn (Icp $icp): array => [
                'id' => $icp->id,
                'name' => $icp->name,
                'is_active' => $icp->is_active,
            ])->all(),
            'defaults' => [
                'limit' => (int) config('lead-sourcing.defaults.limit', 25),
                'max_limit' => (int) config('lead-sourcing.defaults.max_limit', 100),
            ],
            'can' => ['manage' => $canManage],
        ]);
    }

    public function store(StartDiscoveryRequest $request, StartDiscoveryRunAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('create', $this->transientRun($team));

        $runs = $action->handle($team, StartDiscoveryData::fromValidated($request->validated()), $user);

        $message = $runs->count() === 1
            ? __('Alima is searching for leads — this only takes a moment.')
            : __('Alima is searching :count sources for leads — this only takes a moment.', ['count' => $runs->count()]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        // The runs fan out to one row per source; the index shows them all updating live over Reverb.
        return to_route('lead-sourcing.index');
    }

    public function show(Request $request, int $run, FindDiscoveryRunAction $find): Response
    {
        $team = $this->team($request);
        $model = $find->handle($team, $run);

        $this->authorize('view', $model);

        return Inertia::render('lead-sourcing/Show', [
            'run' => DiscoveryRunResource::make($model)->resolve($request),
            'can' => ['manage' => $request->user()?->can('create', $model) ?? false],
        ]);
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * A transient, team-scoped run for authorising list/create (no stored row needed).
     */
    private function transientRun(Team $team): DiscoveryRun
    {
        return (new DiscoveryRun)->forceFill(['team_id' => $team->getKey()]);
    }

    /**
     * The lead sources shown in the picker: the **providers**, each flagged for whether it can be
     * run yet, so one not yet implemented is still previewable.
     *
     * The non-provider cases are left out. `LeadSource` also labels how a lead arrived when nobody
     * went looking — a CSV the team imported, a signal they converted — and offering either as a
     * greyed-out card on a screen whose only question is "which providers shall I search?" invites
     * a member to wait for something that is never coming.
     *
     * @return list<array<string, mixed>>
     */
    private function sources(): array
    {
        return array_values(array_map(static fn (LeadSource $source): array => [
            'value' => $source->value,
            'label' => $source->label(),
            'description' => $source->description(),
            'available' => $source->isAvailable(),
        ], array_filter(
            LeadSource::cases(),
            static fn (LeadSource $source): bool => $source->isAvailable(),
        )));
    }
}
