<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Actions\DeleteSavedViewAction;
use App\Modules\LeadsWorkspace\Actions\SaveViewAction;
use App\Modules\LeadsWorkspace\Http\Requests\SaveViewRequest;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\LeadsWorkspace\Policies\SavedViewPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Saving and removing a member's named leads views. Thin: authorise via {@see SavedViewPolicy},
 * call one Action, redirect back with a toast.
 *
 * Both endpoints resolve the view inside the team context so the global scope hides other teams'
 * rows — a view that isn't the team's own 404s before any policy runs.
 */
final class SavedViewController extends Controller
{
    public function __construct(private readonly TeamContext $teamContext) {}

    public function store(SaveViewRequest $request, SaveViewAction $save): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transientView($team, $user));

        $view = $this->teamContext->run($team, fn (): SavedView => $save->handle(
            $team,
            $user,
            $request->name(),
            $request->filters(),
            $request->isShared(),
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Saved “:name” — it’s in your views now.', ['name' => $view->name]),
        ]);

        return back();
    }

    public function destroy(Request $request, int $view, DeleteSavedViewAction $delete): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->teamContext->run($team, fn (): SavedView => SavedView::query()
            ->where('team_id', $team->getKey())
            // The policy reads the team, and a lazy read would trip preventLazyLoading.
            ->with('team')
            ->findOrFail($view));

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('View removed.'),
        ]);

        return back();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function team(Request $request): Team
    {
        /** @var Team $team */
        $team = $this->user($request)->currentTeam()->firstOrFail();

        return $team;
    }

    private function transientView(Team $team, User $user): SavedView
    {
        $model = new SavedView;
        $model->forceFill(['team_id' => $team->getKey(), 'user_id' => $user->getKey()]);
        $model->setRelation('team', $team);

        return $model;
    }
}
