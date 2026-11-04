<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Actions\AddWatchlistEntriesAction;
use App\Modules\Signals\Actions\DeleteSignalWatchlistAction;
use App\Modules\Signals\Actions\RemoveWatchlistEntryAction;
use App\Modules\Signals\Actions\SaveSignalWatchlistAction;
use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\AddWatchlistEntriesRequest;
use App\Modules\Signals\Http\Requests\SaveSignalWatchlistRequest;
use App\Modules\Signals\Http\Resources\SignalWatchlistResource;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Policies\SignalWatchlistPolicy;
use App\Modules\Signals\Services\SignalSpendGuard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The accounts screen (Inertia, S10 — ADR 0021). Thin: authorise via
 * {@see SignalWatchlistPolicy}, validate in a FormRequest, call one Action, render or redirect.
 *
 * This is the screen that turns Signals from a discovery feed into a monitor: a team points a list
 * at their own pipeline, or types the names they care about, and a watch over that list asks what
 * happened to **those** companies rather than what happened in a city.
 *
 * Two deliberate asymmetries with the watch and type screens:
 *
 * - **Entries are posted as text, one name per line.** A member's source for this is a spreadsheet
 *   column or an email, and asking them to add forty rows one at a time would mean nobody used it.
 *   The shaping of that text into the structured payload the Action takes happens here, so the
 *   request rules describe one shape rather than two.
 * - **"Sync now" exists**, although a nightly job does it. A member who has just changed which
 *   stages a list mirrors wants to see the result, not a promise about tomorrow.
 */
final class SignalWatchlistController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request, SignalSpendGuard $spend): Response
    {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('viewAny', $this->transientWatchlist($team));

        $lists = app(TeamContext::class)->run($team, fn (): array => SignalWatchlist::query()
            ->with(['entries' => fn ($query) => $query->with('lead')->orderBy('name')])
            ->withCount('watches')
            ->orderBy('name')
            ->get()
            ->all());

        return Inertia::render('signals/Accounts', [
            'watchlists' => SignalWatchlistResource::collection($lists)->resolve($request),
            'options' => [
                // Only the kinds a member may choose: `segment` is declared on the enum and not
                // offered, because a case with no implementation is worse than no case.
                'source_kinds' => array_map(static fn (WatchlistSourceKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                    'description' => $kind->description(),
                ], WatchlistSourceKind::offered()),
                // The team's own stages, so "mirror my pipeline" can be narrowed to the stages
                // where watching an account is actually worth paying for.
                'stages' => app(TeamContext::class)->run($team, fn (): array => PipelineStage::query()
                    ->orderBy('position')
                    ->get(['id', 'name'])
                    ->map(static fn (PipelineStage $stage): array => [
                        'id' => (int) $stage->id,
                        'name' => $stage->name,
                    ])
                    ->all()),
                // The team's own ceiling since S16 — their plan's where it sets one — because the
                // sync and the manual add are both held to that rather than to the config floor.
                //
                // **The allowance's own nullable figure, not `maxWatchlistEntries()`.** That method
                // floors an unlimited allowance at `PHP_INT_MAX` so the Action's clamp has an
                // integer to compare against; sending it to a screen would print
                // 9,223,372,036,854,775,807 to an Enterprise team (S16 review). Null is "no limit",
                // and the screen says so in words.
                'max_entries' => $spend->allowanceFor($team)->maxWatchlistEntries,
                'max_lists' => max(1, (int) config('signals.watchlist.max_lists_per_team', 5)),
                // How many accounts one run looks at, so the screen can explain the rotation
                // rather than leaving somebody to wonder why a 250-account list is quiet.
                'window' => max(1, (int) config('signals.watchlist.window', 40)),
            ],
            'can' => ['manage' => $user->can('create', $this->transientWatchlist($team))],
        ]);
    }

    public function store(SaveSignalWatchlistRequest $request, SaveSignalWatchlistAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientWatchlist($team));

        $watchlist = $action->handle($team, $this->user($request), $request->watchlist());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $watchlist->source_kind->isSynced()
                ? __('“:name” is watching your pipeline. Accounts added: :count', [
                    'name' => $watchlist->name,
                    'count' => $watchlist->entries_count,
                ])
                : __('“:name” is ready. Add the companies you want watched.', ['name' => $watchlist->name]),
        ]);

        return to_route('signal-watchlists.index');
    }

    public function update(
        SaveSignalWatchlistRequest $request,
        int $watchlist,
        SaveSignalWatchlistAction $action,
    ): RedirectResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $action->handle($this->team($request), $this->user($request), $request->watchlist(), $model);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved.')]);

        return to_route('signal-watchlists.index');
    }

    public function destroy(
        Request $request,
        int $watchlist,
        DeleteSignalWatchlistAction $action,
    ): RedirectResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('delete', $model);

        $watches = $action->handle($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            // Says what happened to the watches, because it is not what somebody would assume: a
            // watch pointed at a deleted list keeps its history and goes back to searching places.
            'message' => $watches > 0
                ? __('List removed. Watches that used it now search by place instead. Watches changed: :count', ['count' => $watches])
                : __('List removed.'),
        ]);

        return to_route('signal-watchlists.index');
    }

    /**
     * Add names to a manual list. The screen posts a textarea; this shapes it.
     */
    public function storeEntries(
        AddWatchlistEntriesRequest $request,
        int $watchlist,
        AddWatchlistEntriesAction $action,
    ): RedirectResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $result = $action->handle($this->team($request), $this->user($request), $model, $request->entries());

        Inertia::flash('toast', [
            'type' => $result['added'] > 0 ? 'success' : 'info',
            // Counts rather than a sentence per outcome: a paste of forty names that adds
            // thirty-one and recognises nine is the normal case, and saying so is what stops it
            // reading as a failure. Count-neutral phrasing, so Arabic needs no plural forms.
            'message' => __('Accounts added: :added. Already on the list: :duplicates. Not added: :skipped', [
                'added' => $result['added'],
                'duplicates' => $result['duplicates'],
                'skipped' => $result['skipped'],
            ]),
        ]);

        return to_route('signal-watchlists.index');
    }

    public function destroyEntry(
        Request $request,
        int $watchlist,
        int $entry,
        RemoveWatchlistEntryAction $action,
    ): RedirectResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('update', $model);

        $action->handle(
            $this->team($request),
            $this->user($request),
            $model,
            $this->watchlistEntry($request, $watchlist, $entry),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed from the list.')]);

        return to_route('signal-watchlists.index');
    }

    /**
     * Bring a pipeline list in step now, rather than waiting for tonight.
     */
    public function sync(
        Request $request,
        int $watchlist,
        SyncPipelineWatchlistAction $action,
    ): RedirectResponse {
        $model = $this->watchlist($request, $watchlist);

        $this->authorize('sync', $model);

        $result = $action->handle($this->team($request), $model);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Up to date. Added: :added. Removed: :removed. Watching: :kept', [
                'added' => $result['added'],
                'removed' => $result['removed'],
                'kept' => $result['kept'],
            ]),
        ]);

        return to_route('signal-watchlists.index');
    }
}
