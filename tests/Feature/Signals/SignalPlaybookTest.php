<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\EnsureDefaultPipelineStagesAction;
use App\Modules\LeadsWorkspace\Actions\SyncLeadTagsAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\LeadsWorkspace\Models\LeadTask;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Signals\Actions\RunSignalPlaybooksAction;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\PlaybookDraftOutcome;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookActionStatus;
use App\Modules\Signals\Enums\PlaybookAssignStrategy;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\PlaybookNotifyAudience;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Services\NoOutreachDrafting;
use App\Modules\Signals\Services\PlaybookStepRegistry;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

/**
 * Playbooks: a signal arriving becomes an event the team can automate against (S14).
 *
 * Four things are being pinned down here, and they are the four brakes the phase plan says to keep.
 * **A rule fires once per signal, ever.** **A rehearsal changes nothing.** **The per-job spend
 * ceilings hold.** And **a draft is refused where the prospect may not be approached, with the
 * refusal recorded.**
 *
 * Alongside them, the thing the architecture rests on: every step calls the **owning module's
 * Action**, which is asserted by looking for that Action's own side effects — the lead's timeline
 * entry, its audit entry, the assignment row. A handler that wrote the row itself would pass an
 * assertion about the row and fail these.
 */
beforeEach(function (): void {
    // Several notification types default to mail, and this phase is not a test of the mailer.
    Mail::fake();

    $this->owner = createUserWithTeam();
    $this->team = $this->owner->currentTeam()->firstOrFail();

    app(EnsureDefaultPipelineStagesAction::class)->handle($this->team);
});

/** A signal belonging to the team under test. */
function playbookSignal(Team $team, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'heat' => 80,
    ], $attributes)));
}

/** Run the team's playbooks over these signals, as the job does. */
function runPlaybooks(Team $team, array $signals): array
{
    return app(RunSignalPlaybooksAction::class)->handle(
        $team,
        array_map(static fn (Signal $signal): int => (int) $signal->getKey(), $signals),
    );
}

function playbookRuns(Team $team): Collection
{
    return app(TeamContext::class)->run($team, fn () => SignalPlaybookRun::query()->orderBy('id')->get());
}

/**
 * A drafting seam that records what it was asked and answers however the test wants.
 *
 * Bound over `SignalOutreachDrafter`, which is exactly the seam Outreach binds in production — so
 * these tests exercise the contract rather than Outreach's implementation of it, and the drafting
 * step is tested without an AI call.
 */
function fakeDrafter(PlaybookDraftOutcome $outcome): object
{
    $fake = new class($outcome) implements SignalOutreachDrafter
    {
        /** @var list<array{channel: string, tone: string, lead_id: int}> */
        public array $asked = [];

        public function __construct(private readonly PlaybookDraftOutcome $outcome) {}

        public function channels(): array
        {
            return [['value' => 'linkedin', 'label' => 'LinkedIn message', 'description' => 'Copy it yourself.']];
        }

        public function tones(): array
        {
            return [['value' => 'warm', 'label' => 'Warm']];
        }

        public function draftForReview(
            Team $team,
            Lead $lead,
            string $channel,
            string $tone,
            User $actor,
        ): PlaybookDraftOutcome {
            $this->asked[] = ['channel' => $channel, 'tone' => $tone, 'lead_id' => (int) $lead->getKey()];

            return $this->outcome;
        }
    };

    app()->instance(SignalOutreachDrafter::class, $fake);

    return $fake;
}

describe('firing once', function (): void {
    it('fires a playbook once per signal across two runs', function (): void {
        $playbook = SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);
        $signal = playbookSignal($this->team);

        runPlaybooks($this->team, [$signal]);
        $second = runPlaybooks($this->team, [$signal]);

        // The second pass considered it and did nothing: the claim row is what holds the guarantee,
        // not the job's uniqueness, so this holds across retries and across both events too.
        expect($second['fired'])->toBe(0);
        expect(playbookRuns($this->team))->toHaveCount(1);
        expect(playbookRuns($this->team)->first()->signal_playbook_id)->toBe($playbook->getKey());
    });

    it('lets two playbooks each handle the same signal', function (): void {
        SignalPlaybook::factory()->count(2)->create(['team_id' => $this->team->getKey()]);
        $signal = playbookSignal($this->team);

        $result = runPlaybooks($this->team, [$signal]);

        expect($result['fired'])->toBe(2);
        expect(playbookRuns($this->team))->toHaveCount(2);
    });

    it('does not evaluate a paused playbook', function (): void {
        SignalPlaybook::factory()->paused()->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        expect(playbookRuns($this->team))->toHaveCount(0);
    });

    it('stops after a matching rule that says so', function (): void {
        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'priority' => 10,
            'stop_after_first_match' => true,
        ]);
        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey(), 'priority' => 20]);

        expect(runPlaybooks($this->team, [playbookSignal($this->team)])['fired'])->toBe(1);
    });
});

