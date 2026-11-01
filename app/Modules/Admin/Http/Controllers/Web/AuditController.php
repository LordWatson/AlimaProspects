<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\AuditFilterRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\AuditTrail;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The cross-tenant audit viewer (Phase 19c).
 *
 * **One route, and it is a `GET`.** `audit_logs` is append-only, so there is no update route, no
 * delete route and no Action — and the registry's `audit-logs` descriptor forbids all three as
 * well, which is the second lock. An audit trail an operator can edit is not an audit trail, and
 * the protection has to be the absence of a code path rather than a permission somebody could
 * grant themselves.
 *
 * Behind `audit.read` rather than `tenant.read`, as the resource descriptor already is: these are
 * other **operators'** actions as well as customers', which is a different permission even where
 * today's roles happen to carry both.
 *
 * Reading the trail is **not** itself audited, and that is a deliberate stop. {@see AdminAudit}
 * audits reads of tenant data because an operator opening somebody's prospect list is a privacy
 * event; the same rule here would mean every look at the trail wrote a row to the trail, which
 * grows without bound and buries the entries somebody came to find.
 */
final class AuditController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly AuditTrail $trail,
    ) {}

    public function index(AuditFilterRequest $request): Response
    {
        $window = $request->window();
        $page = $this->trail->paginate($request->filters(), $window);

        return Inertia::render('admin/audit/Index', [
            'navigation' => $this->registry->navigation(),
            'entries' => $this->trail->rows($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'filters' => $request->formState() + $request->filters(),
            'options' => $this->trail->options(),
        ]);
    }
}
