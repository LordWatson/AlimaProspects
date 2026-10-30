<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Actions\DeleteOutreachTemplateAction;
use App\Modules\Outreach\Actions\SaveOutreachTemplateAction;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\SaveOutreachTemplateRequest;
use App\Modules\Outreach\Http\Resources\OutreachTemplateResource;
use App\Modules\Outreach\Models\OutreachTemplate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * A team's message briefs over the API (Phase 11).
 */
final class OutreachTemplateController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transient(OutreachTemplate::class, $team));

        return $this->teamContext->run($team, fn (): AnonymousResourceCollection => OutreachTemplateResource::collection(
            OutreachTemplate::query()->with('team')->orderBy('channel')->orderBy('name')->get(),
        ));
    }

    public function store(SaveOutreachTemplateRequest $request, SaveOutreachTemplateAction $save): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transient(OutreachTemplate::class, $team));

        $template = $this->teamContext->run(
            $team,
            fn (): OutreachTemplate => $save->handle($team, $user, $request->templateData()),
        );

        return OutreachTemplateResource::make($template)->response()->setStatusCode(201);
    }

    public function show(Request $request, int $template): OutreachTemplateResource
    {
        $model = $this->template($request, $template);

        $this->authorize('view', $model);

        return OutreachTemplateResource::make($model);
    }

    public function update(
        SaveOutreachTemplateRequest $request,
        int $template,
        SaveOutreachTemplateAction $save,
    ): OutreachTemplateResource {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->template($request, $template);

        $this->authorize('update', $model);

        $saved = $this->teamContext->run(
            $team,
            fn (): OutreachTemplate => $save->handle($team, $user, $request->templateData(), $model),
        );

        return OutreachTemplateResource::make($saved);
    }

    public function destroy(Request $request, int $template, DeleteOutreachTemplateAction $delete): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->template($request, $template);

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $delete->handle($model, $user));

        return new JsonResponse(status: 204);
    }
}