describe('the chain', function (): void {
    it('converts, assigns, moves and drafts — each through the owning module Action', function (): void {
        $drafter = fakeDrafter(PlaybookDraftOutcome::drafted(42));

        $stage = app(TeamContext::class)->run($this->team, fn (): PipelineStage => PipelineStage::query()
            ->where('is_default', false)
            ->orderBy('position')
            ->firstOrFail());

        SignalPlaybook::factory()
            ->fullChain((int) $stage->getKey(), [(int) $this->owner->getKey()])
            ->create(['team_id' => $this->team->getKey()]);

        $signal = playbookSignal($this->team);

        runPlaybooks($this->team, [$signal]);

        $lead = app(TeamContext::class)->run($this->team, fn (): ?Lead => Lead::query()->first());

        expect($lead)->not->toBeNull();
        expect($lead->pipeline_stage_id)->toBe($stage->getKey());

        // The **Action's** own side effects, not just the row: an assignment row with no timeline
        // entry beside it would mean a handler had written the row itself.
        expect(LeadAssignment::query()->where('lead_id', $lead->getKey())->count())->toBe(1);
        expect(LeadActivity::query()
            ->where('lead_id', $lead->getKey())
            ->where('type', LeadActivityType::Assigned)
            ->exists())->toBeTrue();
        expect(LeadActivity::query()
            ->where('lead_id', $lead->getKey())
            ->where('type', LeadActivityType::StageChanged)
            ->exists())->toBeTrue();

        // And the drafting step reached the contract with the lead the conversion produced.
        expect($drafter->asked)->toHaveCount(1);
        expect($drafter->asked[0]['lead_id'])->toBe((int) $lead->getKey());

        $run = playbookRuns($this->team)->first();

        expect($run->applied_count)->toBe(4);
        expect($run->refused_count)->toBe(0);
        expect($run->lead_id)->toBe($lead->getKey());
    });

    it('skips the lead steps and says why when nothing converted', function (): void {
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::AddTag, ['tags' => ['Hot']])
            ->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $run = playbookRuns($this->team)->first();
        $outcome = $run->outcomes()[0];

        expect($outcome->status)->toBe(PlaybookActionStatus::Skipped);
        expect($outcome->reason)->toBe(PlaybookOutcomeReason::NoLead);
    });

    it('attaches to the lead the event is about rather than making a new one', function (): void {
        $existing = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
            'team_id' => $this->team->getKey(),
            'company_name' => 'Acme DMCC',
        ]));

        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::AttachToMatchedLead)
            ->create(['team_id' => $this->team->getKey()]);

        $signal = playbookSignal($this->team, ['matched_lead_id' => $existing->getKey()]);

        runPlaybooks($this->team, [$signal]);

        expect(Lead::query()->count())->toBe(1);
        expect($signal->refresh()->lead_id)->toBe($existing->getKey());
    });

    it('declines to attach when the event is about nobody the team has', function (): void {
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::AttachToMatchedLead)
            ->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        // Not "make a lead instead": that would turn "keep me posted about my accounts" into "fill
        // my pipeline with strangers".
        expect(Lead::query()->count())->toBe(0);
        expect(playbookRuns($this->team)->first()->outcomes()[0]->reason)->toBe(PlaybookOutcomeReason::NoMatch);
    });

    it('creates a task through the workspace Action, due in days from now', function (): void {
        SignalPlaybook::factory()
            ->create([
                'team_id' => $this->team->getKey(),
                'actions' => [
                    ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                    [
                        'kind' => PlaybookActionKind::CreateTask->value,
                        'params' => ['title' => 'Call them', 'due_in_days' => 2],
                    ],
                ],
            ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $task = LeadTask::query()->firstOrFail();

        expect($task->title)->toBe('Call them');
        expect($task->due_at->isSameDay(now()->addDays(2)))->toBeTrue();
        // A task a rule created is the one most likely to sit unread, so the reminder is set.
        expect($task->remind_at)->not->toBeNull();
    });
});

