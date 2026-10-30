<?php

declare(strict_types=1);

namespace App\Modules\Outreach\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Actions\SuppressIdentityAction;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Http\Controllers\Concerns\ResolvesOutreach;
use App\Modules\Outreach\Http\Requests\SuppressIdentityRequest;
use App\Modules\Outreach\Http\Resources\OutreachSuppressionResource;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The do-not-contact list over the API (Phase 11, docs/compliance.md §3.2).
 *
 * There is deliberately **no lookup endpoint** — nothing that answers "is this address
 * suppressed?". Such an endpoint, over a list of exactly the people who asked to be left alone,
 * would be an enumeration oracle: a client could test any address it liked. Callers learn what
 * they need from the guard's decision for a lead they already hold.
 */
final class SuppressionController extends Controller
{
    use ResolvesOutreach;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(Request $request, SuppressionList $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transient(
            OutreachSuppression::class,
            $team,
            ['scope' => SuppressionScope::Team->value],
        ));

        return OutreachSuppressionResource::collection($list->forTeam($team)->load('team'));
    }

    public function store(SuppressIdentityRequest $request, SuppressIdentityAction $suppress): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transient(
            OutreachSuppression::class,
            $team,
            ['scope' => SuppressionScope::Team->value],
        ));

        $entry = $this->teamContext->run($team, fn (): OutreachSuppression => $suppress->handle(
            $team,
            $user,
            $request->type(),
            $request->value(),
            $request->note(),
        ));

        return OutreachSuppressionResource::make($entry->load('team'))->response()->setStatusCode(201);
    }

    public function destroy(Request $request, int $entry, SuppressIdentityAction $suppress): JsonResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $this->suppression($request, $entry);

        $this->authorize('delete', $model);

        $this->teamContext->run($team, fn () => $suppress->lift($model, $user));

        return new JsonResponse(status: 204);
    }
}
