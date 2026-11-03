<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Enums\Capability;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Actions\CloneSignalPresetsAction;
use App\Modules\Signals\Actions\ComposeSignalDefinitionAction;
use App\Modules\Signals\Actions\DeleteSignalDefinitionAction;
use App\Modules\Signals\Actions\ListSignalDefinitionsAction;
use App\Modules\Signals\Actions\PreviewSignalDefinitionAction;
use App\Modules\Signals\Actions\SaveSignalDefinitionAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\CloneSignalPresetsRequest;
use App\Modules\Signals\Http\Requests\ComposeSignalDefinitionRequest;
use App\Modules\Signals\Http\Requests\PreviewSignalDefinitionRequest;
use App\Modules\Signals\Http\Requests\SaveSignalDefinitionRequest;
use App\Modules\Signals\Http\Resources\SignalDefinitionResource;
use App\Modules\Signals\Http\Resources\SignalDraftResource;
use App\Modules\Signals\Http\Resources\SignalPreviewResource;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Services\PlatformPresetReader;
use App\Modules\Signals\Support\SignalCostEstimate;
use App\Modules\Signals\Support\SignalCostEstimator;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Support\SignalPresetPack;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signal-type library (Inertia, S7 — ADR 0018). Thin: authorise via
 * {@see SignalDefinitionPolicy}, validate in a FormRequest, call one Action, render or redirect.
 *
 * One screen, two halves. The team's own types, which they can edit, pause and remove; and the
 * **preset library**, which they cannot — a preset is copied into their own types and tuned from
 * there, so a platform edit never silently changes what they are paying to run (ADR 0018 §3).
 *
 * **Since S9 there are three doors into a definition** (the builder): the **library**, which is the
 * fast path and the one that teaches the format; the **composer**, which drafts one from a sentence
 * and is the only part behind a paid capability (plan decision #1); and the **advanced form**, which
 * is S7's raw fields for somebody who knows what they want. All three end at the same review form,
 * and all three can be previewed before saving — because the S0 spike established that nobody can
 * tell whether a query template works by reading it.
 *
 * The presets are filtered to **the team's own market** (S8, ADR 0019). Until S8 that was a
 * product-wide constant, because a watch was UAE-only whatever market the team traded in; now a UK
 * team's watch looks at the UK, so offering them the UAE pack would be offering templates that name
 * the wrong country. A preset with no `markets` at all is offered to everyone, which is how a
 * type that works anywhere says so.
 */
final class SignalDefinitionController extends Controller
{
    use ResolvesSignals;

    public function index(
        Request $request,
        ListSignalDefinitionsAction $list,
        PlatformPresetReader $presets,
        SignalCostEstimator $cost,
        CapabilityGate $capabilities,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('viewAny', $this->transientDefinition($team));

        $mine = $list->handle($team);

        return Inertia::render('signals/Types', [
            'definitions' => SignalDefinitionResource::collection($mine)->resolve($request),
            // Only the presets the team has not already taken: a library that keeps offering what
            // you already have is a library you stop reading.
            'presets' => SignalDefinitionResource::collection(
                $presets->active(SignalWatchData::marketFor($team->market))
                    ->reject(fn ($preset): bool => $mine->contains('preset_key', $preset->key))
                    ->values(),
            )->resolve($request),
            'options' => [
                'categories' => array_map(static fn (SignalCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                    'description' => $category->description(),
                ], SignalCategory::cases()),
                'icons' => array_map(static fn (SignalIcon $icon): array => [
                    'value' => $icon->value,
                    'label' => $icon->label(),
                ], SignalIcon::cases()),
                // The starter set for this team's market: a short, deliberately small subset
                // (`SignalPreset::$isStarter`). Sent because the screen's "add these for me"
                // button used to post **every** visible preset key, which was fine when the
                // library was three and is wrong at thirty — a UK team would be asking for 27
                // types, past `max_definitions_per_team`, and paying for a search per template
                // per type on every run of every watch. The ceiling would refuse it; the button
                // should not have offered it.
                'starter_keys' => SignalPresetCatalogue::starterKeys(SignalWatchData::marketFor($team->market)),
                // The vertical packs (S9), so the library reads as a product rather than a list.
                // Sent with their "who this is for" line, because a pack whose name a member does
                // not recognise is a filter they will not press.
                'packs' => array_map(static fn (SignalPresetPack $pack): array => [
                    'value' => $pack->value,
                    'label' => $pack->label(),
                    'audience' => $pack->audience(),
                ], SignalPresetPack::cases()),
                // The sources a type may run on (S13, task 9), with what each is good for. Only the
                // available ones: a case with no provider behind it would be a tick box that quietly
                // changes nothing.
                //
                // The description is the enum's, and it is written as a **trade-off** rather than a
                // boast — the honest answer to "should I add web search?" is "it finds more, and
                // more of it is noise", and a member who learns that from their feed instead of
                // from this form will switch it off and not come back.
                'sources' => array_map(static fn (SignalSourceKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'description' => $kind->description(),
                ], SignalSourceKind::available()),
            ],
            // The floors that apply to a type that sets none, so the form's placeholders can say
            // what "leave this empty" actually means rather than showing a bare box.
            'defaults' => [
                'min_confidence' => (int) config('signals.min_confidence', 60),
                'min_relevance' => (int) config('signals.min_relevance', 40),
                'max_definitions' => max(1, (int) config('signals.max_definitions_per_team', 25)),
            ],
            // The cost of **one** template a month, for this team's actual watches. Sent per
            // template rather than as a total so the form can show the number changing as somebody
            // adds one — the moment the number is worth seeing (S9 task 4). The client multiplies;
            // the server owns the arithmetic (ADR 0019 §4).
            'cost' => $this->costFacts($cost->estimate($team, 1, [SignalSourceKind::default()]), SignalSourceKind::default()),
            // The same facts **per source** (S13), because a source is a cost multiplier: one
            // template on two sources is two paid searches per run. The screen sums the entries for
            // the sources that are ticked and multiplies by its template count; every figure in
            // each entry is still computed here, so the client owns only the sum and the
            // multiplication (ADR 0019 §4).
            'cost_by_source' => $this->costBySource($cost, $team),
            'can' => [
                'manage' => $user->can('create', $this->transientDefinition($team)),
                // The AI shortcut is the paid half (plan decision #1). The screen asks the gate
                // rather than reading the plan, so the button and the Action cannot disagree.
                'compose' => $capabilities->allows($team, Capability::SignalComposer),
            ],
        ]);
    }

    /**
     * The per-template cost facts for **each** available source, keyed by source.
     *
     * One estimate per source rather than one for all of them, because the screen needs the figure
     * to move as a box is ticked and it cannot ask the server on every keystroke. Sources are priced
     * independently (`signals.sources.*.cost_micros_per_request`) and one of the declared ones is
     * free, so a single figure multiplied by a count would be wrong the moment a free source ships.
     *
     * @return array<string, array<string, int|bool>>
     */
    private function costBySource(SignalCostEstimator $cost, Team $team): array
    {
        $out = [];

        foreach (SignalSourceKind::available() as $kind) {
            $out[$kind->value] = $this->costFacts($cost->estimate($team, 1, [$kind]), $kind);
        }

        return $out;
    }

    /**
     * The per-template cost facts, named as the client's own type names them.
     *
     * Spelled out rather than handed `SignalCostEstimate::toArray()`: a DTO's serialised shape is a
     * second contract, and one that changes when somebody renames a property. The screen multiplies
     * these by its template count — the server owns the arithmetic (ADR 0019 §4) and the client owns
     * only the multiplication.
     *
     * @return array<string, int|bool>
     */
    private function costFacts(SignalCostEstimate $estimate, ?SignalSourceKind $kind = null): array
    {
        return [
            'templates' => $estimate->templates,
            'queries_per_run' => $estimate->queriesPerRun,
            'runs_per_month' => $estimate->runsPerMonth,
            'queries_per_month' => $estimate->queriesPerMonth,
            'cost_micros_per_month' => $estimate->costMicrosPerMonth,
            'watch_count' => $estimate->watchCount,
            'hypothetical' => $estimate->hypothetical,
            // This source's own price, not the module-wide one: sources are priced separately and
            // one of the declared ones is free, so a shared figure would misquote the moment it ships.
            'micros_per_search' => ($kind ?? SignalSourceKind::default())->costMicrosPerRequest(),
        ];
    }

    /**
     * Draft a signal type from a sentence (S9).
     *
     * Flashed back rather than returned as JSON, which is how every other result travels in this
     * app: the page is server-driven, and a draft is **ephemeral by design** — it lives until the
     * member saves it or walks away, and a flashed prop has exactly that lifetime.
     *
     * The brief is flashed back too, so the box keeps what they typed. A composer that cleared the
     * text box on a refusal would make the refusal unactionable.
     */
    public function compose(
        ComposeSignalDefinitionRequest $request,
        ComposeSignalDefinitionAction $action,
    ): RedirectResponse {
        $team = $this->team($request);

        $this->authorize('create', $this->transientDefinition($team));

        $draft = $action->handle($team, $this->user($request), $request->brief());

        Inertia::flash('draft', (new SignalDraftResource($draft))->resolve($request));
        Inertia::flash('brief', $request->brief());

        if (! $draft->viable) {
            Inertia::flash('toast', [
                'type' => 'info',
                // The model's own reason, not ours: it knows why it refused and the member is owed
                // it. Bounded to 300 characters by the prompt's schema.
                'message' => $draft->reason ?? __('That does not describe an event Alima could watch the news for.'),
            ]);
        }

        return to_route('signal-definitions.index');
    }

    /**
     * Run a definition against live news without saving it (S9).
     *
     * **Only the result is flashed.** It flashed the submitted draft back as well for one review
     * cycle, on the reasoning that the redirect re-renders the page and would otherwise empty the
     * form — and that was true but fixed in the wrong place. The screen posts this with
     * `preserveState`, so the form, the dirty tracking and *which type is being edited* all survive
     * on their own. Flashing a draft meant the client could not tell a composer draft from an echo
     * of its own form, so it re-opened the editor as **new**: previewing a saved type and pressing
     * Save created a duplicate, and `form.reset()` had silently cleared the unsaved-changes guard.
     */
    public function preview(
        PreviewSignalDefinitionRequest $request,
        PreviewSignalDefinitionAction $action,
    ): RedirectResponse {
        $team = $this->team($request);

        $this->authorize('create', $this->transientDefinition($team));

        $result = $action->handle($team, $this->user($request), $request->unsavedDefinition());

        Inertia::flash('preview', (new SignalPreviewResource($result))->resolve($request));

        return to_route('signal-definitions.index');
    }

    public function store(SaveSignalDefinitionRequest $request, SaveSignalDefinitionAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientDefinition($team));

        $definition = $action->handle($team, $this->user($request), $request->definition());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('“:name” is one of your signal types. Add it to a watch to start looking.', [
                'name' => $definition->name,
            ]),
        ]);

        return to_route('signal-definitions.index');
    }

    public function update(
        SaveSignalDefinitionRequest $request,
        int $definition,
        SaveSignalDefinitionAction $action,
    ): RedirectResponse {
        $model = $this->definition($request, $definition);

        $this->authorize('update', $model);

        $action->handle($this->team($request), $this->user($request), $request->definition(), $model);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Signal type updated. Watches using it will use the new version on their next check.'),
        ]);

        return to_route('signal-definitions.index');
    }

    public function destroy(
        Request $request,
        int $definition,
        DeleteSignalDefinitionAction $action,
    ): RedirectResponse {
        $model = $this->definition($request, $definition);

        $this->authorize('delete', $model);

        // Throws `SignalsException::definitionInUse()` (422, rendered centrally) while a watch still
        // uses it — deleting it anyway would quietly change what that watch looks for, and a watch
        // that stops finding a kind of event looks exactly like a quiet week.
        $action->handle($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Signal type removed. The signals it already found are untouched.'),
        ]);

        return to_route('signal-definitions.index');
    }

    /**
     * Copy one or more library presets into the team's own types.
     */
    public function clone(CloneSignalPresetsRequest $request, CloneSignalPresetsAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('clone', $this->transientDefinition($team));

        $cloned = $action->handle($team, $this->user($request), $request->keys());

        Inertia::flash('toast', [
            'type' => 'success',
            // Count-neutral phrasing, so Arabic needs no dual or plural form (the S5 review's
            // standing rule for this module).
            'message' => __('Added to your signal types. Types added: :count', ['count' => $cloned->count()]),
        ]);

        return to_route('signal-definitions.index');
    }
}
