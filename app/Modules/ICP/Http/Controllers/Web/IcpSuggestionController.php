<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\DecideIcpSuggestionAction;
use App\Modules\ICP\Actions\FindIcpAction;
use App\Modules\ICP\Actions\FindIcpSuggestionAction;
use App\Modules\ICP\Actions\SuggestIcpRefinementsAction;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Suggestions for an ideal customer profile: ask for some, then accept or decline each one. Thin by
 * the house rule — authorise, call one Action, redirect. Every route needs the same ability as
 * editing the profile, because accepting a suggestion **is** editing it.
 */
final class IcpSuggestionController extends Controller
{
    public function store(Request $request, int $icp, FindIcpAction $find, SuggestIcpRefinementsAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $model = $find->handle($this->team($request), $icp);

        $this->authorize('update', $model);

        $raised = $action->handle($model, $user);

        Inertia::flash('toast', $raised->isEmpty()
            ? ['type' => 'info', 'message' => __('Alima has nothing new to suggest for this profile right now.')]
            : ['type' => 'success', 'message' => __('New suggestions from Alima: :count.', ['count' => $raised->count()])]);

        return back();
    }

    public function accept(Request $request, int $suggestion, FindIcpSuggestionAction $find, DecideIcpSuggestionAction $action): RedirectResponse
    {
        return $this->decide($request, $suggestion, $find, $action, accept: true);
    }

    public function decline(Request $request, int $suggestion, FindIcpSuggestionAction $find, DecideIcpSuggestionAction $action): RedirectResponse
    {
        return $this->decide($request, $suggestion, $find, $action, accept: false);
    }

    private function decide(Request $request, int $suggestion, FindIcpSuggestionAction $find, DecideIcpSuggestionAction $action, bool $accept): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        // Another team's suggestion is a 404; the policy then double-checks ownership (ADR 0006).
        $model = $find->handle($this->team($request), $suggestion);

        $this->authorize('update', $model->icp);

        $action->handle($model, $accept, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $accept
                ? __('Done. Your profile is updated and your leads will be graded again.')
                : __('Dismissed. Alima won’t suggest that again.'),
        ]);

        return back();
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }
}
