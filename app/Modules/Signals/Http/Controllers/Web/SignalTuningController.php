<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\ApplySignalTuningSuggestionAction;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Models\SignalTuningSuggestion;
use App\Modules\Signals\Policies\SignalTuningSuggestionPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Answering a tuning suggestion (S12). Thin: authorise via {@see SignalTuningSuggestionPolicy},
 * call one Action, redirect back.
 *
 * Two routes and no index, because the suggestions arrive **with the watch settings screen** rather
 * than from a fetch of their own — a panel that loaded separately would flash empty on every visit
 * for the one in ten visits that has something in it.
 *
 * Both redirect back rather than returning JSON, like the feed's own action endpoints, so Inertia
 * re-fetches the screen and the panel loses the card without the client patching its own list.
 */
final class SignalTuningController extends Controller
{
    use ResolvesSignals;

    public function accept(
        Request $request,
        int $suggestion,
        ApplySignalTuningSuggestionAction $action,
    ): RedirectResponse {
        $model = $this->suggestion($request, $suggestion);

        $this->authorize('update', $model);

        $action->accept($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            // Deliberately plain about what happened rather than congratulatory: the member has
            // just changed how their feed behaves, and the useful thing to tell them is that it
            // took effect and where to undo it.
            'message' => __('Done — that change is live. You can edit it yourself at any time.'),
        ]);

        return back();
    }

    public function decline(
        Request $request,
        int $suggestion,
        ApplySignalTuningSuggestionAction $action,
    ): RedirectResponse {
        $model = $this->suggestion($request, $suggestion);

        $this->authorize('update', $model);

        $action->decline($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            // The promise that makes declining safe to click, and it is a real one: the advisor
            // reads the declined row before proposing and never raises the same thing again.
            'message' => __('Noted — Alima won’t suggest that again.'),
        ]);

        return back();
    }

    /**
     * A team-scoped read of one suggestion.
     *
     * Read inside the team's context like every other resolve in this module, which is what makes a
     * cross-tenant request a **404 before any policy runs**; the policy still double-checks
     * (ADR 0006).
     */
    private function suggestion(Request $request, int $id): SignalTuningSuggestion
    {
        $team = $this->team($request);

        return app(TeamContext::class)->run($team, static fn (): SignalTuningSuggestion => SignalTuningSuggestion::query()
            ->where('team_id', $team->getKey())
            // `team` for the policy, which reads it, and `definition` because an accept writes to
            // it — a lazy read of either would trip `preventLazyLoading`.
            ->with(['team', 'definition'])
            ->findOrFail($id));
    }
}
