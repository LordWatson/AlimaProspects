<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Actions\CompleteLeadTaskAction;
use App\Modules\LeadsWorkspace\Actions\CreateLeadNoteAction;
use App\Modules\LeadsWorkspace\Actions\CreateLeadTaskAction;
use App\Modules\LeadsWorkspace\Actions\DeleteLeadNoteAction;
use App\Modules\LeadsWorkspace\Actions\DeleteLeadTaskAction;
use App\Modules\LeadsWorkspace\Actions\SetLeadAssigneesAction;
use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\CompleteLeadTaskRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SaveLeadNoteRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SaveLeadTaskRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SetLeadAssigneesRequest;
use App\Modules\LeadsWorkspace\Http\Requests\SyncLeadTagsRequest;
use App\Modules\LeadsWorkspace\Models\LeadNote;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The team's own work on a lead: tags, notes, tasks and assignment (Phase 10b). One controller
 * because they are one job — everything here is authorised by `LeadPolicy::work`, and splitting it
 * into four would be four copies of the same five lines.
 *
 * Every endpoint redirects back: these are all side panels on the lead detail screen, so the member
 * stays where they were and Inertia re-fetches the page's props.
 */
final class LeadCollaborationController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function tags(SyncLeadTagsRequest $request, int $lead, SyncLeadTagsAction $sync): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead, ['tags']);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $sync->handle($team, $model, $request->tags(), $user));

        return back();
    }

    public function assignees(
        SetLeadAssigneesRequest $request,
        int $lead,
        SetLeadAssigneesAction $assign,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $assign->handle($team, $model, $request->userIds(), $user));

        return back();
    }

    public function storeNote(SaveLeadNoteRequest $request, int $lead, CreateLeadNoteAction $create): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $create->handle($model, $user, $request->body()));

        return back();
    }

    public function destroyNote(Request $request, int $note, DeleteLeadNoteAction $delete): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->teamContext->run($team, fn (): LeadNote => LeadNote::query()
            ->where('team_id', $team->getKey())
            ->with('team')
            ->findOrFail($note));

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        return back();
    }

    public function storeTask(SaveLeadTaskRequest $request, int $lead, CreateLeadTaskAction $create): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->lead($request, $lead);

        $this->authorize('work', $model);

        $this->teamContext->run($team, fn () => $create->handle($model, $user, $request->taskData()));

        return back();
    }

    public function completeTask(
        CompleteLeadTaskRequest $request,
        int $task,
        CompleteLeadTaskAction $complete,
    ): RedirectResponse {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->task($team->getKey(), $task);

        $this->authorize('complete', $model);

        $this->teamContext->run($team, fn () => $complete->handle($model, $user, $request->done()));

        return back();
    }

    public function destroyTask(Request $request, int $task, DeleteLeadTaskAction $delete): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->task($team->getKey(), $task);

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        return back();
    }

    private function task(int|string $teamId, int $taskId): LeadTask
    {
        return $this->teamContext->run((int) $teamId, fn (): LeadTask => LeadTask::query()
            ->where('team_id', $teamId)
            ->with('team')
            ->findOrFail($taskId));
    }
}
