<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Modules\Admin\Http\Requests\ResolveSubjectRequestRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Compliance\Actions\ResolveSubjectRequestAction;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Models\DataSubjectRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public data-subject request queue (PL5).
 *
 * **It lives in the panel rather than in a tenant's screens** because the request is addressed to
 * Alima: the person wrote to the platform, not to a customer, and showing one tenant a request
 * naming somebody another tenant also holds would be a disclosure between customers.
 *
 * **It owns no rule**, like everything else here (ADR 0014): `ResolveSubjectRequestAction` is
 * Compliance's, and the only thing this adds is who may reach it. The suppression and the purge
 * are not reachable from this screen at all — they happen at verification, because the parts that
 * follow mechanically from the person's own instruction must not wait for somebody to be at a desk.
 *
 * Behind `platform.write`: closing a statutory request is an act on the platform's own record, not
 * a read of a tenant's.
 *
 * **Unverified requests are not listed.** A queue that showed them would move the attack the
 * verification step prevents one screen along — an operator looking at a list of addresses somebody
 * typed into a public form.
 */
final class SubjectRequestController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly AdminAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $status = $this->status($request);

        $page = DataSubjectRequest::query()
            ->whereNotNull('verified_at')
            ->when($status instanceof SubjectRequestStatus, fn ($query) => $query->where('status', $status->value))
            // Open first, then the tightest deadline. An operator opens this screen to find what is
            // about to be late, not what arrived most recently.
            ->orderByRaw('CASE WHEN status = ? THEN 0 ELSE 1 END', [SubjectRequestStatus::Open->value])
            ->orderBy('due_at')
            ->orderByDesc('id')
            ->with('handler')
            ->paginate(25)
            ->withQueryString();

        // Audited, for the same reason the resource index is: the rows name people.
        $this->audit->record(AdminAudit::RESOURCE_VIEWED, null, [
            'resource' => 'subject-requests',
            'status' => $status?->value,
            'results' => $page->total(),
        ]);

        return Inertia::render('admin/compliance/Requests', [
            'navigation' => $this->registry->navigation(),
            'requests' => array_map(static fn (DataSubjectRequest $row): array => [
                'id' => (int) $row->getKey(),
                'type' => $row->type->value,
                'type_label' => $row->type->label(),
                'status' => $row->status->value,
                'status_label' => $row->status->label(),
                // Shown whole: an operator answering an access request has to be able to write
                // back, and this screen is already behind four controls and an audit entry.
                'email' => $row->email,
                'name' => $row->name,
                'company' => $row->company,
                'details' => $row->details,
                'verified_at' => $row->verified_at?->toIso8601String(),
                'due_at' => $row->due_at?->toIso8601String(),
                'days_remaining' => $row->daysRemaining(),
                'overdue' => $row->isOverdue(),
                'purged_at' => $row->purged_at?->toIso8601String(),
                'purge_summary' => $row->purge_summary,
                'handled_by' => $row->handler?->name,
                'resolution_note' => $row->resolution_note,
                'completed_at' => $row->completed_at?->toIso8601String(),
            ], $page->items()),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'filters' => ['status' => $status?->value],
            'statuses' => array_map(static fn (SubjectRequestStatus $case): array => [
                'value' => $case->value,
                'label' => $case->label(),
            ], SubjectRequestStatus::cases()),
        ]);
    }

    public function update(
        ResolveSubjectRequestRequest $request,
        int $subjectRequest,
        ResolveSubjectRequestAction $resolve,
    ): RedirectResponse {
        $model = DataSubjectRequest::query()->findOrFail($subjectRequest);

        $resolve->handle($model, $request->status(), $request->operator(), $request->note());

        return back()->with('toast', ['type' => 'success', 'message' => __('Request closed.')]);
    }

    private function status(Request $request): ?SubjectRequestStatus
    {
        $status = $request->query('status');

        return is_string($status) ? SubjectRequestStatus::tryFrom($status) : null;
    }
}