describe('the rehearsal', function (): void {
    it('writes a run row and changes nothing else', function (): void {
        $drafter = fakeDrafter(PlaybookDraftOutcome::drafted(1));

        $stage = app(TeamContext::class)->run($this->team, fn (): PipelineStage => PipelineStage::query()
            ->orderBy('position')
            ->firstOrFail());

        SignalPlaybook::factory()
            ->rehearsing()
            ->fullChain((int) $stage->getKey(), [(int) $this->owner->getKey()])
            ->create(['team_id' => $this->team->getKey()]);

        $signal = playbookSignal($this->team);

        runPlaybooks($this->team, [$signal]);

        $run = playbookRuns($this->team)->first();

        expect($run)->not->toBeNull();
        expect($run->mode)->toBe(PlaybookMode::DryRun);
        expect($run->applied_count)->toBe(0);
        expect($run->outcomes()[0]->status)->toBe(PlaybookActionStatus::Simulated);

        // Nothing happened anywhere else — including, deliberately, no model call.
        expect(Lead::query()->count())->toBe(0);
        expect(LeadAssignment::query()->count())->toBe(0);
        expect(LeadTask::query()->count())->toBe(0);
        expect(Notification::query()->count())->toBe(0);
        expect($drafter->asked)->toBe([]);
        expect($signal->refresh()->status)->toBe(SignalStatus::New);
    });

    it('records the mode it ran under, not the one the rule now has', function (): void {
        $playbook = SignalPlaybook::factory()->rehearsing()->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $playbook->forceFill(['mode' => PlaybookMode::Live])->save();

        // Relabelling the rehearsal would destroy the evidence somebody switched the rule on with.
        expect(playbookRuns($this->team)->first()->mode)->toBe(PlaybookMode::DryRun);
    });
});

