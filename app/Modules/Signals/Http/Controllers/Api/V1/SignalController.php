<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Actions\DismissSignalAction;
use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Actions\RestoreSignalAction;
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

        return SignalResource::collection(
            $list->handle($team, $request->filters(), $request->perPage()),
        );
    }

    public function show(Request $request, int $signal): SignalResource
    {
        $model = $this->signal($request, $signal, ['watch', 'lead']);

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
        return SignalResource::make($model->load(['watch', 'lead']));
    }

    public function dismiss(
        DismissSignalRequest $request,
        int $signal,
        DismissSignalAction $action,
    ): SignalResource {
        $model = $this->signal($request, $signal);

        $this->authorize('dismiss', $model);

        return SignalResource::make(
            $action->handle($model, $this->user($request), $request->reason())->load('watch'),
        );
    }

    public function restore(Request $request, int $signal, RestoreSignalAction $action): SignalResource
    {
        $model = $this->signal($request, $signal);

        $this->authorize('restore', $model);

        return SignalResource::make(
            $action->handle($model, $this->user($request))->load('watch'),
        );
    }
}
