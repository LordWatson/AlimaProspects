<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Policies\LeadPolicy;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\FindLeadAction;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Actions\ListSavedViewsAction;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\ListLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Requests\StoreLeadRequest;
use App\Modules\LeadsWorkspace\Http\Resources\LeadActivityResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadNoteResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTagResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTaskResource;
use App\Modules\LeadsWorkspace\Http\Resources\PipelineStageResource;
use App\Modules\LeadsWorkspace\Http\Resources\SavedViewResource;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\LeadsWorkspace\Policies\SavedViewPolicy;
use App\Modules\LeadsWorkspace\Support\LeadFilterOptions;
use App\Modules\Scoring\Http\Resources\LeadScoreResource;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The leads workspace screens (Inertia). Thin: authorise via {@see LeadPolicy} /
 * {@see SavedViewPolicy}, delegate to one Action, render.
 *
 * The list is **server-driven**: filtering, sorting and paging all happen in the query, so the
 * screen never holds more than a page of leads and a shared URL reproduces what the sender saw.
 */
final class LeadController extends Controller
{
    use ResolvesWorkspace;

    public function __construct(private readonly TeamContext $teamContext) {}

    public function index(
        ListLeadsRequest $request,
        ListLeadsAction $list,
        ListSavedViewsAction $listViews,
        EnsureDefaultPipelineStagesAction $ensureStages,
    ): Response {
        $user = $this->user($request);
        $team = $this->team($request);

        $views = $listViews->handle($team, (int) $user->getKey());
        $openView = $this->openView($request, $views, $user);

        // Opening a saved view means its filters, not the ones in the URL — the view *is* the
        // request. Anything else and a stale query string would quietly override the view.
        $filters = $openView instanceof SavedView ? $openView->toFilters() : $request->filters();

        $stages = $ensureStages->handle($team);

        $leads = $list->handle($team, $filters, $request->perPage());

        return Inertia::render('leads/Index', [
            // The whole paginator, so the screen gets `links` and `meta` for its pager rather than
            // reinventing paging on the client.
            'leads' => LeadResource::collection($leads)->toResponse($request)->getData(true),
            'filters' => $filters->toFormState(),
            'options' => LeadFilterOptions::all(),
            'stages' => PipelineStageResource::collection($stages)->resolve($request),
            'tags' => LeadTagResource::collection($this->tags($team))->resolve($request),
            'members' => $this->members($team),
            'savedViews' => SavedViewResource::collection($views)->resolve($request),
            'activeViewId' => $openView?->getKey(),
            'can' => [
                'saveView' => $user->can('create', $this->transientView($team, $user)),
                'work' => $user->can('work', $this->transientLead($team)),
                'create' => $user->can('create', $this->transientLead($team)),
                'import' => $user->can('import', $this->transientLead($team)),
                'export' => $user->can('export', $this->transientLead($team)),
            ],
            'maxBulk' => (int) config('leads-workspace.max_bulk_leads', 500),
        ]);
    }

