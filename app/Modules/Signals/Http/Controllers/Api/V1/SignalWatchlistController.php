<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\AddWatchlistEntriesAction;
use App\Modules\Signals\Actions\DeleteSignalWatchlistAction;
use App\Modules\Signals\Actions\RemoveWatchlistEntryAction;
use App\Modules\Signals\Actions\SaveSignalWatchlistAction;
use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\AddWatchlistEntriesRequest;
use App\Modules\Signals\Http\Requests\SaveSignalWatchlistRequest;
use App\Modules\Signals\Http\Resources\SignalWatchlistResource;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Policies\SignalWatchlistPolicy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

/**
 * Versioned `/api/v1` CRUD for watchlists (S10), authorised by {@see SignalWatchlistPolicy} and
 * delegating to the same Actions as the screen — so there is one definition of what a watchlist is
 * and what adding to one means.
 *
 * **Bulk add is the point of this endpoint existing.** A team with a CRM has the list of accounts
 * they care about somewhere else, and typing them into a textarea is a worse answer than posting
 * them. `entries[]` is bounded by the list's own cap, so a client cannot discover the limit by
 * having half a batch silently dropped.
 *
 * Not paginated: a team may hold five lists of 250 accounts, and paging through that to render a
 * picker would be work for nothing — the same reasoning the preset library uses.
 */
final class SignalWatchlistController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('viewAny', $this->transientWatchlist($team));

        return SignalWatchlistResource::collection(
            app(TeamContext::class)->run($team, fn (): array => SignalWatchlist::query()
                ->withCount('watches')
                ->orderBy('name')
                ->get()
                ->all()),
        );
    }

    public function show(Request $request, int $watchlist): SignalWatchlistResource
    {
        $model = $this->watchlist($request, $watchlist, ['entries.lead']);

        $this->authorize('view', $model);

        // The count the index sends too, so one list looks the same whichever endpoint returned
        // it — and so a client can see what deleting it would stop before it deletes it.
        $model->loadCount('watches');

        return new SignalWatchlistResource($model);
    }

    public function store(SaveSignalWatchlistRequest $request, SaveSignalWatchlistAction $action): JsonResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientWatchlist($team));

        $watchlist = $action->handle($team, $this->user($request), $request->watchlist());

        return (new SignalWatchlistResource($watchlist->load('entries')))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    public function update(
        SaveSignalWatchlistRequest $request,
        int $watchlist,
        SaveSignalWatchlistAction $action,
    ): SignalWatchlistResource {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $saved = $action->handle($this->team($request), $this->user($request), $request->watchlist(), $model);

        return new SignalWatchlistResource($saved->load('entries'));
    }

    public function destroy(Request $request, int $watchlist, DeleteSignalWatchlistAction $action): Response
    {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('delete', $model);

        $action->handle($model, $this->user($request));

        return response()->noContent();
    }

    /**
     * Add accounts in bulk. 200 with the list, not 201: the list already existed.
     */
    public function storeEntries(
        AddWatchlistEntriesRequest $request,
        int $watchlist,
        AddWatchlistEntriesAction $action,
    ): JsonResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $result = $action->handle($this->team($request), $this->user($request), $model, $request->entries());

        // The counts travel beside the list, because "thirty-one added, nine already there" is the
        // normal outcome of a bulk add and a client that could not tell the difference would have
        // to diff the two lists itself.
        return (new SignalWatchlistResource($model->fresh(['entries.lead'])))
            ->additional(['meta' => $result])
            ->response();
    }

    public function destroyEntry(
        Request $request,
        int $watchlist,
        int $entry,
        RemoveWatchlistEntryAction $action,
    ): Response {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $action->handle(
            $this->team($request),
            $this->user($request),
            $model,
            $this->watchlistEntry($request, $watchlist, $entry),
        );

        return response()->noContent();
    }

    /**
     * Bring a pipeline list in step now rather than tonight.
     */
    public function sync(
        Request $request,
        int $watchlist,
        SyncPipelineWatchlistAction $action,
    ): JsonResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('sync', $model);

        $result = $action->handle($this->team($request), $model);

        return (new SignalWatchlistResource($model->fresh(['entries.lead'])))
            ->additional(['meta' => $result])
            ->response();
    }
}
