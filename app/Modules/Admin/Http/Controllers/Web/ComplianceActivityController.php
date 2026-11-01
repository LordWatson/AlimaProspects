<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\ComplianceActivityRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\ComplianceActivity;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Cross-tenant compliance activity (Phase 19c) — one of the three views earlier phases deferred
 * here, and the one `compliance-privacy.md` has carried a pointer to since Phase 15.
 *
 * **One `GET` and nothing else.** An erasure, an export and a retention policy are the *tenant's*
 * decisions as data controller — which is why `compliance.dsar.erase` and `compliance.lia.update`
 * are on the impersonation blocklist too — so there is no write route here, no Action, and nothing
 * for a future diff to turn into one by relaxing a permission. `PlatformOversightTest` asserts the
 * absence over the whole route table rather than trusting this comment.
 *
 * Behind `audit.read`: the activity is read out of `audit_logs`, and an operator who may not read
 * the trail may not read a filtered view of it either.
 */
final class ComplianceActivityController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly ComplianceActivity $activity,
        private readonly AdminAudit $audit,
    ) {}

    public function index(ComplianceActivityRequest $request): Response
    {
        $window = $request->window();
        $page = $this->activity->paginate($request->filters(), $window);

        // Audited, for the same reason the resource index is: the rows name tenants and their
        // assessments. The **general** audit viewer deliberately is not — see `AuditController`,
        // where auditing a read of the trail would write to the trail and bury what it is for.
        $this->audit->record(AdminAudit::RESOURCE_VIEWED, null, [
            'resource' => 'compliance-activity',
            'filters' => array_filter($request->filters(), static fn (mixed $v): bool => $v !== null),
            'from' => $window->fromDate(),
            'to' => $window->toDate(),
            'results' => $page->total(),
        ]);

        return Inertia::render('admin/compliance/Index', [
            'navigation' => $this->registry->navigation(),
            'events' => $this->activity->rows($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'assessments' => $this->activity->assessments(),
            'policies' => $this->activity->retentionPolicies(),
            'filters' => $request->formState() + $request->filters(),
            'actions' => $this->activity->actions(),
        ]);
    }
}
