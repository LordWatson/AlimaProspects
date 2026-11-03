<?php

declare(strict_types=1);

namespace App\Modules\Signals\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Actions\DeleteSignalPlaybookAction;
use App\Modules\Signals\Actions\PreviewSignalPlaybookAction;
use App\Modules\Signals\Actions\SaveSignalPlaybookAction;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookAssignStrategy;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Enums\PlaybookWatchlistCondition;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Http\Controllers\Concerns\ResolvesSignals;
use App\Modules\Signals\Http\Requests\PreviewSignalPlaybookRequest;
use App\Modules\Signals\Http\Requests\SaveSignalPlaybookRequest;
use App\Modules\Signals\Http\Resources\SignalPlaybookResource;
use App\Modules\Signals\Http\Resources\SignalPlaybookRunResource;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Policies\SignalPlaybookPolicy;
use App\Modules\Signals\Services\PlaybookStepRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The rule builder and the run log (Inertia, S14). Thin: authorise via
 * {@see SignalPlaybookPolicy}, validate in a FormRequest, call one Action, render or redirect.
 *
 * **Two screens, and they are deliberately separate.** The builder is a form somebody visits to
 * change something; the log is a list they visit to find out what happened, and it paginates. Put
 * together, the log would reload the whole builder on every page of history, and the builder would
 * flash a hundred rows of JSON at somebody who came to rename a rule.
 *
 * The one thing this screen has to say out loud, because nothing else will: **a rule drafts, it
 * never sends** (docs/compliance.md §3.12). The copy says so beside the drafting step and the log
 * shows every refusal, because a member who believed otherwise would be making a legal decision on
 * the strength of a guess about our implementation.
 *
 * The drafting channels come from {@see SignalOutreachDrafter} rather than from an Outreach enum,
 * so this controller — like the rest of the module — knows nothing about Outreach's vocabulary. An
 * empty list is a real answer, and the builder renders the drafting step as unavailable rather than
 * offering a control that would save a rule that could never run.
 */
final class SignalPlaybookController extends Controller
{
    use ResolvesSignals;

    public function index(Request $request, PlaybookStepRegistry $handlers, SignalOutreachDrafter $drafter): Response
    {
        $team = $this->team($request);
        $user = $this->user($request);

        $this->authorize('viewAny', $this->transientPlaybook($team));

        $context = app(TeamContext::class);

        $playbooks = $context->run($team, fn (): array => SignalPlaybook::query()
            ->withCount('runs')
            ->orderBy('priority')
            ->orderBy('id')
            ->get()
            ->all());

        return Inertia::render('signals/Playbooks', [
            'playbooks' => SignalPlaybookResource::collection($playbooks)->resolve($request),
            // The handful of most recent rows, so somebody who has just switched a rehearsal on
            // sees it working without going to the log. The log itself is its own screen.
            'recent' => SignalPlaybookRunResource::collection(
                $context->run($team, fn (): array => SignalPlaybookRun::query()
                    ->with(['signal', 'lead', 'actor'])
                    ->orderByDesc('id')
                    ->limit(5)
                    ->get()
                    ->all()),
            )->resolve($request),
            'options' => $this->options($team, $handlers, $drafter),
            'can' => ['manage' => $user->can('create', $this->transientPlaybook($team))],
        ]);
    }

    /**
     * The run log — what fired, and what it refused to do.
     */
    public function activity(Request $request): Response
    {
        $team = $this->team($request);

        $this->authorize('viewAny', $this->transientPlaybook($team));

        // "Only the ones that refused something" is the first filter anybody uses, and it has its
        // own index. A boolean rather than a status list: the question is "is there anything here
        // for me to read", and a per-status filter would be our vocabulary rather than theirs.
        $attention = $request->boolean('attention');

        $runs = app(TeamContext::class)->run($team, fn () => SignalPlaybookRun::query()
            ->with(['signal', 'lead', 'actor'])
            ->when($attention, static fn ($query) => $query->where(
                static fn ($inner) => $inner->where('refused_count', '>', 0)->orWhere('failed_count', '>', 0),
            ))
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString());

        return Inertia::render('signals/PlaybookActivity', [
            'runs' => SignalPlaybookRunResource::collection($runs)->response($request)->getData(true),
            'filters' => ['attention' => $attention],
        ]);
    }

    public function store(SaveSignalPlaybookRequest $request, SaveSignalPlaybookAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('create', $this->transientPlaybook($team));

        $playbook = $action->handle($team, $this->user($request), $request->playbook());

        Inertia::flash('toast', [
            'type' => 'success',
            // Says which mode it is in, because that is the difference between a rule that watches
            // and a rule that acts, and nobody should have to go back and check.
            'message' => $playbook->mode->performs()
                ? __('“:name” is live. Everything it does is in the log.', ['name' => $playbook->name])
                : __('“:name” is rehearsing. It will record what it would have done, and change nothing.', ['name' => $playbook->name]),
        ]);

        return to_route('signal-playbooks.index');
    }

