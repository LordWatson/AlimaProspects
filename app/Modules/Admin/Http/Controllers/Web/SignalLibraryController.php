<?php

declare(strict_types=1);

namespace App\Modules\Admin\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Admin\Http\Requests\PromoteSubmissionRequest;
use App\Modules\Admin\Http\Requests\SavePlatformPresetRequest;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Admin\Services\Platform\SignalLibrary;
use App\Modules\Signals\Actions\PromoteSignalDefinitionAction;
use App\Modules\Signals\Actions\SavePlatformPresetAction;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Curating the shared signal library (S15 task 4, plan decision #3).
 *
 * **The panel owns no business rule here**, which is `docs/modules/admin.md`'s standing requirement
 * and the thing this controller is shaped by: every write calls a **Signals** Action
 * ({@see SavePlatformPresetAction}, {@see PromoteSignalDefinitionAction}), because what a preset is
 * — `team_id = null`, versioned, unique within the platform scope, read only through
 * `PlatformPresetReader` — is ADR 0018 §2's rule and not ours. A controller that wrote the row
 * itself would be a second implementation of it, and the second one would forget the version bump
 * that makes a change offerable to every clone.
 *
 * Behind `AdminAbility::ManageOperations` and the panel's own four controls, with
 * `AdminRouteContractTest` as the gate.
 *
 * **Reads here are audited as privacy events and writes as what they are.** The queue carries one
 * tenant's own type — their templates, their covering note — so an operator opening it leaves a
 * trail, which is `AdminAudit`'s rule for reading somebody else's records. The Signals Actions write
 * their own audit entries for the changes themselves, so nothing is recorded twice.
 */
final class SignalLibraryController extends Controller
{
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly SignalLibrary $library,
        private readonly AdminAudit $audit,
    ) {}

    public function index(Request $request): Response
    {
        $queue = $this->library->queue();

        // The queue rows are a tenant's own work. Counts and nothing else in the trail — never a
        // template, never a note, and never whose they are.
        $this->audit->record(AdminAudit::RESOURCE_VIEWED, null, [
            'resource' => 'signal-library',
            'presets' => count($this->library->presets()),
            'pending' => count($queue),
        ]);

        return Inertia::render('admin/signals/Library', [
            'navigation' => $this->registry->navigation(),
            'presets' => $this->library->presets(),
            'queue' => $queue,
            'options' => [
                'categories' => array_map(static fn (SignalCategory $category): array => [
                    'value' => $category->value,
                    'label' => $category->label(),
                ], SignalCategory::cases()),
                'icons' => array_map(static fn (SignalIcon $icon): array => [
                    'value' => $icon->value,
                    'label' => $icon->label(),
                ], SignalIcon::cases()),
                // Only the sources that actually run. A preset saved against a declared-but-unwired
                // source would be a type every team could clone and none could use (ADR 0023 §3).
                'sources' => array_map(static fn (SignalSourceKind $kind): array => [
                    'value' => $kind->value,
                    'label' => $kind->label(),
                ], SignalSourceKind::available()),
            ],
        ]);
    }

    public function store(SavePlatformPresetRequest $request, SavePlatformPresetAction $action): RedirectResponse
    {
        $preset = $action->create($this->operator($request), $request->definition(), $request->key());

        return $this->back(__('“:name” is in the library.', ['name' => $preset->name]));
    }

    public function update(
        SavePlatformPresetRequest $request,
        int $preset,
        SavePlatformPresetAction $action,
    ): RedirectResponse {
        $model = $this->preset($preset);

        $saved = $action->update($this->operator($request), $model, $request->definition());

        return $this->back(__('Saved. Version :version — teams holding a copy will be offered the change.', [
            'version' => $saved->version,
        ]));
    }

    /**
     * Stop offering a preset, or start again.
     *
     * Its own route rather than a field on the save form, because it is the one change that does
     * **not** bump the version: whether the library advertises a type is not a change to the type,
     * and folding it into the editor would make it one.
     */
    public function setActive(Request $request, int $preset, SavePlatformPresetAction $action): RedirectResponse
    {
        $model = $this->preset($preset);
        $active = ! $model->is_active;

        $action->setActive($this->operator($request), $model, $active);

        return $this->back($active
            ? __('Offered again.')
            : __('No longer offered. Teams already running a copy keep it.'));
    }

    public function destroy(Request $request, int $preset, SavePlatformPresetAction $action): RedirectResponse
    {
        $action->delete($this->operator($request), $this->preset($preset));

        return $this->back(__('Withdrawn from the library. Teams running a copy keep theirs.'));
    }

    /**
     * Accept a submission into the library.
     */
    public function promote(
        PromoteSubmissionRequest $request,
        int $submission,
        PromoteSignalDefinitionAction $action,
    ): RedirectResponse {
        $preset = $action->promote(
            $this->submission($submission),
            $this->operator($request),
            $request->note(),
            $request->key(),
        );

        return $this->back(__('“:name” is in the library, at version :version.', [
            'name' => $preset->name,
            'version' => $preset->version,
        ]));
    }

    /**
     * Turn a submission down, with a note the team reads.
     */
    public function reject(
        PromoteSubmissionRequest $request,
        int $submission,
        PromoteSignalDefinitionAction $action,
    ): RedirectResponse {
        $action->reject($this->submission($submission), $this->operator($request), $request->note());

        return $this->back(__('Answered. The team keeps their own type, untouched.'));
    }

    /**
     * A preset by id, refusing a tenant's row.
     *
     * The `team_id is null` is re-asserted here as well as in the Action, which is the same belt
     * and braces `PlatformPresetReader` applies to every read: an id arriving from a form must never
     * let an operator edit somebody's own type through the library screen.
     */
    private function preset(int $id): SignalDefinition
    {
        /** @var SignalDefinition $model */
        $model = SignalDefinition::query()
            // Only the tenancy scope: `withoutGlobalScopes()` would drop the soft-delete scope too
            // and let a withdrawn preset be edited and re-withdrawn (review).
            ->withoutGlobalScope('team')
            ->whereNull('team_id')
            ->findOrFail($id);

        return $model;
    }

    private function submission(int $id): SignalDefinitionSubmission
    {
        /** @var SignalDefinitionSubmission $model */
        $model = SignalDefinitionSubmission::query()
            // Cross-tenant on purpose: the queue is every tenant's offers. Only the team scope is
            // dropped — this table does not soft-delete, but naming the one scope we mean is the
            // habit that stopped the preset lookup resurrecting withdrawn rows.
            ->withoutGlobalScope('team')
            // Cross-tenant on the relation as well as on the row — the eager load is a second
            // query and re-applies `BelongsToTeam` otherwise.
            ->with(['definition' => fn ($query) => $query->withoutGlobalScope('team')->withTrashed()])
            ->findOrFail($id);

        return $model;
    }

    private function operator(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }

    private function back(string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('admin.signal-library.index');
    }
}
