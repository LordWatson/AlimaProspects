<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\AiInsights\Actions\DismissInsightAction;
use App\Modules\AiInsights\Actions\ListInsightsAction;
use App\Modules\AiInsights\Http\Controllers\Concerns\ResolvesInsights;
use App\Modules\AiInsights\Http\Requests\ListInsightsRequest;
use App\Modules\AiInsights\Http\Resources\InsightResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Versioned `/api/v1` access to the insights feed (Phase 14), consumed by the mobile app.
 *
 * Returns API Resources only — never models — and delegates to the **same Actions** the web
 * controller uses, so there is one definition of what dismissing an insight means. The one
 * difference from the web is the shape of the answer: JSON rather than a redirect with a toast.
 */
final class InsightController extends Controller
{
    use ResolvesInsights;

    public function index(ListInsightsRequest $request, ListInsightsAction $list): AnonymousResourceCollection
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientInsight($team));

        return InsightResource::collection(
            $list->handle($team, $request->status(), $request->perPage()),
        );
    }

    public function show(Request $request, int $insight): InsightResource
    {
        $model = $this->insight($request, $insight);

        $this->authorize('view', $model);

        return InsightResource::make($model);
    }

    public function dismiss(Request $request, int $insight, DismissInsightAction $action): InsightResource
    {
        $model = $this->insight($request, $insight);

        $this->authorize('dismiss', $model);

        // The dismissed insight, so a client can update its list from the response rather than
        // re-fetching the feed.
        return InsightResource::make($action->handle($model, $this->user($request)));
    }
}
