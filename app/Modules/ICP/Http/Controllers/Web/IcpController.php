<?php

declare(strict_types=1);

namespace App\Modules\ICP\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\ICP\Actions\CreateIcpAction;
use App\Modules\ICP\Actions\DeleteIcpAction;
use App\Modules\ICP\Actions\FindIcpAction;
use App\Modules\ICP\Actions\GetIcpsAction;
use App\Modules\ICP\Actions\SetActiveIcpAction;
use App\Modules\ICP\Actions\UpdateIcpAction;
use App\Modules\ICP\Data\IcpData;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Http\Requests\SaveIcpRequest;
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
    public function index(Request $request, GetIcpsAction $getIcps): Response
    {
        $team = $this->team($request);

        $this->authorize('view', $this->transientIcp($team));

        $icps = $getIcps->handle($team);

        return Inertia::render('icp/Index', [
            'icps' => $icps->map(fn (Icp $icp): array => $this->serializeIcp($icp))->all(),
            'criterionTypes' => $this->criterionTypes(),
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
