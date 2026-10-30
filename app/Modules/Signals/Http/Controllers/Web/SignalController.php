<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Http\Resources\PipelineStageResource;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Actions\CountSignalWatchesAction;
use App\Modules\Signals\Actions\DismissSignalAction;
use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Actions\RestoreSignalAction;
use App\Modules\Signals\Data\SignalWatchData;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\ConvertSignalRequest;
use App\Modules\Signals\Http\Requests\DismissSignalRequest;
use App\Modules\Signals\Http\Requests\ListSignalsRequest;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Policies\SignalPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Signals feed (Inertia). Thin: authorise via {@see SignalPolicy}, validate in a FormRequest,
 * call one Action, render or redirect.
 *
 * The list is **server-driven** — tab, filters and page all live in the query string — so the
 * screen never holds more than a page of signals and a shared URL reproduces what the sender saw.
 * The three action endpoints redirect back rather than returning JSON, so Inertia re-fetches the
 * feed and the card leaves (or changes tab) without the client patching its own list.
 */
final class SignalController extends Controller
{
    use ResolvesSignals;

    public function index(
        ListSignalsRequest $request,
        ListSignalsAction $list,
        CountSignalWatchesAction $countWatches,
        EnsureDefaultPipelineStagesAction $ensureStages,
    ): Response {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('view', $this->transientSignal($team));

        $filters = $request->filters();
        $signals = $list->handle($team, $filters, $request->perPage());

        // The feed is meaningless without a watch, and a team with none needs to be told that
        // rather than shown an empty list — so the count comes down with the page. A count, not
        // the list: the feed doesn't render watches and has no use for their run history.
        $watchCount = $countWatches->handle($team);

        return Inertia::render('signals/Index', [
            // The whole paginator, so the screen gets `links` and `meta` for the shared pager.
            'signals' => SignalResource::collection($signals)->toResponse($request)->getData(true),
            'filters' => $filters->toFormState(),
            'counts' => $this->counts($list->counts($team)),
            'options' => [
                'event_types' => array_map(static fn (SignalEventType $type): array => [
                    'value' => $type->value,
                    'label' => $type->label(),
                    'description' => $type->description(),
                ], SignalEventType::cases()),
                'dismiss_reasons' => array_map(static fn (SignalDismissReason $reason): array => [
                    'value' => $reason->value,
                    'label' => $reason->label(),
                ], SignalDismissReason::cases()),
            ],
            'watchCount' => $watchCount,
            // What a first watch would cover, so the empty state can name the market rather than
            // hard-coding "Dubai" in the markup.
            'defaults' => ['location' => SignalWatchData::defaultWatch()->location],
            // The convert modal's stage picker. Resolved here rather than on open, so clicking
            // Convert shows a form rather than a spinner.
            'stages' => PipelineStageResource::collection($ensureStages->handle($team))->resolve($request),
            'can' => [
                'action' => $user->can('dismiss', $this->transientSignal($team)),
                'manageWatches' => $user->can('create', $this->transientWatch($team)),
            ],
        ]);
    }

    public function convert(
        ConvertSignalRequest $request,
        int $signal,
        ConvertSignalToLeadAction $action,
    ): RedirectResponse {
        $model = $this->signal($request, $signal);

        $this->authorize('convert', $model);

        $lead = $action->handle($model, $this->user($request), $request->conversion($model));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('“:company” is in your leads — Alima is filling in the details now.', [
                'company' => $lead->company_name,
            ]),
        ]);

        // Straight to the lead: the member converted it to work it, and the origin panel there
        // shows the event they just acted on.
        return to_route('leads.show', ['lead' => $lead->getKey()]);
    }

    public function dismiss(
        DismissSignalRequest $request,
        int $signal,
        DismissSignalAction $action,
    ): RedirectResponse {
        $model = $this->signal($request, $signal);

        $this->authorize('dismiss', $model);

        $action->handle($model, $this->user($request), $request->reason());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Dismissed. You can bring it back from the Dismissed tab.'),
        ]);

        return back();
    }

    public function restore(Request $request, int $signal, RestoreSignalAction $action): RedirectResponse
    {
        $model = $this->signal($request, $signal);

        $this->authorize('restore', $model);

        $action->handle($model, $this->user($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Back in your new signals.')]);

        return back();
    }

    /**
     * The tab counts, with every tab present. A status with no signals must come back as `0`
     * rather than missing, or the tab would render blank instead of saying "none".
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function counts(array $counts): array
    {
        $all = [];

        foreach (SignalStatus::cases() as $status) {
            $all[$status->value] = $counts[$status->value] ?? 0;
        }

        return $all;
    }
}
