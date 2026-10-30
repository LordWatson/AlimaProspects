<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadsWorkspace\Actions\DeletePipelineStageAction;
use App\Modules\LeadsWorkspace\Actions\ListPipelineAction;
use App\Modules\LeadsWorkspace\Actions\MoveLeadStageAction;
use App\Modules\LeadsWorkspace\Actions\ReorderPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\SavePipelineStageAction;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\MoveLeadStageRequest;
use App\Modules\LeadsWorkspace\Http\Requests\ReorderPipelineStagesRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SavePipelineStageRequest;
use App\Modules\LeadsWorkspace\Http\Resources\PipelineStageResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The kanban board and the stages it is made of (Phase 10b). Thin: authorise, call one Action,
 * render or redirect back with a toast.
 *
 * Moving one lead between columns is `LeadPolicy::work` (any member); changing the board itself is
 * `PipelineStagePolicy::manage` (Owner/Admin) — the board is shared furniture, and reordering it
 * changes how every member sees every lead.
 */
final class PipelineController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(Request $request, ListPipelineAction $list): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        ['stages' => $stages, 'leads' => $leads, 'counts' => $counts] = $list->handle($team);

        return Inertia::render('leads/Pipeline', [
            'stages' => PipelineStageResource::collection($stages)->resolve($request),
            // Keyed by stage id, so the board renders a column from its own stage without
            // re-filtering a flat list on the client.
            'cards' => collect($leads)
                ->map(fn ($column) => LeadResource::collection($column)->resolve($request))
                ->all(),
            'counts' => $counts,
            'columnLimit' => (int) config('leads-workspace.board_column_limit', 25),
            'can' => [
                'work' => $user->can('work', $this->transientLead($team)),
                'manageStages' => $user->can('manage', $this->transientStage($team)),
            ],
        ]);
    }

    public function move(
        MoveLeadStageRequest $request,
        int $lead,
        MoveLeadStageAction $move,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['pipelineStage']);

        $this->authorize('work', $model);

        $stage = $this->stage($request, $request->stageId());

        $this->teamContext->run($team, fn () => $move->handle($model, $stage, $user));

        return back();
    }

    public function store(SavePipelineStageRequest $request, SavePipelineStageAction $save): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('manage', $this->transientStage($team));

        $stage = $this->teamContext->run($team, fn () => $save->handle(
            $team,
            $user,
            $request->stageName(),
            $request->outcome(),
            $request->isDefault(),
        ));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Added “:name” to your board.', ['name' => $stage->name]),
        ]);

        return back();
    }

    public function update(
        SavePipelineStageRequest $request,
        int $stage,
        SavePipelineStageAction $save,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->stage($request, $stage);

        $this->authorize('manage', $model);

        $this->teamContext->run($team, fn () => $save->handle(
            $team,
            $user,
            $request->stageName(),
            $request->outcome(),
            $request->isDefault(),
            $model,
        ));

        return back();
    }

    public function reorder(
        ReorderPipelineStagesRequest $request,
        ReorderPipelineStagesAction $reorder,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('manage', $this->transientStage($team));

        $this->teamContext->run($team, fn () => $reorder->handle($team, $user, $request->orderedIds()));

        return back();
    }

    public function destroy(
        Request $request,
        int $stage,
        DeletePipelineStageAction $delete,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->stage($request, $stage);

        $this->authorize('manage', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Stage removed — its leads moved to your starting stage.'),
        ]);

        return back();
    }
}