describe('the brakes', function (): void {
    // **A cap defers; it does not refuse.** The first cut of this phase claimed the signal and
    // recorded a `*_cap_reached` refusal on it — and the claim is permanent, so with a 200-signal
    // batch and a 25-conversion ceiling, 175 signals were refused for ever by design. The signal is
    // now left unclaimed and handed back for a later pass.
    it('defers a conversion past the per-job ceiling rather than refusing it', function (): void {
        config()->set('signals.playbooks.max_conversions_per_run', 1);

        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create(['team_id' => $this->team->getKey()]);

        // Created in the order the Action walks them (by id), so the second is the deferred one.
        $first = playbookSignal($this->team);
        $second = playbookSignal($this->team);

        $result = runPlaybooks($this->team, [$first, $second]);

        expect(Lead::query()->count())->toBe(1);
        // One run row, not two: the deferred signal was never claimed, so the next pass can have it.
        expect(playbookRuns($this->team))->toHaveCount(1);
        expect($result['deferred'])->toBe([(int) $second->getKey()]);
    });

    it('defers a draft past the per-job ceiling', function (): void {
        config()->set('signals.playbooks.max_drafts_per_run', 1);
        $drafter = fakeDrafter(PlaybookDraftOutcome::drafted(1));

        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::DraftOutreach->value,
                    'params' => ['channel' => 'linkedin', 'tone' => 'warm'],
                ],
            ],
        ]);

        $first = playbookSignal($this->team);
        $second = playbookSignal($this->team);

        $result = runPlaybooks($this->team, [$first, $second]);

        expect($drafter->asked)->toHaveCount(1);
        expect($result['deferred'])->toBe([(int) $second->getKey()]);
        expect(playbookRuns($this->team))->toHaveCount(1);
    });

    it('counts a failed draft against the ceiling, so an outage is bounded', function (): void {
        // Counting successes left a hanging provider unbounded: every signal in the batch would
        // wait out a timeout until the job's own timeout killed the worker, which is the one thing
        // that loses a claimed run's work.
        config()->set('signals.playbooks.max_drafts_per_run', 1);
        $drafter = fakeDrafter(PlaybookDraftOutcome::unavailable());

        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::DraftOutreach->value,
                    'params' => ['channel' => 'linkedin', 'tone' => 'warm'],
                ],
            ],
        ]);

        $first = playbookSignal($this->team);
        $second = playbookSignal($this->team);

        $result = runPlaybooks($this->team, [$first, $second]);

        expect($drafter->asked)->toHaveCount(1);
        expect($result['deferred'])->toBe([(int) $second->getKey()]);
    });

    it('hands back the signals past the batch ceiling instead of dropping them', function (): void {
        config()->set('signals.playbooks.max_signals_per_run', 1);

        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        $first = playbookSignal($this->team);
        $second = playbookSignal($this->team);

        $result = runPlaybooks($this->team, [$first, $second]);

        expect($result['deferred'])->toBe([(int) $second->getKey()]);
        expect(playbookRuns($this->team))->toHaveCount(1);
    });

    it('reports nothing deferred when the team has no rules', function (): void {
        config()->set('signals.playbooks.max_signals_per_run', 1);

        $result = runPlaybooks($this->team, [playbookSignal($this->team), playbookSignal($this->team)]);

        expect($result['deferred'])->toBe([]);
    });

    it('refuses a step this build has no handler for, rather than leaving it out', function (): void {
        // A step whose *kind* is unknown is dropped (a rolled-back deploy must not fail every
        // signal); a known kind with no handler is in the rule and visible on the screen, so
        // leaving it out of the log would make the counts lie about what the rule did.
        app()->instance(PlaybookStepRegistry::class, new PlaybookStepRegistry);

        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::Notify, ['audience' => PlaybookNotifyAudience::Team->value])
            ->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $run = playbookRuns($this->team)->first();

        expect($run->refused_count)->toBe(1);
        expect($run->outcomes()[0]->reason)->toBe(PlaybookOutcomeReason::StepUnavailable);
    });

    it('leaves a dismissed signal alone entirely', function (): void {
        // A member's "no" outranks a rule. A rule that converted a dismissed signal and drafted an
        // approach for it would be the product overriding a human decision.
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create(['team_id' => $this->team->getKey()]);

        $dismissed = playbookSignal($this->team, [
            'status' => SignalStatus::Dismissed,
            'dismiss_reason' => SignalDismissReason::NotRelevant,
        ]);

        $result = runPlaybooks($this->team, [$dismissed]);

        expect($result['considered'])->toBe(0);
        expect(playbookRuns($this->team))->toHaveCount(0);
        expect(Lead::query()->count())->toBe(0);
    });

    it('refuses to add a label to a lead that is already at the workspace ceiling', function (): void {
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::AddTag, ['tags' => ['Hot']])
            ->create(['team_id' => $this->team->getKey()]);

        $lead = app(TeamContext::class)->run($this->team, fn (): Lead => Lead::factory()->create([
            'team_id' => $this->team->getKey(),
            'company_name' => 'Acme DMCC',
        ]));

        app(TeamContext::class)->run($this->team, function () use ($lead): void {
            app(SyncLeadTagsAction::class)->handle(
                $this->team,
                $lead,
                array_map(static fn (int $i): string => 'Label '.$i, range(1, SyncLeadTagsAction::MAX_PER_LEAD)),
                $this->owner,
            );
        });

        // A signal that is already this lead, so the chain acts on it with no conversion step.
        $signal = playbookSignal($this->team, [
            'status' => SignalStatus::Converted,
            'lead_id' => $lead->getKey(),
            'actioned_by' => $this->owner->getKey(),
            'actioned_at' => now(),
        ]);

        runPlaybooks($this->team, [$signal]);

        expect(playbookRuns($this->team)->first()->outcomes()[0]->reason)
            ->toBe(PlaybookOutcomeReason::TagLimitReached);
    });
});

