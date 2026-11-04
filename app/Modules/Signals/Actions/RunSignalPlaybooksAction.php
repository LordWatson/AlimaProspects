<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Services\NotificationDispatcher;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookFacts;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookActionStatus;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPlaybookRun;
use App\Modules\Signals\Services\PlaybookStepRegistry;
use App\Modules\Signals\Services\SignalPlaybookEvaluator;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Support\Playbook\PlaybookActor;
use App\Modules\Signals\Support\Playbook\PlaybookBudget;
use App\Modules\Signals\Support\Playbook\PlaybookContext;
use App\Modules\Signals\Support\Playbook\PlaybookNotices;
use App\Modules\Signals\Support\Playbook\PlaybookStepHandler;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Puts a batch of signals through a team's playbooks (S14).
 *
 * **The order of operations is the design**, and the awkward-looking part of it is the important
 * part:
 *
 * 1. **The run row is claimed first**, in its own short transaction, before a single step executes.
 *    That insert is what wins the `(signal_id, playbook_id)` unique — the once-per-signal
 *    guarantee — so two workers racing the same signal have exactly one of them do the work. The
 *    alternative, executing and then inserting, would have the loser discover its duplicate
 *    *after* creating a second task and spending a second draft.
 * 2. **The steps execute outside any transaction.** One of them calls an AI model
 *    (CLAUDE.md's external-call rule) and each of the others carries a transaction of its own,
 *    inside the module that owns the write. Wrapping the chain would nest five modules' writes in
 *    one long-held transaction and hold row locks across an HTTP call.
 * 3. **The row is filled in afterwards.** A crash between 1 and 3 leaves a claimed row with no
 *    results, which is honest — we started this and did not finish — and which stops a retry doing
 *    half of it twice. The log shows it as an empty run rather than pretending.
 * 4. **Notifications go out last, aggregated** (`PlaybookNotices`). A rule that fired on twelve
 *    signals is one message, not twelve (CLAUDE.md §3).
 *
 * The four brakes the phase plan asks for are all here or under here: the once-per-signal unique
 * above, {@see PlaybookBudget}'s per-job spend ceilings, `signals.playbooks.max_signals_per_run`,
 * and the dry-run mode that makes every handler do nothing. Keep all four.
 *
 * Must be called with the team in hand; it establishes the team context itself.
 */
final class RunSignalPlaybooksAction
{
    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SignalPlaybookEvaluator $evaluator,
        private readonly PlaybookStepRegistry $handlers,
        private readonly PlaybookActor $actors,
        private readonly NotificationDispatcher $dispatcher,
        private readonly SignalSpendGuard $spend,
    ) {}

    /**
     * Run the team's playbooks over these signals.
     *
     * **`deferred` is the signals it did not get to**, and the caller is expected to come back for
     * them: those past the batch ceiling, and those a rule could not afford. Neither is claimed, so
     * neither is lost — see {@see PlaybookBudget::canAfford()} for why a cap must defer rather than
     * refuse.
     *
     * @param  list<int>  $signalIds
     * @return array{considered: int, fired: int, deferred: list<int>}
     */
    public function handle(Team $team, array $signalIds): array
    {
        if ($signalIds === []) {
            return ['considered' => 0, 'fired' => 0, 'deferred' => []];
        }

        // **A downgrade stops the rules without failing the run** (S16 task 2). The save path
        // refuses outright; this one returns quietly, because the caller is a listener on an
        // after-commit event and therefore runs inside the watch's own job — a throw here would
        // fail the run and the retry would re-bill the Serper search (the S14 review's finding,
        // which is also why nothing is deferred: there is nothing to come back for).
        if (! $this->spend->allowsPlaybooks($team)) {
            return ['considered' => 0, 'fired' => 0, 'deferred' => []];
        }

        $cap = max(0, (int) config('signals.playbooks.max_signals_per_run', 200));
        $overCap = array_slice($signalIds, $cap);
        $signalIds = array_slice($signalIds, 0, $cap);

        return $this->teamContext->run($team, function () use ($team, $signalIds, $overCap): array {
            /** @var list<SignalPlaybook> $playbooks */
            $playbooks = SignalPlaybook::query()->runnable()->get()->all();

            if ($playbooks === []) {
                // Nothing to come back for: there are no rules, so the tail is not deferred work.
                return ['considered' => 0, 'fired' => 0, 'deferred' => []];
            }

            $budget = PlaybookBudget::fromConfig();
            $notices = new PlaybookNotices;
            $actors = [];
            $considered = 0;
            $fired = 0;
            $deferred = $overCap;

            // Chunked, and every relation the facts and the handlers read is eager-loaded here:
            // `preventLazyLoading` is on outside production and a job walking two hundred signals
            // is exactly where a lazy relation would be a hundred queries nobody noticed.
            Signal::query()
                ->whereIn('id', $signalIds)
                ->with(['cluster', 'watch', 'matchedLead', 'lead'])
                ->orderBy('id')
                ->chunkById(50, function ($chunk) use (
                    $team, $playbooks, $budget, $notices, &$actors, &$considered, &$fired, &$deferred
                ): void {
                    foreach ($chunk as $signal) {
                        // **A member's "no" outranks a rule.** A signal dismissed between the run
                        // and this job is left alone entirely — not claimed, not logged — because a
                        // rule that converted it and drafted an approach for it would be the
                        // product overriding a human decision (found in the S14 review).
                        // `ConvertSignalToLeadAction` deliberately permits converting a dismissed
                        // signal, since a member may ask for exactly that; a rule may not.
                        if ($signal->status === SignalStatus::Dismissed) {
                            continue;
                        }

                        $considered++;

                        $facts = SignalPlaybookFacts::fromSignal($signal);

                        foreach ($this->evaluator->select($playbooks, $facts) as $playbook) {
                            $kinds = array_map(
                                static fn (SignalPlaybookStep $step): PlaybookActionKind => $step->kind,
                                $playbook->steps(),
                            );

                            // Out of budget: leave the signal for the next pass rather than
                            // claiming it and recording a refusal the rule can never retry.
                            if (! $budget->canAfford($kinds, ! $playbook->mode->performs())) {
                                $deferred[] = (int) $signal->getKey();

                                break;
                            }

                            if ($this->fire($team, $signal, $playbook, $budget, $notices, $actors)) {
                                $fired++;
                            }
                        }
                    }
                });

            $this->announce($team, $notices);

            Log::channel('signals')->info('Playbooks evaluated.', [
                'team_id' => $team->getKey(),
                'playbooks' => count($playbooks),
                'considered' => $considered,
                'fired' => $fired,
                'deferred' => count($deferred),
            ]);

            return [
                'considered' => $considered,
                'fired' => $fired,
                // Distinct, because one signal may be deferred by several rules in one pass.
                'deferred' => array_values(array_unique($deferred)),
            ];
        });
    }

    /**
     * Claim the signal for this playbook, run its steps, and record what happened.
     *
     * Returns false when the claim was lost — the playbook has already handled this signal, which
     * is the normal case on a retry and on the `SignalConverted` event that a conversion step of
     * this very chain raised.
     *
     * @param  array<int, User|false>  $actors  Resolved once per playbook; `false` memoises "this
     *                                          rule has nobody to act as".
     */
    private function fire(
        Team $team,
        Signal $signal,
        SignalPlaybook $playbook,
        PlaybookBudget $budget,
        PlaybookNotices $notices,
        array &$actors,
    ): bool {
        $playbookId = (int) $playbook->getKey();

        if (! array_key_exists($playbookId, $actors)) {
            $actors[$playbookId] = $this->actors->for($team, $playbook) ?? false;
        }

        $actor = $actors[$playbookId];

        $run = $this->claim($team, $signal, $playbook, $actor instanceof User ? $actor : null);

        if (! $run instanceof SignalPlaybookRun) {
            return false;
        }

        if (! $actor instanceof User) {
            // Nobody to act as: the creator has left and the team has no owner. Recorded as a
            // refusal on every step rather than silently doing nothing, because "my rule stopped
            // working" with no explanation is the support ticket this log exists to prevent.
            $this->record($run, array_map(
                static fn (SignalPlaybookStep $step): PlaybookStepOutcome => PlaybookStepOutcome::refused(
                    $step->kind,
                    PlaybookOutcomeReason::NoActor,
                ),
                $playbook->steps(),
            ), null);

            return true;
        }

        $context = new PlaybookContext(
            team: $team,
            signal: $signal,
            playbook: $playbook,
            actor: $actor,
            mode: $playbook->mode,
            budget: $budget,
            notices: $notices,
            // The lead the signal is already about: its own conversion, or the lead S10 matched the
            // event to. A rule over accounts the team already works therefore needs no conversion
            // step at all, which is the common case and the cheap one.
            lead: $this->startingLead($signal),
        );

        $outcomes = [];

        foreach ($playbook->steps() as $step) {
            $handler = $this->handlers->find($step->kind);

            if (! $handler instanceof PlaybookStepHandler) {
                // A kind this build knows but has nothing wired up for. **Recorded** rather than
                // dropped, which is the difference from an *unknown* kind: the step is in the rule
                // and the member can see it on the screen, so leaving it out of the log would make
                // the counts lie about what the rule did (found in the S14 review).
                $outcomes[] = PlaybookStepOutcome::refused(
                    $step->kind,
                    PlaybookOutcomeReason::StepUnavailable,
                );

                continue;
            }

            try {
                $outcome = $handler->handle($context, $step);
            } catch (Throwable $e) {
                // The owning module's Action threw. Recorded and the chain **continues**: the steps
                // are independent instructions, and abandoning the tag because the draft failed
                // would lose work the team asked for. Nothing about the exception's message is
                // stored — an ids-and-counts log is the module's rule, and an exception message is
                // the one place a prospect's name could leak into JSON.
                Log::channel('signals')->warning('A playbook step failed.', [
                    'team_id' => $team->getKey(),
                    'playbook_id' => $playbookId,
                    'signal_id' => (int) $signal->getKey(),
                    'kind' => $step->kind->value,
                    'exception' => $e::class,
                ]);

                $outcomes[] = PlaybookStepOutcome::failed($step->kind, PlaybookOutcomeReason::ActionFailed);

                continue;
            }

            if ($outcome->lead instanceof Lead) {
                $context->useLead($outcome->lead);
            }

            $outcomes[] = $outcome;
        }

        $this->record($run, $outcomes, $context->lead());

        return true;
    }

    /**
     * Claim `(signal, playbook)`, or null when somebody already has.
     *
     * The insert is the lock. A caught {@see UniqueConstraintViolationException} is the whole
     * once-per-signal guarantee working, not an error — which is why it returns null rather than
     * rethrowing, and why nothing is logged at warning level for it.
     */
    private function claim(Team $team, Signal $signal, SignalPlaybook $playbook, ?User $actor): ?SignalPlaybookRun
    {
        try {
            // `attempts: 3`, because three workers inserting the same key can meet on InnoDB's
            // shared-lock queue and get a deadlock rather than a duplicate-key error. Harmless for
            // correctness — the retry finds the pair claimed — but it would otherwise fail the job.
            return DB::transaction(fn (): SignalPlaybookRun => SignalPlaybookRun::query()->create([
                'team_id' => $team->getKey(),
                'signal_playbook_id' => $playbook->getKey(),
                // Copied, so the log survives the rule being deleted.
                'playbook_name' => $playbook->name,
                'signal_id' => $signal->getKey(),
                'lead_id' => null,
                'actor_id' => $actor?->getKey(),
                // The mode **at the time**: a rule rehearsed and then switched on has a log in two
                // halves, and relabelling the first half would destroy the rehearsal somebody made
                // the decision on.
                'mode' => $playbook->mode,
                'results' => [],
            ]), attempts: 3);
        } catch (UniqueConstraintViolationException) {
            return null;
        } catch (QueryException $e) {
            // The rule was deleted between the evaluation and here, so the foreign key refuses the
            // insert. Nothing is wrong and nothing to do: the rule is gone.
            Log::channel('signals')->info('A playbook could not claim a signal.', [
                'team_id' => $team->getKey(),
                'playbook_id' => $playbook->getKey(),
                'signal_id' => $signal->getKey(),
                'exception' => $e::class,
            ]);

            return null;
        }
    }

    /**
     * Fill in what the chain did.
     *
     * @param  list<PlaybookStepOutcome>  $outcomes
     */
    private function record(SignalPlaybookRun $run, array $outcomes, ?Lead $lead): void
    {
        $counts = [
            PlaybookActionStatus::Applied->value => 0,
            PlaybookActionStatus::Refused->value => 0,
            PlaybookActionStatus::Failed->value => 0,
        ];

        foreach ($outcomes as $outcome) {
            if (array_key_exists($outcome->status->value, $counts)) {
                $counts[$outcome->status->value]++;
            }
        }

        DB::transaction(static fn () => $run->forceFill([
            // The stamp that tells an interrupted run apart from one that matched nothing. See the
            // `completed_at` migration for why the claim comes first and this second.
            'completed_at' => now(),
            'results' => array_map(
                static fn (PlaybookStepOutcome $outcome): array => $outcome->toStored(),
                $outcomes,
            ),
            'lead_id' => $lead?->getKey(),
            'applied_count' => $counts[PlaybookActionStatus::Applied->value],
            'refused_count' => $counts[PlaybookActionStatus::Refused->value],
            'failed_count' => $counts[PlaybookActionStatus::Failed->value],
        ])->save());
    }

    /**
     * The lead the signal already points at, if any — read off the loaded relations.
     */
    private function startingLead(Signal $signal): ?Lead
    {
        $converted = $signal->relationLoaded('lead') ? $signal->lead : null;

        if ($converted instanceof Lead) {
            return $converted;
        }

        $matched = $signal->relationLoaded('matchedLead') ? $signal->matchedLead : null;

        return $matched instanceof Lead ? $matched : null;
    }

    /**
     * One notification per recipient per rule, after everything has run.
     *
     * The dispatcher re-checks membership and preferences, so a member who left between the step
     * and here is dropped and one who switched this type off is never sent to.
     */
    private function announce(Team $team, PlaybookNotices $notices): void
    {
        if ($notices->isEmpty()) {
            return;
        }

        foreach ($notices->all() as $recipientId => $perPlaybook) {
            /** @var User|null $recipient */
            $recipient = $team->users()->where('users.id', $recipientId)->first();

            if (! $recipient instanceof User) {
                continue;
            }

            foreach ($perPlaybook as $notice) {
                $this->dispatcher->send($team, $recipient, NotificationType::PlaybookFired, [
                    'playbook' => $notice['playbook'],
                    'count' => $notice['signals'],
                    'lead_id' => $notice['lead_id'],
                ]);
            }
        }
    }
}
