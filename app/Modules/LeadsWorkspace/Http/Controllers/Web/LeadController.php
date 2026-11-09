<?php

declare(strict_types=1);

namespace App\Modules\LeadsWorkspace\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Policies\LeadPolicy;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use App\Modules\LeadsWorkspace\Actions\CreateLeadAction;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\FindLeadAction;
use App\Modules\LeadsWorkspace\Actions\ListLeadsAction;
use App\Modules\LeadsWorkspace\Actions\ListSavedViewsAction;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\LeadsWorkspace\Data\LeadOrigin;
use App\Modules\LeadsWorkspace\Enums\ExportPreset;
use App\Modules\LeadsWorkspace\Http\Controllers\Web\Concerns\ResolvesWorkspace;
use App\Modules\LeadsWorkspace\Http\Requests\ListLeadsRequest;
use App\Modules\LeadsWorkspace\Http\Requests\StoreLeadRequest;
use App\Modules\LeadsWorkspace\Http\Resources\LeadActivityResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadExportResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadNoteResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTagResource;
use App\Modules\LeadsWorkspace\Http\Resources\LeadTaskResource;
use App\Modules\LeadsWorkspace\Http\Resources\PipelineStageResource;
use App\Modules\LeadsWorkspace\Http\Resources\SavedViewResource;
use App\Modules\LeadsWorkspace\Models\LeadExport;
use App\Modules\LeadsWorkspace\Models\LeadTag;
use App\Modules\LeadsWorkspace\Models\SavedView;
use App\Modules\LeadsWorkspace\Policies\SavedViewPolicy;
use App\Modules\LeadsWorkspace\Services\LeadCsvExporter;
use App\Modules\LeadsWorkspace\Services\LeadQueryBuilder;
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
        LeadQueryBuilder $leadQuery,
        LeadConfidence $confidence,
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
        $whyNow = PrelaunchFeature::WhyNow->activeFor($team);

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
            // --- Why now (PL1) ---
            'whyNow' => $whyNow,
            // The N in "Hidden: N low-confidence leads". Asked of the query builder so it counts
            // exactly what the toggle would reveal: these filters, that switch flipped. Skipped
            // entirely when the list is not hiding anything, so a team without the flag pays
            // nothing for a question that has no answer.
            'hiddenCount' => $whyNow ? $leadQuery->suppressedCount($team, $filters) : 0,
            'minConfidence' => $confidence->thresholdFor($team),
            'enrichment' => PrelaunchFeature::Enrichment->activeFor($team),
            // --- CSV export (PL6) ---
            // `exportOptions` rather than `export`, because `export` is a reserved word in a Vue
            // template expression and `export.enabled` is a syntax error rather than a read.
            'exportOptions' => $this->exportProps($team),
        ]);
    }

    /**
     * What the export dialog needs (PL6), or nothing at all when the flag is off.
     *
     * The column catalogue is sent as **the keys themselves**, deliberately untranslated: they are
     * the header row the member is about to open in a spreadsheet, and a prettified label would
     * mean picking "Company name" and finding `company_name` in the file.
     *
     * `recent` is the receipt — how many rows each file holds and how many companies were left out
     * because somebody there is on the do-not-contact list. It is also where a queued export lands
     * and where somebody comes back for a file whose notification they have deleted.
     *
     * @return array<string, mixed>
     */
    private function exportProps(Team $team): array
    {
        if (! PrelaunchFeature::Export->activeFor($team)) {
            return ['enabled' => false];
        }

        return [
            'enabled' => true,
            'syncMaxRows' => max(1, (int) config('leads-workspace.export.sync_max_rows', 500)),
            'presets' => array_map(static fn (ExportPreset $preset): array => [
                'value' => $preset->value,
                'label' => $preset->label(),
                'description' => $preset->description(),
                'columns' => $preset->columns(),
            ], ExportPreset::cases()),
            'columns' => LeadCsvExporter::ALL_COLUMNS,
            'recent' => LeadExportResource::collection(
                LeadExport::query()
                    ->where('team_id', $team->getKey())
                    ->latest('id')
                    ->limit(5)
                    ->get()
            )->resolve(request()),
        ];
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
            // --- Why now (PL1) ---
            'whyNow' => PrelaunchFeature::WhyNow->activeFor($team),
            'enrichment' => PrelaunchFeature::Enrichment->activeFor($team),
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
            // Everything that has happened to this company, newest first (S10). The single
            // `origin` above stays for the heading — *why this lead exists* — and this is the
            // history underneath it, which is what makes the page worth opening in the morning.
            'events' => $this->teamContext->run($team, fn (): array => array_map(
                static fn (LeadOrigin $event): array => $event->toArray(),
                $origin->eventsFor($model),
            )),
            'discoveryRun' => $model->discoveryRun === null ? null : [
                'id' => $model->discoveryRun->id,
                'source_label' => $model->discoveryRun->source->label(),
                'created_at' => $model->discoveryRun->created_at?->toIso8601String(),
            ],
            'can' => [
                'work' => $user->can('work', $model),
                // Flagging a reason lowers the lead's confidence for the whole team, so it is work
                // rather than reading — the same line the policy draws for everything else a
                // member does *to* a lead.
                'flagEvidence' => $user->can('flag', $this->transientEvidence($team)),
            ],
        ]);
    }

    /**
     * An unsaved evidence row belonging to the team, so the policy can be asked about the
     * *ability* without a row to ask it about — the same trick `transientLead()` uses above.
     */
    private function transientEvidence(Team $team): LeadEvidence
    {
        $evidence = (new LeadEvidence)->forceFill(['team_id' => $team->getKey()]);
        $evidence->setRelation('team', $team);

        return $evidence;
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