    /**
     * The add-a-lead form: one company, typed in by a member who met them.
     */
    public function create(Request $request, EnsureDefaultPipelineStagesAction $ensureStages): Response
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transientLead($team));

        return Inertia::render('leads/Create', [
            'stages' => PipelineStageResource::collection($ensureStages->handle($team))->resolve($request),
            // The tags the team already uses, so adding a lead reaches for an existing label rather
            // than inventing a third spelling of "warm".
            'tags' => LeadTagResource::collection($this->tags($team))->resolve($request),
            'countries' => $this->countries(),
            'can' => ['import' => $user->can('import', $this->transientLead($team))],
        ]);
    }

    /**
     * Save it, and go to the lead — the member added a company because they want to do something
     * with it, so the next screen is the one they can do it on.
     */
    public function store(StoreLeadRequest $request, CreateLeadAction $create): RedirectResponse
    {
        $user = $this->user($request);
        $team = $this->team($request);

        $this->authorize('create', $this->transientLead($team));

        $result = $create->handle($team, $user, $request->lead());

        Inertia::flash('toast', [
            // A merge is not a failure, but it is not what the member asked for either: they are
            // about to land on a lead with history they didn't write, so the message says why.
            'type' => $result->merged ? 'info' : 'success',
            'message' => $result->merged
                ? __('You already had :company — Alima added what you typed to that lead.', [
                    'company' => $result->lead->company_name,
                ])
                : __(':company is in your leads. Alima is filling in the rest.', [
                    'company' => $result->lead->company_name,
                ]),
        ]);

        return to_route('leads.show', ['lead' => $result->lead->getKey()]);
    }

    public function show(
        Request $request,
        int $lead,
        FindLeadAction $find,
        EnsureDefaultPipelineStagesAction $ensureStages,
        LeadOriginProvider $origin,
    ): Response {
        $user = $this->user($request);
        $team = $this->team($request);

        $model = $find->handle($team, $lead, (int) $user->getKey());

        $this->authorize('view', $model);

        $score = LeadScore::activeFor($model);

        return Inertia::render('leads/Show', [
            'lead' => LeadResource::make($model)->resolve($request),
            // The badge and the summary panel; the full breakdown stays on the score screen.
            'score' => $score === null ? null : LeadScoreResource::make($score)->resolve($request),
            'activities' => LeadActivityResource::collection($model->activities)->resolve($request),
            'notes' => LeadNoteResource::collection($model->notes)->resolve($request),
            'tasks' => LeadTaskResource::collection($model->tasks)->resolve($request),
            'stages' => PipelineStageResource::collection($ensureStages->handle($team))->resolve($request),
            'members' => $this->members($team),
            // Where the lead came from when "a provider found it" isn't the whole story — a
            // converted signal, say. Resolved through a contract, so the workspace renders the
            // panel without depending on whichever module created the lead.
            'origin' => $this->teamContext->run($team, fn (): ?array => $origin->originFor($model)?->toArray()),
            'discoveryRun' => $model->discoveryRun === null ? null : [
                'id' => $model->discoveryRun->id,
                'source_label' => $model->discoveryRun->source->label(),
                'created_at' => $model->discoveryRun->created_at?->toIso8601String(),
            ],
            'can' => [
                'work' => $user->can('work', $model),
            ],
        ]);
    }

    /**
     * Resolve the saved view the request asked to open. A view the member may not see — or one that
     * has since been deleted — yields null rather than an error: a stale bookmark should open the
     * leads list, not a 403.
     *
     * @param  Collection<int, SavedView>  $views
     */
    private function openView(ListLeadsRequest $request, Collection $views, User $user): ?SavedView
    {
        $id = $request->savedViewId();

        if ($id === null) {
            return null;
        }

        $view = $views->firstWhere('id', $id);

        return $view instanceof SavedView && $user->can('view', $view) ? $view : null;
    }

    /**
     * The tags the team has actually used, with how many leads carry each — the filter bar offers a
     * tag that exists rather than a free-text box that matches nothing.
     *
     * @return Collection<int, LeadTag>
     */
    private function tags(Team $team): Collection
    {
        return $this->teamContext->run($team, fn (): Collection => LeadTag::query()
            ->where('team_id', $team->getKey())
            ->withCount('leads')
            ->orderBy('name')
            ->get());
    }

    /**
     * The team's members, for the assignee filter and the assign picker.
     *
     * @return list<array{id: int, name: string}>
     */
    private function members(Team $team): array
    {
        /** @var list<array{id: int, name: string}> $members */
        $members = $team->users()
            ->orderBy('name')
            ->get(['users.id', 'users.name'])
            ->map(static fn (User $member): array => [
                'id' => (int) $member->getKey(),
                'name' => (string) $member->name,
            ])
            ->all();

        return $members;
    }

    /**
     * The countries the add-a-lead form offers, from config so the list is one thing rather than a
     * hand-written `<option>` list. Names are translation keys; the client passes them through
     * `t()`, which is why they aren't translated here.
     *
     * @return list<array{code: string, name: string}>
     */
    private function countries(): array
    {
        /** @var array<string, string> $countries */
        $countries = config('leads-workspace.countries', []);

        return array_map(
            static fn (string $name, string $code): array => ['code' => $code, 'name' => $name],
            array_values($countries),
            array_keys($countries),
        );
    }

    /**
     * A transient, team-scoped view for authorising "may this member save one at all", which needs
     * no stored row.
     */
    private function transientView(Team $team, User $user): SavedView
    {
        $view = new SavedView;
        $view->forceFill(['team_id' => $team->getKey(), 'user_id' => $user->getKey()]);
        $view->setRelation('team', $team);

        return $view;
    }
}
