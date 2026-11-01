<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\AddPlatformSuppressionAction;
use App\Modules\Admin\Http\Requests\AddPlatformSuppressionRequest;
use App\Modules\Admin\Http\Requests\PlatformWindowRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\Platform\OutreachOversight;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Outreach oversight and the global do-not-contact list (Phase 19c).
 *
 * Two routes: read the deliverability figures and the global list, and **add** to that list. There
 * is deliberately no third — no lift route exists for a global entry, here or anywhere, because
 * removing one would overrule a person's stated wish with a button
 * (`OutreachSuppression::isRemovable()` refuses it, the policy refuses it, and the screen does not
 * offer it).
 *
 * The add goes through Outreach's own `SuppressionList` via one Action, so the entry is normalised,
 * hashed and masked exactly like every other — and `OutreachGuard` refuses the next send in every
 * tenant without this module teaching it anything.
 */
final class OutreachOversightController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly OutreachOversight $oversight,
    ) {}

    public function index(PlatformWindowRequest $request): Response
    {
        $window = $request->window();
        $page = $this->oversight->globalList();

        return Inertia::render('admin/outreach/Index', [
            'navigation' => $this->registry->navigation(),
            'deliverability' => $this->oversight->deliverability($window),
            'suppressions' => $this->oversight->rows($page),
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'total' => $page->total(),
                'per_page' => $page->perPage(),
            ],
            'summary' => $this->oversight->suppressionSummary(),
            'form' => $this->oversight->formOptions(),
            'filters' => $request->formState(),
        ]);
    }

    public function suppress(
        AddPlatformSuppressionRequest $request,
        AddPlatformSuppressionAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        /** @var array<string, mixed> $validated */
        $validated = $request->validated();

        $entry = $action->handle(
            type: $request->type(),
            value: (string) $validated['value'],
            reason: $request->reason(),
            operator: $operator,
            note: (string) $validated['note'],
        );

        Inertia::flash('toast', [
            'type' => $entry->wasRecentlyCreated ? 'success' : 'info',
            'message' => $entry->wasRecentlyCreated
                ? $entry->value_preview.' is now suppressed for every tenant.'
                : $entry->value_preview.' was already on the global list.',
        ]);

        return to_route('admin.outreach.index');
    }
}
