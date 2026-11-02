<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Signals\Actions\CloneSignalPresetsAction;
use App\Modules\Signals\Actions\DeleteSignalDefinitionAction;
use App\Modules\Signals\Actions\ListSignalDefinitionsAction;
use App\Modules\Signals\Actions\SaveSignalDefinitionAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\CloneSignalPresetsRequest;
use App\Modules\Signals\Http\Requests\SaveSignalDefinitionRequest;
use App\Modules\Signals\Http\Resources\SignalDefinitionResource;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Services\PlatformPresetReader;
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
 * The presets are filtered to the market a watch can actually be created in
 * ({@see SignalWatchData::DEFAULT_MARKET}), not the team's own market: v1 watches are UAE-only
 * whatever market the team trades in, so offering a type whose templates only work elsewhere would
 * be offering something that finds nothing. S8 is where those two stop being different questions.
 */
final class SignalDefinitionController extends Controller
{
    use ResolvesSignals;

    public function index(
        Request $request,
        ListSignalDefinitionsAction $list,
        PlatformPresetReader $presets,
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
                $presets->active(SignalWatchData::DEFAULT_MARKET)
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
            ],
            // The floors that apply to a type that sets none, so the form's placeholders can say
            // what "leave this empty" actually means rather than showing a bare box.
            'defaults' => [
                'min_confidence' => (int) config('signals.min_confidence', 60),
                'min_relevance' => (int) config('signals.min_relevance', 40),
                'max_definitions' => max(1, (int) config('signals.max_definitions_per_team', 25)),
            ],
            'can' => ['manage' => $user->can('create', $this->transientDefinition($team))],
        ]);
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
