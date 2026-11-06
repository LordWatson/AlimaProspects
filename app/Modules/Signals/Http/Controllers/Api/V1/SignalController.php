<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Actions\DismissSignalAction;
use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Actions\RestoreSignalAction;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\ConvertSignalRequest;
use App\Modules\Signals\Http\Requests\DismissSignalRequest;
use App\Modules\Signals\Http\Requests\ListSignalsRequest;
use App\Modules\Signals\Http\Resources\SignalResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Versioned `/api/v1` access to the signals feed (S6), consumed by the mobile app.
 *
 * Returns API Resources only — never models — and delegates to the **same Actions** the web
 * controller uses, so there is one definition of what converting or dismissing a signal means. The
 * one difference from the web is the shape of the answer: a domain error comes back as the standard
 * JSON envelope with its status (409 for an already-converted signal, 429 for a throttled run)
 * rather than as a redirect with a toast — see `bootstrap/app.php`.
 */
final class SignalController extends Controller
{
    use ResolvesSignals;

    public function index(ListSignalsRequest $request, ListSignalsAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientSignal($team));

        // `event_keys` is honoured as given. A stored query in somebody's integration naming a type
        // they have since deleted keeps returning that type's signals, which still exist and still
        // carry the key — rather than quietly widening to everything, which is what an earlier
        // version did and is the worse answer for a caller who asked a narrow question.
        return SignalResource::collection(
            $list->handle($team, $request->filters(), $request->perPage()),
        );
    }

    public function show(Request $request, int $signal): SignalResource
    {
        // `matchedLead` with the rest: the resource reads it whenever `matched_lead_id` is set, so
        // leaving it unloaded is a lazy-load violation here and an N+1 in production.
        $model = $this->signal($request, $signal, ['watch', 'lead', 'matchedLead', 'definition']);

        $this->authorize('view', $model);

        return SignalResource::make($model);
    }

    public function convert(
        ConvertSignalRequest $request,
        int $signal,
        ConvertSignalToLeadAction $action,
    ): SignalResource {
        $model = $this->signal($request, $signal);

        $this->authorize('convert', $model);

        $action->handle($model, $this->user($request), $request->conversion($model));

        // The converted signal, carrying the lead it created — so a client needs one call, not two,
        // to show "this is now lead 41". The Action refreshes the model, so this is the new state.
        return SignalResource::make($model->load(['watch', 'lead', 'matchedLead', 'definition']));
    }

    /**
     * Add this event to the lead it is about, creating no lead (S10).
     *
     * 200 and **the signal**, not the lead: the same shape `convert` returns, for the same reason —
     * a client needs one call to show "this is now on lead 41", and returning a different resource
     * from the two endpoints would make them two integrations rather than one. Nothing is created,
     * which is the whole point of the endpoint.
     */
    public function attach(
        Request $request,
        int $signal,
        ConvertSignalToLeadAction $action,
    ): SignalResource {
        $model = $this->signal($request, $signal, ['matchedLead']);

        $this->authorize('convert', $model);

        $lead = $model->matchedLead;

        if (! $lead instanceof Lead) {
            // The match was reported, then the lead was deleted or anonymised. The caller is told
            // plainly rather than handed a new lead they did not ask for.
            throw SignalsException::noLeadToAttachTo();
        }

        $action->handle(
            $model,
            $this->user($request),
            ConvertSignalData::fromSignalForLead($model, $lead),
            $lead,
        );

        return SignalResource::make($model->load(['watch', 'lead', 'matchedLead', 'definition']));
    }

    public function dismiss(
        DismissSignalRequest $request,
        int $signal,
        DismissSignalAction $action,
    ): SignalResource {
        $model = $this->signal($request, $signal);

        $this->authorize('dismiss', $model);

        return SignalResource::make(
            $action->handle($model, $this->user($request), $request->reason())->load(['watch', 'matchedLead', 'definition']),
        );
    }

    public function restore(Request $request, int $signal, RestoreSignalAction $action): SignalResource
    {
        $model = $this->signal($request, $signal);

        $this->authorize('restore', $model);

        return SignalResource::make(
            $action->handle($model, $this->user($request))->load(['watch', 'matchedLead', 'definition']),
        );
    }
}
