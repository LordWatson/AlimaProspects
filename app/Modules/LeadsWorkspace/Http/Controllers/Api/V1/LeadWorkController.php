<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadsWorkspace\Actions\CompleteLeadTaskAction;
use App\Modules\LeadsWorkspace\Actions\CreateLeadNoteAction;
use App\Modules\LeadsWorkspace\Actions\CreateLeadTaskAction;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\MoveLeadStageAction;
use App\Modules\LeadsWorkspace\Actions\SetLeadAssigneesAction;
use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\CompleteLeadTaskRequest;
use App\Modules\LeadsWorkspace\Http\Requests\MoveLeadStageRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SaveLeadNoteRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SaveLeadTaskRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SetLeadAssigneesRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SyncLeadTagsRequest;
use App\Modules\LeadsWorkspace\Http\Resources\LeadNoteResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTagResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTaskResource;
use App\Modules\LeadsWorkspace\Http\Resources\PipelineStageResource;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Versioned `/api/v1` access to the team's work on a lead — the mobile app's stage move, note, task
 * and assignment (docs/modules/leads-workspace.md). Returns API Resources only and delegates to the
 * same Actions the web controllers use, so a lead worked from a phone gets exactly the same
 * timeline and audit entries as one worked from the browser.
 */
final class LeadWorkController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private readonly TeamContext $teamContext) {}

    /**
     * The team's board, so a mobile stage picker knows what the columns are.
     *
     * @return AnonymousResourceCollection<Collection<int, PipelineStage>>
     */
    public function stages(Request $request, EnsureDefaultPipelineStagesAction $ensure): AnonymousResourceCollection
    {
        return PipelineStageResource::collection($ensure->handle($this->team($request)));
    }

    public function move(MoveLeadStageRequest $request, int $lead, MoveLeadStageAction $move): LeadResource
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['pipelineStage']);

        $this->authorize('work', $model);

        $stage = $this->stage($request, $request->stageId());

        $this->teamContext->run($team, fn () => $move->handle($model, $stage, $user));

        return LeadResource::make($model->load(['pipelineStage', 'tags', 'assignments.user:id,name']));
    }

    public function storeNote(SaveLeadNoteRequest $request, int $lead, CreateLeadNoteAction $create): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $note = $this->teamContext->run($team, fn () => $create->handle($model, $user, $request->body()));

        return LeadNoteResource::make($note->load('user:id,name'))->response()->setStatusCode(201);
    }

    public function storeTask(SaveLeadTaskRequest $request, int $lead, CreateLeadTaskAction $create): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $task = $this->teamContext->run($team, fn () => $create->handle($model, $user, $request->taskData()));

        return LeadTaskResource::make($task->load('assignee:id,name'))->response()->setStatusCode(201);
    }

    public function completeTask(
        CompleteLeadTaskRequest $request,
        int $task,
        CompleteLeadTaskAction $complete,
    ): LeadTaskResource {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->teamContext->run($team, fn (): LeadTask => LeadTask::query()
            ->where('team_id', $team->getKey())
            ->with(['team', 'assignee:id,name'])
            ->findOrFail($task));

        $this->authorize('complete', $model);

        $this->teamContext->run($team, fn () => $complete->handle($model, $user, $request->done()));

        return LeadTaskResource::make($model);
    }

    /**
     * @return AnonymousResourceCollection<Collection<int, LeadTag>>
     */
    public function tags(SyncLeadTagsRequest $request, int $lead, SyncLeadTagsAction $sync): AnonymousResourceCollection
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['tags']);

        $this->authorize('work', $model);

        $tags = $this->teamContext->run($team, fn () => $sync->handle($team, $model, $request->tags(), $user));

        return LeadTagResource::collection($tags);
    }

    public function assignees(
        SetLeadAssigneesRequest $request,
        int $lead,
        SetLeadAssigneesAction $assign,
    ): LeadResource {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $assign->handle($team, $model, $request->userIds(), $user));

        return LeadResource::make($model->load(['assignments.user:id,name', 'tags', 'pipelineStage']));
    }
}