describe('the audit trail', function (): void {
    it('stamps a finished run, so an interrupted one can be told apart', function (): void {
        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $run = playbookRuns($this->team)->first();

        expect($run->isComplete())->toBeTrue();
        expect($run->needsAttention())->toBeFalse();
    });

    it('treats an unstamped run as needing attention', function (): void {
        // The claim has to be written before any step executes or a race creates a second task, so
        // a worker that dies in between cannot be designed away — only made visible.
        $run = app(TeamContext::class)->run($this->team, fn () => SignalPlaybookRun::factory()->create([
            'team_id' => $this->team->getKey(),
            'signal_id' => playbookSignal($this->team)->getKey(),
            'results' => [],
            'applied_count' => 0,
            'completed_at' => null,
        ]));

        expect($run->isComplete())->toBeFalse();
        expect($run->needsAttention())->toBeTrue();
    });
});

describe('drafting', function (): void {
    it('refuses the draft when the prospect may not be approached, and records why', function (): void {
        // The real guard's answer for a UAE-sourced lead: a prior-consent restriction nothing
        // published can satisfy (docs/compliance.md §3.12).
        $drafter = fakeDrafter(PlaybookDraftOutcome::blocked(
            reasonKey: 'restricted',
            reasonLabel: 'Get consent first',
            reasonGuidance: 'Approach via LinkedIn or a referral — not cold email.',
            isCompliance: true,
        ));

        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::DraftOutreach->value,
                    'params' => ['channel' => 'linkedin', 'tone' => 'warm'],
                ],
            ],
        ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $run = playbookRuns($this->team)->first();
        $draftStep = $run->outcomes()[1];

        expect($run->refused_count)->toBe(1);
        expect($run->needsAttention())->toBeTrue();
        expect($draftStep->status)->toBe(PlaybookActionStatus::Refused);
        expect($draftStep->reason)->toBe(PlaybookOutcomeReason::OutreachBlocked);
        // The guard's own vocabulary, passed through rather than restated by Signals.
        expect($draftStep->params['block_reason'])->toBe('restricted');
        expect($draftStep->params['is_compliance'])->toBeTrue();

        expect($drafter->asked)->toHaveCount(1);
    });

    it('is refused outright when nothing on this deployment writes drafts', function (): void {
        // Signals' own default binding. Conservative rather than permissive: with no drafting
        // module bound the step says so instead of reporting success.
        SignalPlaybook::factory()
            ->create([
                'team_id' => $this->team->getKey(),
                'actions' => [
                    ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                    [
                        'kind' => PlaybookActionKind::DraftOutreach->value,
                        'params' => ['channel' => 'nonsense', 'tone' => 'nonsense'],
                    ],
                ],
            ]);

        app()->instance(SignalOutreachDrafter::class, new NoOutreachDrafting);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        expect(playbookRuns($this->team)->first()->outcomes()[1]->reason)
            ->toBe(PlaybookOutcomeReason::DraftingUnavailable);
    });

    it('never sends anything', function (): void {
        // The structural assertion behind docs/compliance.md §3.12: there is no send on the seam a
        // playbook reaches outreach through, so no rule can transmit.
        expect(method_exists(SignalOutreachDrafter::class, 'send'))->toBeFalse();
        expect(array_map(
            static fn (ReflectionMethod $method): string => $method->getName(),
            (new ReflectionClass(SignalOutreachDrafter::class))->getMethods(),
        ))->toBe(['channels', 'tones', 'draftForReview']);
    });
});