    public function update(
        SaveSignalPlaybookRequest $request,
        int $playbook,
        SaveSignalPlaybookAction $action,
    ): RedirectResponse {
        $model = $this->playbook($request, $playbook);

        $this->authorize('update', $model);

        $saved = $action->handle($this->team($request), $this->user($request), $request->playbook(), $model);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $saved->mode->performs()
                ? __('Saved. “:name” is live.', ['name' => $saved->name])
                : __('Saved. “:name” is rehearsing.', ['name' => $saved->name]),
        ]);

        return to_route('signal-playbooks.index');
    }

    public function destroy(Request $request, int $playbook, DeleteSignalPlaybookAction $action): RedirectResponse
    {
        $model = $this->playbook($request, $playbook);

        $this->authorize('delete', $model);

        $action->handle($model, $this->user($request));

        Inertia::flash('toast', [
            'type' => 'success',
            // Says what happened to the history, because it is not what somebody would assume.
            'message' => __('Rule removed. What it already did stays in the log.'),
        ]);

        return to_route('signal-playbooks.index');
    }

    /**
     * "What would this have done to last week's signals?"
     *
     * No throttle, unlike the definition builder's preview: this one executes nothing, calls no
     * provider and spends no credit — it reads rows the team has already paid for. The ceiling on
     * it is the window, not a budget.
     */
    public function preview(PreviewSignalPlaybookRequest $request, PreviewSignalPlaybookAction $action): RedirectResponse
    {
        $team = $this->team($request);

        $this->authorize('preview', $this->transientPlaybook($team));

        $preview = $action->handle($team, $request->conditions(), $request->kinds());

        // Flashed rather than returned as JSON, because the builder is an Inertia form and the
        // preview belongs beside the fields it describes — the same arrangement the definition
        // builder's preview uses.
        Inertia::flash('playbookPreview', $preview->toPayload());

        return back();
    }

    /**
     * Everything the builder needs to offer, assembled once.
     *
     * @return array<string, mixed>
     */
    private function options(
        Team $team,
        PlaybookStepRegistry $handlers,
        SignalOutreachDrafter $drafter,
    ): array {
        $context = app(TeamContext::class);

        return [
            // **From the registry, not from the enum.** A kind that is declared and not yet wired
            // up must never reach the screen — the same separation `SignalSourceKind::isAvailable()`
            // draws one level up (ADR 0023 §3).
            'action_kinds' => array_map(static fn (PlaybookActionKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
                'description' => $kind->description(),
                'needs_lead' => $kind->needsLead(),
                'spends' => $kind->spends(),
                'repeatable' => $kind->isRepeatable(),
            ], $handlers->registered()),
            'modes' => array_map(static fn (PlaybookMode $mode): array => [
                'value' => $mode->value,
                'label' => $mode->label(),
                'description' => $mode->description(),
            ], PlaybookMode::cases()),
            'assign_strategies' => array_map(static fn (PlaybookAssignStrategy $strategy): array => [
                'value' => $strategy->value,
                'label' => $strategy->label(),
            ], PlaybookAssignStrategy::cases()),
            'notify_audiences' => array_map(static fn (PlaybookNotifyAudience $audience): array => [
                'value' => $audience->value,
                'label' => $audience->label(),
            ], PlaybookNotifyAudience::cases()),
            'watchlist_conditions' => array_map(static fn (PlaybookWatchlistCondition $condition): array => [
                'value' => $condition->value,
                'label' => $condition->label(),
            ], PlaybookWatchlistCondition::cases()),
            'sources' => array_map(static fn (SignalSourceKind $kind): array => [
                'value' => $kind->value,
                'label' => $kind->label(),
            ], SignalSourceKind::available()),
            // The team's own types, their own lists, their own board and their own people — every
            // condition and every step is about something they already have.
            'definitions' => $context->run($team, fn (): array => SignalDefinition::query()
                ->where('team_id', $team->getKey())
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (SignalDefinition $definition): array => [
                    'id' => (int) $definition->id,
                    'name' => $definition->name,
                ])
                ->all()),
            'watchlists' => $context->run($team, fn (): array => SignalWatchlist::query()
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(static fn (SignalWatchlist $watchlist): array => [
                    'id' => (int) $watchlist->id,
                    'name' => $watchlist->name,
                ])
                ->all()),
            'stages' => $context->run($team, fn (): array => PipelineStage::query()
                ->orderBy('position')
                ->get(['id', 'name'])
                ->map(static fn (PipelineStage $stage): array => [
                    'id' => (int) $stage->id,
                    'name' => $stage->name,
                ])
                ->all()),
            'members' => $team->users()
                ->orderBy('users.name')
                ->get(['users.id', 'users.name'])
                ->map(static fn (User $member): array => [
                    'id' => (int) $member->id,
                    'name' => $member->name,
                ])
                ->all(),
            // The jurisdiction codes Alima can actually resolve. Offering a code the extractor
            // never writes would be a condition that silently matches nothing.
            'jurisdictions' => array_map(
                static fn (string $code): array => ['value' => $code, 'label' => $code],
                array_keys((array) config('signals.jurisdictions', [])),
            ),
            // Empty lists are a real answer: the builder renders the drafting step as unavailable
            // rather than offering a control that would save a rule that could never run.
            'channels' => $drafter->channels(),
            'tones' => $drafter->tones(),
            'limits' => [
                'max_playbooks' => max(1, (int) config('signals.playbooks.max_per_team', 10)),
                'max_actions' => max(1, (int) config('signals.playbooks.max_actions', 8)),
                'max_conversions_per_run' => max(0, (int) config('signals.playbooks.max_conversions_per_run', 25)),
                'max_drafts_per_run' => max(0, (int) config('signals.playbooks.max_drafts_per_run', 10)),
                'preview_days' => max(1, (int) config('signals.playbooks.preview_days', 7)),
                'suggested_dry_run_days' => max(1, (int) config('signals.playbooks.suggested_dry_run_days', 7)),
            ],
        ];
    }
}
