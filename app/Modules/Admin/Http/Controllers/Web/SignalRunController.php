<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\SignalRunFilterRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\SignalRunOversight;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-tenant `signal_runs` (Phase 19c) — the observability view Signals deferred at S6
 * (`signals-plan.md` task 6): cost, failures and durations per run.
 *
 * **One `GET` and nothing else.** No re-run, no retry. Each signal run spends real money at Serper
 * and on the AI; `TriggerSignalWatchAction` throttles a tenant's own re-runs for exactly that
 * reason, and an operator "helpfully" re-running somebody's watch would spend their allowance for
 * them. A tenant re-runs their own watch from their own screen.
 */
final class SignalRunController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly SignalRunOversight $oversight,
        private readonly AdminAudit $audit,
    ) {}

    public function index(SignalRunFilterRequest $request): Response
    {
        $window = $request->window();
        $page = $this->oversight->paginate($request->filters(), $window);

        // **Audited as a privacy event**, like a resource index and unlike the reporting screen.
        // The rows carry a tenant's watch names and a provider's error text — somebody else's
        // records, not an aggregate — and `AdminAudit`'s rule is that an operator reading those
        // leaves a trail. Filters and a count, never a row.
        $this->audit->record(AdminAudit::RESOURCE_VIEWED, null, [
            'resource' => 'signal-runs',
            'filters' => array_filter($request->filters(), static fn (mixed $v): bool => $v !== null),
            'from' => $window->fromDate(),
            'to' => $window->toDate(),
            'results' => $page->total(),
        ]);

        return Inertia::render('admin/signals/Runs', [
            'navigation' => $this->registry->navigation(),
            'runs' => $this->oversight->rows($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'summary' => $this->oversight->summary($window),
            'filters' => $request->formState() + $request->filters(),
        ]);
    }
}
