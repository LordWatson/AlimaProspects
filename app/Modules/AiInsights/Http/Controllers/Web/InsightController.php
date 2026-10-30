<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\AiInsights\Actions\DismissInsightAction;
use App\Modules\AiInsights\Actions\ListInsightsAction;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Http\Controllers\Concerns\ResolvesInsights;
use App\Modules\AiInsights\Http\Requests\ListInsightsRequest;
use App\Modules\AiInsights\Http\Resources\InsightResource;
use App\Modules\AiInsights\Policies\InsightPolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The insights feed (Inertia). Thin: authorise via {@see InsightPolicy}, validate in a FormRequest,
 * call one Action, render or redirect.
 *
 * Server-driven, like the signals feed — the tab and the page live in the query string, so the
 * screen never holds more than a page and a shared URL reproduces what the sender saw. Dismiss
 * redirects back rather than returning JSON, so Inertia re-fetches the feed and the card leaves
 * without the client patching its own list.
 */
final class InsightController extends Controller
{
    use ResolvesInsights;

    public function index(ListInsightsRequest $request, ListInsightsAction $list): Response
    {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('view', $this->transientInsight($team));

        $status = $request->status();
        $insights = $list->handle($team, $status, $request->perPage());

        return Inertia::render('insights/Index', [
            // The whole paginator, so the screen gets `links` and `meta` for the shared pager.
            'insights' => InsightResource::collection($insights)->toResponse($request)->getData(true),
            'filters' => ['status' => $status->value],
            'counts' => $this->counts($list->counts($team)),
            // When the next run is due, in the team's own words. The feed is generated for a
            // member rather than by them, so a team with nothing yet needs to know it is coming
            // rather than wonder whether the screen is broken.
            'schedule' => [
                'hour' => max(0, min(23, (int) config('ai-insights.generate_at_hour', 7))),
                'timezone' => (string) $team->timezone,
            ],
            'can' => ['dismiss' => $user->can('dismiss', $this->transientInsight($team))],
        ]);
    }

    public function dismiss(Request $request, int $insight, DismissInsightAction $action): RedirectResponse
    {
        $model = $this->insight($request, $insight);

        $this->authorize('dismiss', $model);

        $action->handle($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => __('Cleared. You can find it under Dismissed.'),
        ]);

        return back();
    }

    /**
     * The tab counts, with every tab present. A status with no insights must come back as `0`
     * rather than missing, or the tab would render blank instead of saying "none".
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private function counts(array $counts): array
    {
        $all = [];

        foreach (InsightStatus::cases() as $status) {
            $all[$status->value] = $counts[$status->value] ?? 0;
        }

        return $all;
    }
}
