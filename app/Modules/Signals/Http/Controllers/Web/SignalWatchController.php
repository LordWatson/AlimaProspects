<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Actions\CloneSignalPresetsAction;
use App\Modules\Signals\Actions\DeleteSignalWatchAction;
use App\Modules\Signals\Actions\ListSignalDefinitionsAction;
use App\Modules\Signals\Actions\ListSignalWatchesAction;
use App\Modules\Signals\Actions\SaveSignalWatchAction;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Http\Resources\SignalDefinitionResource;
use App\Modules\Signals\Http\Resources\SignalTuningSuggestionResource;
use App\Modules\Signals\Http\Resources\SignalWatchResource;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Services\SignalTuningAdvisor;
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
        SignalTuningAdvisor $advisor,
        SignalSpendGuard $spend,
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
                // Only the regions the business holds a compliance position for (ADR 0019 §3):
                // choosing where to look also chooses how the resulting leads may be approached,
                // and `offered` is that switch rather than a feature flag.
                'regions' => array_map(static fn (SearchRegion $region): array => [
                    'value' => $region->value,
                    'label' => $region->label(),
                    'approach_note' => $region->approachNote(),
                    'default_locations' => $region->defaultLocations(),
                ], SearchRegion::offered()),
                // On screen rather than only in the validator: a member who pastes a dozen cities
                // has to be told the cap before the save, not have the extras quietly dropped.
                'max_locations' => (int) config('signals.max_locations_per_watch', 10),
                // The same rule one level up, and the screen this module's other three already
                // obey: `SaveSignalWatchAction` asks the plan whether there is room for another
                // watch, so a team at its ceiling could fill in the whole editor — the tallest
                // form in the product — and meet the refusal only on submit. **Null is "no
                // limit", not zero**: an Enterprise plan declares no ceiling.
                'max_watches' => $spend->allowanceFor($team)->maxWatches,
                'cadences' => array_map(static fn (SignalWatchCadence $cadence): array => [
                    'value' => $cadence->value,
                    'label' => $cadence->label(),
                ], SignalWatchCadence::cases()),
                'icps' => $getIcps->handle($team)->map(static fn (Icp $icp): array => [
                    'id' => $icp->id,
                    'name' => $icp->name,
                    'is_active' => $icp->is_active,
                ])->values()->all(),
                // The lists a watch can be pointed at (S10). Active ones with something on them:
                // pointing a watch at an empty list is a watch that searches nothing, and a picker
                // that offers one is a trap rather than a choice. A team with no lists is sent to
                // the accounts screen by the editor instead of being shown an empty select.
                'watchlists' => $this->watchlists($team),
            ],
            // What the one-click "set up my first watch" button creates, so the empty state can
            // name the market and the events rather than promising something vague.
            'defaults' => $this->defaults($team),
            // What the product thinks the team should change, with the evidence (S12). Sent with
            // the screen rather than fetched by it: a panel that loaded separately would flash
            // empty on every visit for the one in ten that has something in it.
            //
            // **Read, never generated here.** The advisor runs weekly from `signals:advise`;
            // calling it on a page load would make a member's first visit of the week slower for a
            // reason they could not see, and would mean the proposals a team is looking at changed
            // under them while they read.
            'suggestions' => SignalTuningSuggestionResource::collection(
                app(TeamContext::class)->run($team, static fn (): array => $advisor->open($team)),
            )->resolve($request),
            'can' => ['manage' => $user->can('create', $this->transientWatch($team))],
        ]);
    }

    /**
     * The team's usable watchlists, for the editor's picker (S10).
     *
     * @return list<array{id: int, name: string, source_kind: string, entries_count: int}>
     */
    private function watchlists(Team $team): array
    {
        return app(TeamContext::class)->run($team, static function () use ($team): array {
            $rows = [];

            $lists = SignalWatchlist::query()
                ->where('team_id', $team->getKey())
                ->where('is_active', true)
                ->where('entries_count', '>', 0)
                ->orderBy('name')
                ->get(['id', 'name', 'source_kind', 'entries_count']);

            foreach ($lists as $list) {
                $rows[] = [
                    'id' => (int) $list->id,
                    'name' => $list->name,
                    'source_kind' => $list->source_kind->value,
                    'entries_count' => (int) $list->entries_count,
                ];
            }

            return $rows;
        });
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

        $market = SignalWatchData::marketFor($team->market);

        $definitions = $clonePresets->handleStartersForMarket($team, $user, $market);

        /** @var list<int> $definitionIds */
        $definitionIds = array_values($definitions
            ->map(static fn (SignalDefinition $definition): int => (int) $definition->getKey())
            ->all());

        $watch = $action->handle($team, $user, SignalWatchData::defaultWatch($definitionIds, $market));

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
     * has, and for the one-click button they are whatever cloning the market's starter presets
     * produces — neither of which this method can know without a query the empty state does not
     * need.
     *
     * The places and the region come from the **team's own market** since S8, so a UK team's first
     * watch looks at London rather than at Dubai (ADR 0019).
     *
     * @return array{locations: list<string>, regions: list<string>, cadence: string, market: string, market_label: string}
     */
    private function defaults(Team $team): array
    {
        $market = SignalWatchData::marketFor($team->market);
        $default = SignalWatchData::defaultWatch(market: $market);

        return [
            'locations' => $default->locations,
            'regions' => array_map(static fn (SearchRegion $region): string => $region->value, $default->regions),
            'cadence' => $default->cadence->value,
            'market' => $market->value,
            'market_label' => $market->label(),
        ];
    }
}
