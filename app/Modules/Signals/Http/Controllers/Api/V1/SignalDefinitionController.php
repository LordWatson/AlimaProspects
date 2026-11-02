<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Signals\Actions\CloneSignalPresetsAction;
use App\Modules\Signals\Actions\DeleteSignalDefinitionAction;
use App\Modules\Signals\Actions\ListSignalDefinitionsAction;
use App\Modules\Signals\Actions\SaveSignalDefinitionAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\CloneSignalPresetsRequest;
use App\Modules\Signals\Http\Requests\SaveSignalDefinitionRequest;
use App\Modules\Signals\Http\Resources\SignalDefinitionResource;
use App\Modules\Signals\Policies\SignalDefinitionPolicy;
use App\Modules\Signals\Services\PlatformPresetReader;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Versioned `/api/v1` CRUD for signal definitions (S7, ADR 0018), authorised by
 * {@see SignalDefinitionPolicy} and delegating to the same Actions as the web screen — so there is
 * one definition of what creating, editing or cloning a signal type means.
 *
 * `presets` is read-only and deliberately **not** paginated: the library is a curated set measured
 * in tens, and a client that has to page through it to render a picker is a client we made do extra
 * work for nothing. `PaginatedIndexRequest` governs the endpoints that return a tenant's own
 * growing collections; this is not one.
 *
 * It is filtered to **the team's own market** since S8 (ADR 0019) — a UK team is offered the UK
 * pack, and a preset with no `markets` is offered to everyone.
 */
final class SignalDefinitionController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request, ListSignalDefinitionsAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('viewAny', $this->transientDefinition($team));

        return SignalDefinitionResource::collection($list->handle($team));
    }

    /**
     * The platform preset library, filtered to the team's own market.
     */
    public function presets(Request $request, PlatformPresetReader $presets): AnonymousResourceCollection
    {
        $team = $this->team($request);

        // Authorised as a read of the team's own library, because that is what it is for: the
        // presets themselves carry nothing tenant-specific, and `viewAny` is every role including
        // Read-only.
        $this->authorize('viewAny', $this->transientDefinition($team));

        return SignalDefinitionResource::collection(
            $presets->active(SignalWatchData::marketFor($team->market)),
        );
    }

    public function show(Request $request, int $definition): SignalDefinitionResource
    {
        $model = $this->definition($request, $definition);

        $this->authorize('view', $model);

        return SignalDefinitionResource::make($model->loadCount(['watches', 'signals']));
    }

    public function store(SaveSignalDefinitionRequest $request, SaveSignalDefinitionAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientDefinition($team));

        $definition = $action->handle($team, $this->user($request), $request->definition());

        return SignalDefinitionResource::make($definition)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        SaveSignalDefinitionRequest $request,
        int $definition,
        SaveSignalDefinitionAction $action,
    ): SignalDefinitionResource {
        $model = $this->definition($request, $definition);

        $this->authorize('update', $model);

        return SignalDefinitionResource::make(
            $action->handle($this->team($request), $this->user($request), $request->definition(), $model),
        );
    }

    public function destroy(Request $request, int $definition, DeleteSignalDefinitionAction $action): Response
    {
        $model = $this->definition($request, $definition);

        $this->authorize('delete', $model);

        // 422 `signals.definition_in_use` while a watch still uses it, rendered as the standard
        // envelope. The signals it already found are kept either way (ADR 0018 §4).
        $action->handle($model, $this->user($request));

        return response()->noContent();
    }

    /**
     * Copy library presets into the team's own types. 201, because it creates rows.
     */
    public function clone(CloneSignalPresetsRequest $request, CloneSignalPresetsAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('clone', $this->transientDefinition($team));

        $cloned = $action->handle($team, $this->user($request), $request->keys());

        return SignalDefinitionResource::collection($cloned)
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
