<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Actions\CloneSignalPresetsAction;
use App\Modules\Signals\Actions\DeleteSignalWatchAction;
use App\Modules\Signals\Actions\ListSignalDefinitionsAction;
use App\Modules\Signals\Actions\ListSignalWatchesAction;
use App\Modules\Signals\Actions\SaveSignalWatchAction;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Http\Resources\SignalDefinitionResource;
use App\Modules\Signals\Http\Resources\SignalWatchResource;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Watch settings (Inertia). Thin: authorise via {@see SignalWatchPolicy}, validate in a
 * FormRequest, call one Action, render or redirect.
 *
 * Every write redirects back to the settings screen, so Inertia re-fetches the list and the run
 * history rather than the client patching its own copy. "Run now" is the one endpoint that does
 * something asynchronous — it dispatches and returns immediately; progress arrives over Reverb.
 */
final class SignalWatchController extends Controller
{
    use ResolvesSignals;

    public function index(
        Request $request,
        ListSignalWatchesAction $list,
        ListSignalDefinitionsAction $listDefinitions,
        GetIcpsAction $getIcps,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('view', $this->transientWatch($team));

        // The checkboxes a watch is built from. Since S7 these are the team's **own** signal types
        // (ADR 0018), so a team with none sees the editor say so and point at the library rather
        // than offering an empty fieldset - the one-click default below is the path that avoids
        // ever meeting that state.
        $definitions = $listDefinitions->handle($team)
            ->filter(static fn (SignalDefinition $definition): bool => $definition->is_active)
            ->values();

        return Inertia::render('signals/Watches', [
            'watches' => SignalWatchResource::collection($list->handle($team))->resolve($request),
            'options' => [
                'definitions' => SignalDefinitionResource::collection($definitions)->resolve($request),
                'cadences' => array_map(static fn (SignalWatchCadence $cadence): array => [
                    'value' => $cadence->value,
                    'label' => $cadence->label(),
                ], SignalWatchCadence::cases()),
                'icps' => $getIcps->handle($team)->map(static fn (Icp $icp): array => [
                    'id' => $icp->id,
                    'name' => $icp->name,
                    'is_active' => $icp->is_active,
                ])->values()->all(),
            ],
            // What the one-click "set up my first watch" button creates, so the empty state can
            // name the market and the events rather than promising something vague.
            'defaults' => $this->defaults(),
            'can' => ['manage' => $user->can('create', $this->transientWatch($team))],
        ]);
    }

    public function store(SaveSignalWatchRequest $request, SaveSignalWatchAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientWatch($team));

        $watch = $action->handle($team, $this->user($request), $request->watch());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $watch->is_active
                ? __('“:name” is on. Alima will check for events within the hour.', ['name' => $watch->name])
                : __('“:name” is saved, and paused until you switch it on.', ['name' => $watch->name]),
        ]);

        return to_route('signal-watches.index');
    }

    /**
     * The one-click default watch for a team that has none (S5): the market's default location,
     * every signal type, daily. A first watch that finds nothing teaches the team that Signals
     * does not work, so this is deliberately the broadest useful one.
     *
     * Since S7 it **clones the market's presets first** and builds the watch from what it got. That
     * is what keeps this one click: a team setting Signals up has no library yet, and making them
     * assemble one before they can have a watch would turn the shortest path into the longest. The
     * clone is idempotent, so a team that already has those types keeps their own tuned copies.
     */
    public function storeDefault(
        Request $request,
        SaveSignalWatchAction $action,
        CloneSignalPresetsAction $clonePresets,
    ): RedirectResponse {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('create', $this->transientWatch($team));
        $this->authorize('clone', $this->transientDefinition($team));

        $definitions = $clonePresets->handleStartersForMarket($team, $user, SignalWatchData::DEFAULT_MARKET);

        /** @var list<int> $definitionIds */
        $definitionIds = array_values($definitions
            ->map(static fn (SignalDefinition $definition): int => (int) $definition->getKey())
            ->all());

        $watch = $action->handle($team, $user, SignalWatchData::defaultWatch($definitionIds));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('“:name” is on. Alima will check for events within the hour.', ['name' => $watch->name]),
        ]);

        return to_route('signal-watches.index');
    }

    public function update(SaveSignalWatchRequest $request, int $watch, SaveSignalWatchAction $action): RedirectResponse
    {
        $model = $this->watch($request, $watch);

        $this->authorize('update', $model);

        $action->handle($this->team($request), $this->user($request), $request->watch(), $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Watch updated.')]);

        return to_route('signal-watches.index');
    }

    public function destroy(Request $request, int $watch, DeleteSignalWatchAction $action): RedirectResponse
    {
        $model = $this->watch($request, $watch);

        $this->authorize('delete', $model);

        $action->handle($model, $this->user($request));

        // Said plainly, because it isn't obvious: deleting a watch takes its signals with it. Any
        // signal the team actually wanted is already a lead, and the lead survives.
        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Watch removed, along with its signals. Leads you converted are untouched.'),
        ]);

        return to_route('signal-watches.index');
    }

    public function run(Request $request, int $watch, TriggerSignalWatchAction $action): RedirectResponse
    {
        $model = $this->watch($request, $watch);

        $this->authorize('run', $model);

        // Throws a SignalsException (rendered centrally) when the watch is paused or was run in
        // the last 15 minutes — a run costs money, so it is rate-limited per watch.
        $action->handle($model);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Looking for events now — new signals will appear as they land.'),
        ]);

        return back();
    }

    /**
     * What a new watch starts as, and what the one-click button will create.
     *
     * The types are **not** here: for a new watch the editor pre-ticks every active type the team
     * has, and for the one-click button they are whatever cloning the market's presets produces -
     * neither of which this method can know without a query the empty state does not need.
     *
     * @return array{location: string, cadence: string}
     */
    private function defaults(): array
    {
        $default = SignalWatchData::defaultWatch();

        return [
            'location' => $default->location,
            'cadence' => $default->cadence->value,
        ];
    }
}
