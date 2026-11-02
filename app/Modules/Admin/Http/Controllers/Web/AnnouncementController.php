<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Actions\PublishAnnouncementAction;
use App\Modules\Admin\Enums\AnnouncementLevel;
use App\Modules\Admin\Http\Requests\PublishAnnouncementRequest;
use App\Modules\Admin\Models\Announcement;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Core\Enums\Market;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Writing and retiring announcements (Phase 19b).
 *
 * A bespoke screen rather than a registry resource, for the registry's stated reason: the title and
 * body are **per-locale maps** and the targeting is two optional lists, none of which a declarative
 * descriptor can render as a form field. The registry still lists announcements read-only, so
 * `audit-logs`-style browsing of what we have told people still works.
 */
final class AnnouncementController extends Controller
{
    public function __construct(private readonly ResourceRegistry $registry) {}

    public function index(): Response
    {
        return Inertia::render('admin/announcements/Index', [
            'navigation' => $this->registry->navigation(),
            'announcements' => Announcement::query()
                ->orderByDesc('starts_at')
                ->limit(50)
                ->get()
                ->map(static fn (Announcement $a): array => [
                    'id' => $a->id,
                    'level' => $a->level->value,
                    'variant' => $a->level->variant(),
                    'title' => $a->copyFor('en')['title'],
                    'plans' => $a->plans,
                    'markets' => $a->markets,
                    'starts_at' => $a->starts_at->toIso8601String(),
                    'ends_at' => $a->ends_at?->toIso8601String(),
                    'is_dismissible' => $a->is_dismissible,
                    'notify' => $a->notify,
                    'notified_at' => $a->notified_at?->toIso8601String(),
                ])
                ->values()
                ->all(),
            'options' => [
                'levels' => array_map(
                    static fn (AnnouncementLevel $level): array => [
                        'value' => $level->value,
                        'label' => $level->label(),
                        'dismissible' => $level->defaultDismissible(),
                    ],
                    AnnouncementLevel::cases(),
                ),
                'plans' => array_map(
                    static fn (PlanKey $plan): array => ['value' => $plan->value, 'label' => $plan->value],
                    PlanKey::cases(),
                ),
                'markets' => array_map(
                    static fn (Market $market): array => ['value' => $market->value, 'label' => $market->label()],
                    Market::cases(),
                ),
            ],
        ]);
    }

    public function store(PublishAnnouncementRequest $request, PublishAnnouncementAction $action): RedirectResponse
    {
        /** @var User $operator */
        $operator = $request->user();

        $announcement = $action->handle($request->payload(), $operator);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $announcement->notify
                ? 'Published, and every targeted tenant is being notified.'
                : 'Published. It will show as a banner to the tenants it targets.',
        ]);

        return to_route('admin.announcements.index');
    }

    public function retire(
        Request $request,
        Announcement $announcement,
        PublishAnnouncementAction $action,
    ): RedirectResponse {
        /** @var User $operator */
        $operator = $request->user();

        $action->retire($announcement, $operator);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Taken down. The record stays.']);

        return to_route('admin.announcements.index');
    }
}
