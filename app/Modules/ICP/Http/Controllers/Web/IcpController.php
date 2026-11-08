<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\ComposeIcpAction;
use App\Modules\ICP\Actions\CreateIcpAction;
use App\Modules\ICP\Actions\DeleteIcpAction;
use App\Modules\ICP\Actions\FindIcpAction;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Actions\SetActiveIcpAction;
use App\Modules\ICP\Actions\UpdateIcpAction;
use App\Modules\ICP\Contracts\IcpCalibrationSource;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Http\Requests\ComposeIcpRequest;
use App\Modules\ICP\Http\Requests\SaveIcpRequest;
use App\Modules\ICP\Http\Resources\IcpDraftResource;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The ICP workspace (Inertia). Thin: authorise via the ICP policy, delegate to the Create/Update/
 * SetActive/Delete Actions, and render the list + weighted editor. All tenant reads go through the
 * Get/Find Actions so the controller never queries directly.
 */
final class IcpController extends Controller
{
    public function index(Request $request, GetIcpsAction $getIcps, IcpCalibrationSource $calibration): Response
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientIcp($team));

        $icps = $getIcps->handle($team);

        // How the active profile is actually grading the team's leads, so the screen can say which
        // criterion is sending leads to C/D rather than leaving the team to guess. Read-only and
        // AI-free; null when there is no active profile to calibrate.
        $active = $icps->firstWhere('is_active', true);

        return Inertia::render('icp/Index', [
            'icps' => $icps->map(fn (Icp $icp): array => $this->serializeIcp($icp))->all(),
            'criterionTypes' => $this->criterionTypes(),
            'calibration' => $active instanceof Icp ? $calibration->summarise($active)->toArray() : null,
            'can' => ['manage' => $request->user()?->can('create', $this->transientIcp($team)) ?? false],
        ]);
    }

    public function create(Request $request): Response
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientIcp($team));

        return Inertia::render('icp/Edit', [
            'icp' => null,
            'criterionTypes' => $this->criterionTypes(),
        ]);
    }

    /**
     * Draft a profile from a sentence about who the team sells to.
     *
     * **Nothing is saved.** The draft is flashed back to the editor, which fills its fields with it;
     * the member then changes what they like and presses save, which is `store` below. A flash
     * rather than a prop because that is exactly how long a draft should exist — until this screen
     * is left.
     *
     * Authorised as a `create`, because a draft is the first half of one.
     */
    public function compose(ComposeIcpRequest $request, ComposeIcpAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('create', $this->transientIcp($team));

        $draft = $action->handle($team, $user, $request->brief());

        Inertia::flash('draft', (new IcpDraftResource($draft))->resolve($request));
        // The brief survives on purpose: a composer that cleared the box would make its own reason
        // unactionable.
        Inertia::flash('brief', $request->brief());

        if (! $draft->viable) {
            Inertia::flash('toast', [
                'type' => 'info',
                // The model's own reason, not ours: it knows why it refused and the member is owed
                // it. Bounded to 300 characters by the prompt's schema.
                'message' => $draft->reason ?? __('That does not describe the customers you are looking for.'),
            ]);
        }

        return to_route('icp.create');
    }

    public function store(SaveIcpRequest $request, CreateIcpAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);

        $this->authorize('create', $this->transientIcp($team));

        $action->handle($team, IcpData::fromValidated($request->validated()), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your ideal customer profile is saved.')]);

        return to_route('icp.index');
    }

    public function edit(Request $request, int $icp, FindIcpAction $find): Response
    {
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('update', $model);

        return Inertia::render('icp/Edit', [
            'icp' => $this->serializeIcp($model),
            'criterionTypes' => $this->criterionTypes(),
        ]);
    }

    public function update(SaveIcpRequest $request, int $icp, FindIcpAction $find, UpdateIcpAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('update', $model);

        $action->handle($model, IcpData::fromValidated($request->validated()), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your changes are saved.')]);

        return to_route('icp.index');
    }

    public function activate(Request $request, int $icp, FindIcpAction $find, SetActiveIcpAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('update', $model);

        $action->handle($model, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __(':name is now your active profile.', ['name' => $model->name])]);

        return back();
    }

    public function destroy(Request $request, int $icp, FindIcpAction $find, DeleteIcpAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $team = $this->team($request);
        $model = $find->handle($team, $icp);

        $this->authorize('delete', $model);

        $action->handle($model, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile deleted.')]);

        return to_route('icp.index');
    }

    private function team(Request $request): Team
    {
        /** @var User $user */
        $user = $request->user();

        /** @var Team $team */
        $team = $user->currentTeam()->firstOrFail();

        return $team;
    }

    /**
     * A transient, team-scoped ICP for authorising list/create (no stored row needed).
     */
    private function transientIcp(Team $team): Icp
    {
        return (new Icp)->forceFill(['team_id' => $team->getKey()]);
    }

    /**
     * Shape an ICP (with its loaded criteria) into the plain array the Inertia pages consume.
     *
     * @return array<string, mixed>
     */
    private function serializeIcp(Icp $icp): array
    {
        return [
            'id' => $icp->id,
            'name' => $icp->name,
            'description' => $icp->description,
            'is_active' => $icp->is_active,
            'criteria' => $icp->criteria->map(static fn (IcpCriterion $c): array => [
                'id' => $c->id,
                'type' => $c->type->value,
                'type_label' => $c->type->label(),
                'value' => $c->value,
                'weight' => $c->weight,
                'is_exclusion' => $c->isExclusion(),
                'position' => $c->position,
            ])->all(),
            'updated_at' => $icp->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Criterion type metadata for the editor (label + hint + whether it's an exclusion).
     *
     * @return list<array<string, mixed>>
     */
    private function criterionTypes(): array
    {
        return array_map(static fn (CriterionType $type): array => [
            'value' => $type->value,
            'label' => $type->label(),
            'placeholder' => $type->placeholder(),
            'isExclusion' => $type->isExclusion(),
        ], CriterionType::cases());
    }
}
