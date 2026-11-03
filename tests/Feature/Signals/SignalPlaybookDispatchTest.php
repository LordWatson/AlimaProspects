<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalPlaybooksAction;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Events\SignalConverted;
use App\Modules\Signals\Events\SignalsDetected;
use App\Modules\Signals\Jobs\RunSignalPlaybooksJob;
use App\Modules\Signals\Listeners\RunPlaybooks;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Models\SignalRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

/**
 * How a signal reaches the rules (S14): the listener, the job, and the two things that must not
 * happen.
 *
 * Separate from `SignalPlaybookTest`, which exercises the chain itself. What is being pinned here
 * is the **plumbing**, and both of its failure modes cost real money:
 *
 * - **Nothing here may fail the thing that raised the event.** The listener runs inside
 *   `RunSignalWatchAction`'s after-commit callback, so an exception propagated into the watch-run
 *   job, failed it, and the retry re-ran the Serper search and re-billed the team.
 * - **The deferred tail comes back.** Signals past the batch ceiling, and signals a rule could not
 *   afford, are handed to a later job rather than dropped.
 */
beforeEach(function (): void {
    $this->owner = createUserWithTeam();
    $this->team = $this->owner->currentTeam()->firstOrFail();
});

function dispatchSignals(Team $team, int $count = 1): SignalRun
{
    return app(TeamContext::class)->run($team, function () use ($team, $count): SignalRun {
        $run = SignalRun::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->count($count)->create([
            'team_id' => $team->getKey(),
            'signal_run_id' => $run->getKey(),
        ]);

        return $run;
    });
}

describe('the listener', function (): void {
    it('queues one job for the signals a run produced', function (): void {
        Queue::fake();

        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);
        $run = dispatchSignals($this->team, 3);

        app(RunPlaybooks::class)->detected(
            new SignalsDetected((int) $this->team->getKey(), (int) $run->getKey(), 3),
        );

        Queue::assertPushed(
            RunSignalPlaybooksJob::class,
            fn (RunSignalPlaybooksJob $job): bool => count($job->signalIds) === 3,
        );
    });

    it('queues nothing when the team has no rules', function (): void {
        // The cheapest possible question, asked first: a team with no playbooks is the
        // overwhelming majority, and a job per watch run that does nothing is a queue nobody can
        // read.
        Queue::fake();

        $run = dispatchSignals($this->team, 2);

        app(RunPlaybooks::class)->detected(
            new SignalsDetected((int) $this->team->getKey(), (int) $run->getKey(), 2),
        );

        Queue::assertNothingPushed();
    });

    it('queues nothing when the only rule is paused', function (): void {
        Queue::fake();

        SignalPlaybook::factory()->paused()->create(['team_id' => $this->team->getKey()]);
        $run = dispatchSignals($this->team, 1);

        app(RunPlaybooks::class)->detected(
            new SignalsDetected((int) $this->team->getKey(), (int) $run->getKey(), 1),
        );

        Queue::assertNothingPushed();
    });

    it('swallows its own failure rather than failing the watch run that raised the event', function (): void {
        // This is the expensive one. The listener runs inside the watch run's after-commit
        // callback, so a queue outage here used to fail the watch job — and the retry re-ran the
        // paid Serper search. The signals are already saved by this point; automating them is
        // worth nothing next to that.
        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);
        $run = dispatchSignals($this->team, 1);

        Queue::shouldReceive('connection')->andThrow(new RuntimeException('queue is down'));

        app(RunPlaybooks::class)->detected(
            new SignalsDetected((int) $this->team->getKey(), (int) $run->getKey(), 1),
        );
    })->throwsNoExceptions();

    it('queues nothing for a team that no longer exists', function (): void {
        Queue::fake();

        app(RunPlaybooks::class)->converted(new SignalConverted(999_999, 1, 1, false));

        Queue::assertNothingPushed();
    });
});

describe('the job', function (): void {
    it('hands the deferred tail to a later job', function (): void {
        Queue::fake();
        config()->set('signals.playbooks.max_signals_per_run', 1);

        SignalPlaybook::factory()->create(['team_id' => $this->team->getKey()]);

        $ids = app(TeamContext::class)->run($this->team, fn (): array => Signal::factory()
            ->count(2)
            ->create(['team_id' => $this->team->getKey()])
            ->modelKeys());

        (new RunSignalPlaybooksJob((int) $this->team->getKey(), array_map('intval', $ids)))
            ->handle(app(RunSignalPlaybooksAction::class));

        // One handled now, one coming back — rather than one handled and one silently dropped.
        expect(SignalPlaybookRun::query()->withoutGlobalScopes()->count())->toBe(1);

        Queue::assertPushed(
            RunSignalPlaybooksJob::class,
            fn (RunSignalPlaybooksJob $job): bool => $job->signalIds === [(int) $ids[1]],
        );
    });

    it('does not hand back a batch it made no progress on', function (): void {
        // The termination guard: a pass that deferred everything it was given must not re-queue
        // the same list for ever.
        Queue::fake();
        config()->set('signals.playbooks.max_conversions_per_run', 0);

        SignalPlaybook::factory()
            ->withStep(PlaybookActionKind::ConvertToLead)
            ->create(['team_id' => $this->team->getKey()]);

        $ids = app(TeamContext::class)->run($this->team, fn (): array => Signal::factory()
            ->count(2)
            ->create(['team_id' => $this->team->getKey()])
            ->modelKeys());

        (new RunSignalPlaybooksJob((int) $this->team->getKey(), array_map('intval', $ids)))
            ->handle(app(RunSignalPlaybooksAction::class));

        Queue::assertNothingPushed();
    });

    it('keys its uniqueness on the batch, not the team', function (): void {
        // Deliberately not one job per team: a team whose daily watch and whose manual run both
        // finish inside the window have two genuinely different batches to handle, and a team-wide
        // key would drop the second silently.
        $a = new RunSignalPlaybooksJob(1, [3, 1, 2]);
        $b = new RunSignalPlaybooksJob(1, [1, 2, 3]);
        $c = new RunSignalPlaybooksJob(1, [1, 2]);

        expect($a->uniqueId())->toBe($b->uniqueId());
        expect($a->uniqueId())->not->toBe($c->uniqueId());
    });

    it('does nothing for a team that has been deleted', function (): void {
        (new RunSignalPlaybooksJob(999_999, [1]))->handle(app(RunSignalPlaybooksAction::class));
    })->throwsNoExceptions();
});