describe('tenancy', function (): void {
    it('refuses a stage from another team', function (): void {
        $other = createUserWithTeam()->currentTeam()->firstOrFail();
        app(EnsureDefaultPipelineStagesAction::class)->handle($other);

        $theirStage = app(TeamContext::class)->run($other, fn (): PipelineStage => PipelineStage::query()
            ->orderBy('position')
            ->firstOrFail());

        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::MoveToPipelineStage->value,
                    'params' => ['stage_id' => (int) $theirStage->getKey()],
                ],
            ],
        ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        $lead = app(TeamContext::class)->run($this->team, fn (): ?Lead => Lead::query()->first());

        expect($lead->pipeline_stage_id)->not->toBe($theirStage->getKey());
        expect(playbookRuns($this->team)->first()->outcomes()[1]->reason)
            ->toBe(PlaybookOutcomeReason::StageNotFound);
    });

    it('refuses a member from another team', function (): void {
        $outsider = createUserWithTeam();

        SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'actions' => [
                ['kind' => PlaybookActionKind::ConvertToLead->value, 'params' => []],
                [
                    'kind' => PlaybookActionKind::AssignToMember->value,
                    'params' => [
                        'strategy' => PlaybookAssignStrategy::Named->value,
                        'user_ids' => [(int) $outsider->getKey()],
                    ],
                ],
            ],
        ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        expect(LeadAssignment::query()->count())->toBe(0);
        expect(playbookRuns($this->team)->first()->outcomes()[1]->reason)
            ->toBe(PlaybookOutcomeReason::NoMembers);
    });

    it('never reaches another team signals', function (): void {
        $other = createUserWithTeam()->currentTeam()->firstOrFail();
        $theirSignal = playbookSignal($other);

        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        $result = runPlaybooks($this->team, [$theirSignal]);

        expect($result['considered'])->toBe(0);
        expect(SignalPlaybookRun::query()->withoutGlobalScopes()->count())->toBe(0);
    });
});

describe('notifications', function (): void {
    it('sends one message per recipient per rule, however many signals matched', function (): void {
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::Notify, ['audience' => PlaybookNotifyAudience::Team->value])
            ->create(['team_id' => $this->team->getKey()]);

        runPlaybooks($this->team, [
            playbookSignal($this->team),
            playbookSignal($this->team),
            playbookSignal($this->team),
        ]);

        $notifications = Notification::query()
            ->where('type', 'alima.'.NotificationType::PlaybookFired->value)
            ->get();

        // Three signals, one member, one message — carrying the count.
        expect($notifications)->toHaveCount(1);
        expect($notifications->first()->params()['count'] ?? null)->toBe(3);
        // Keys and params, never prose: the wording is composed at render time (EN/AR).
        expect($notifications->first()->data)->not->toHaveKey('headline');
    });
});

describe('the actor', function (): void {
    it('acts as the rule creator', function (): void {
        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create([
                'team_id' => $this->team->getKey(),
                'created_by' => $this->owner->getKey(),
            ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        expect(playbookRuns($this->team)->first()->actor_id)->toBe($this->owner->getKey());
    });

    it('falls back to the owner when the creator can no longer act', function (): void {
        // Membership alone is not enough: a member demoted to Read-only would otherwise go on
        // converting, assigning and drafting for ever, because the module Actions take an actor
        // and do not consult a policy.
        $demoted = User::factory()->create();
        app(TeamMembership::class)->add($demoted, $this->team, TeamRole::Member);

        $playbook = SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create([
                'team_id' => $this->team->getKey(),
                'created_by' => $demoted->getKey(),
            ]);

        $this->team->users()->updateExistingPivot($demoted->getKey(), ['role' => TeamRole::ReadOnly->value]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        expect(playbookRuns($this->team)->first()->actor_id)->toBe($this->owner->getKey());
        expect($playbook->fresh()->created_by)->toBe($demoted->getKey());
    });

    it('refuses every step when the rule has nobody to act as', function (): void {
        // A team with no owner at all — the creator has gone and there is no one to stand in. The
        // alternative, running as a synthetic user, would make every entry it wrote
        // unattributable.
        $team = Team::factory()->create();

        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create(['team_id' => $team->getKey()]);

        runPlaybooks($team, [playbookSignal($team)]);

        $run = playbookRuns($team)->first();

        expect($run->refused_count)->toBe(1);
        expect($run->outcomes()[0]->reason)->toBe(PlaybookOutcomeReason::NoActor);
        expect(Lead::query()->count())->toBe(0);
    });
});

describe('the log outlives the rule', function (): void {
    it('keeps the run and its name when the playbook is deleted', function (): void {
        $playbook = SignalPlaybook::factory()->create([
            'team_id' => $this->team->getKey(),
            'name' => 'Handle acquisitions',
        ]);

        runPlaybooks($this->team, [playbookSignal($this->team)]);

        app(TeamContext::class)->run($this->team, static fn () => $playbook->delete());

        $run = playbookRuns($this->team)->first();

        expect($run)->not->toBeNull();
        expect($run->signal_playbook_id)->toBeNull();
        expect($run->playbook_name)->toBe('Handle acquisitions');
    });
});
