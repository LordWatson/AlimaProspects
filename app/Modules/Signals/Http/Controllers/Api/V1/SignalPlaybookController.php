<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Http\Requests\PaginatedIndexRequest;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\DeleteSignalPlaybookAction;
use App\Modules\Signals\Actions\PreviewSignalPlaybookAction;
use App\Modules\Signals\Actions\SaveSignalPlaybookAction;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\PreviewSignalPlaybookRequest;
use App\Modules\Signals\Http\Requests\SaveSignalPlaybookRequest;
use App\Modules\Signals\Http\Resources\SignalPlaybookResource;
use App\Modules\Signals\Http\Resources\SignalPlaybookRunResource;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Playbooks over the API (Sanctum, S14) — parity with the web screens, the same Actions behind both.
 *
 * Two things follow the module's existing decisions rather than inventing anything:
 *
 * - **`SignalsRead` / `SignalsWrite`**, not a scope of their own. A playbook is part of Signals, and
 *   a token that could read the feed but not the rules acting on it is a credential nobody would
 *   mean to issue (the same argument the definition and watchlist routes make).
 * - **`preview` is a write ability all the same.** It spends nothing — which is why it has no
 *   throttle and the policy lets a Read-only *member* run it — but a scope asks what a
 *   *credential* may do (ADR 0013), and previewing a rule means posting one. There is nothing for a
 *   read-only token to preview.
 *
 * The run log is paginated through `PaginatedIndexRequest`, like every other list in the API, so
 * `per_page` has one definition.
 */
final class SignalPlaybookController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('viewAny', $this->transientPlaybook($team));

        $playbooks = app(TeamContext::class)->run($team, fn (): array => SignalPlaybook::query()
            ->withCount('runs')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all());

        return SignalPlaybookResource::collection($playbooks);
    }

    public function show(Request $request, int $playbook): SignalPlaybookResource
    {
        $model = $this->playbook($request, $playbook);

        $this->authorize('view', $model);

        return new SignalPlaybookResource($model->loadCount('runs'));
    }

    public function store(SaveSignalPlaybookRequest $request, SaveSignalPlaybookAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientPlaybook($team));

        $playbook = $action->handle($team, $this->user($request), $request->playbook());

        return (new SignalPlaybookResource($playbook->loadCount('runs')))
            ->response()
            ->setStatusCode(201);
    }

    public function update(
        SaveSignalPlaybookRequest $request,
        int $playbook,
        SaveSignalPlaybookAction $action,
    ): SignalPlaybookResource {
        $model = $this->playbook($request, $playbook);

        $this->authorize('update', $model);

        $saved = $action->handle($this->team($request), $this->user($request), $request->playbook(), $model);

        return new SignalPlaybookResource($saved->loadCount('runs'));
    }

    public function destroy(Request $request, int $playbook, DeleteSignalPlaybookAction $action): JsonResponse
    {
        $model = $this->playbook($request, $playbook);

        $this->authorize('delete', $model);

        $action->handle($model, $this->user($request));

        return response()->json(status: 204);
    }

    /**
     * What a rule would have done to the signals the team already has.
     */
    public function preview(PreviewSignalPlaybookRequest $request, PreviewSignalPlaybookAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('preview', $this->transientPlaybook($team));

        return response()->json([
            'data' => $action->handle($team, $request->conditions(), $request->kinds())->toPayload(),
        ]);
    }

    /**
     * The run log, newest first.
     */
    public function runs(PaginatedIndexRequest $request): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('viewAny', $this->transientPlaybook($team));

        $attention = $request->boolean('attention');

        $runs = app(TeamContext::class)->run($team, fn () => SignalPlaybookRun::query()
            ->with(['signal', 'lead', 'actor'])
            ->when($attention, static fn ($query) => $query->where(
                static fn ($inner) => $inner->where('refused_count', '>', 0)->orWhere('failed_count', '>', 0),
            ))
            ->orderByDesc('id')
            ->paginate($request->perPage())
            ->withQueryString());

        return SignalPlaybookRunResource::collection($runs);
    }
}
