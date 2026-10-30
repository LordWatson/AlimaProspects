<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Signals\Actions\DeleteSignalWatchAction;
use App\Modules\Signals\Actions\ListSignalWatchesAction;
use App\Modules\Signals\Actions\SaveSignalWatchAction;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\SaveSignalWatchRequest;
use App\Modules\Signals\Http\Resources\SignalWatchResource;
use App\Modules\Signals\Policies\SignalWatchPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Versioned `/api/v1` CRUD for signal watches (S6), authorised by {@see SignalWatchPolicy} and
 * delegating to the same Actions as the web screen.
 *
 * `run` answers **202 Accepted** with no body: it queues a run and returns immediately, because
 * the work is a Serper search plus an AI pass and the results arrive over Reverb (or on the next
 * `GET`). A client that gets 202 should poll the watch, not wait on this call.
 */
final class SignalWatchController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request, ListSignalWatchesAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientWatch($team));

        return SignalWatchResource::collection($list->handle($team));
    }

    public function show(Request $request, int $watch): SignalWatchResource
    {
        $model = $this->watch($request, $watch, ['icp', 'runs']);

        $this->authorize('view', $model);

        return SignalWatchResource::make($model->loadCount('signals'));
    }

    public function store(SaveSignalWatchRequest $request, SaveSignalWatchAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientWatch($team));

        $watch = $action->handle($team, $this->user($request), $request->watch());

        return SignalWatchResource::make($watch->load('icp'))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(SaveSignalWatchRequest $request, int $watch, SaveSignalWatchAction $action): SignalWatchResource
    {
        $model = $this->watch($request, $watch);

        $this->authorize('update', $model);

        $updated = $action->handle($this->team($request), $this->user($request), $request->watch(), $model);

        return SignalWatchResource::make($updated->load('icp'));
    }

    public function destroy(Request $request, int $watch, DeleteSignalWatchAction $action): Response
    {
        $model = $this->watch($request, $watch);

        $this->authorize('delete', $model);

        // Takes the watch's runs and its un-converted signals with it. A **converted** signal
        // stays: it is the provenance of a lead the team may still be working, so both FKs are
        // `nullOnDelete` and the row outlives the watch (S6).
        $action->handle($model, $this->user($request));

        return response()->noContent();
    }

    public function run(Request $request, int $watch, TriggerSignalWatchAction $action): Response
    {
        $model = $this->watch($request, $watch);

        $this->authorize('run', $model);

        // Throws a SignalsException — 409 for a paused watch, 429 when run inside the per-watch
        // 15-minute window — rendered as the standard JSON envelope.
        $action->handle($model);

        return response()->noContent(Response::HTTP_ACCEPTED);
    }
}
