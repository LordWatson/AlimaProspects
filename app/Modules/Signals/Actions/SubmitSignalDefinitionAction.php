<?php

declare(strict_types=1);

namespace App\Modules\Signals\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Enums\SignalSubmissionStatus;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSubmission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * A team offers one of their signal types to the platform library (S15 task 4).
 *
 * Plan decision #3 in one Action: **a team submits, an admin curates.** Nothing about the team's own
 * type changes — it keeps running, keeps its name and keeps its history — and the row this writes is
 * a request, not a publication.
 *
 * Three refusals, each for a different reason:
 *
 * - **A platform preset cannot be submitted**, because it *is* the library. A team's **clone** can
 *   be, deliberately: a team who took `funding_round` and spent three months improving its
 *   templates is the best source of a better `funding_round`, and an operator folds that back in by
 *   naming the preset's key on promotion ({@see PromoteSignalDefinitionAction}). Only a row with no
 *   `team_id` is refused, which is also the belt to the braces of a route that is team-scoped
 *   anyway.
 * - **A type with no searches cannot be submitted.** An empty template list is nothing another team
 *   could start from, and letting it through would mean an operator reviewing a blank.
 * - **One open submission per type.** Pressing the button twice asks the same question, and an
 *   operator seeing it twice has to work out whether the second is a revision.
 *
 * Must be called inside the team's context.
 */
final class SubmitSignalDefinitionAction
{
    /** Longest note we will carry. A covering note, not an essay somebody has to read twice. */
    public const int MAX_NOTE = 500;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @throws SignalsException when the type cannot be offered, or already has an open offer
     */
    public function handle(
        Team $team,
        User $actor,
        SignalDefinition $definition,
        ?string $note = null,
    ): SignalDefinitionSubmission {
        if ($definition->isPreset()) {
            throw SignalsException::presetNotSubmittable();
        }

        // **The pair is asserted rather than assumed** (review). Both callers resolve the
        // definition through a team-scoped lookup and the Policy checks the member, so this cannot
        // fire today — which is exactly when a one-line invariant is cheap. An Action that took a
        // team and a row of somebody else's and wrote a submission naming the first would be a
        // tenancy bug with nothing in its own file to stop it.
        if ((int) $definition->team_id !== (int) $team->getKey()) {
            throw SignalsException::definitionNotYours();
        }

        if ($definition->query_templates === []) {
            throw SignalsException::nothingToSubmit();
        }

        return $this->teamContext->run($team, fn (): SignalDefinitionSubmission => DB::transaction(function () use ($team, $actor, $definition, $note): SignalDefinitionSubmission {
            $existing = SignalDefinitionSubmission::query()
                ->where('signal_definition_id', $definition->getKey())
                ->whereIn('status', [
                    SignalSubmissionStatus::Pending->value,
                    SignalSubmissionStatus::Promoted->value,
                ])
                ->lockForUpdate()
                ->first();

            if ($existing instanceof SignalDefinitionSubmission) {
                throw $existing->status === SignalSubmissionStatus::Promoted
                    ? SignalsException::alreadyInLibrary()
                    : SignalsException::submissionPending();
            }

            // A rejected or withdrawn row for the same type is **replaced**, not kept beside the
            // new one, and the honest version of why is narrower than the first draft claimed
            // (review). `unique(signal_definition_id, status)` holds **one row per status**, so a
            // type offered, rejected, offered again and rejected again cannot keep both rejections
            // — the second would collide. Deleting the settled row is what makes re-offering work
            // at all, which `SignalSubmissionStatus::allowsResubmission()` says a team may do.
            //
            // The cost is real and accepted: **the previous review note goes with it.** A team
            // re-offering a type has read our answer and acted on it, the queue and their own list
            // are meant to show one row per type rather than a history nobody reads, and the
            // audit log keeps the record that the exchange happened. If the history is ever
            // wanted, the fix is to drop this unique and enforce "one *open* offer" in the Action
            // instead — MySQL has no partial index, which is why it is not enforced that way now.
            SignalDefinitionSubmission::query()
                ->where('signal_definition_id', $definition->getKey())
                ->whereIn('status', [
                    SignalSubmissionStatus::Rejected->value,
                    SignalSubmissionStatus::Withdrawn->value,
                ])
                ->delete();

            /** @var SignalDefinitionSubmission $submission */
            $submission = SignalDefinitionSubmission::query()->create([
                'team_id' => $team->getKey(),
                'submitted_by' => $actor->getKey(),
                'signal_definition_id' => $definition->getKey(),
                'status' => SignalSubmissionStatus::Pending,
                // The team's own words, stored as prose — the one kind of text in this codebase
                // that is not a translation key, because there is nothing to translate about
                // somebody's own sentence.
                'note' => $this->note($note),
            ]);

            $this->audit->log('signals.definition_submitted', $definition, [
                'submission_id' => (int) $submission->getKey(),
                'has_note' => $submission->note !== null,
            ], $actor);

            return $submission;
        }));
    }

    /**
     * The team takes an offer back.
     *
     * Kept as a row rather than deleted, so the record that they asked survives until they offer
     * the same type again — and so the review queue can tell "withdrawn" from "never submitted" if
     * an operator was halfway through reading it. (Re-offering replaces it; see `handle()` for why
     * the unique index makes that the only workable shape.)
     */
    public function withdraw(Team $team, User $actor, SignalDefinitionSubmission $submission): SignalDefinitionSubmission
    {
        return $this->teamContext->run($team, fn (): SignalDefinitionSubmission => DB::transaction(function () use ($actor, $submission): SignalDefinitionSubmission {
            /** @var SignalDefinitionSubmission $locked */
            $locked = SignalDefinitionSubmission::query()
                ->whereKey($submission->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw SignalsException::submissionSettled();
            }

            $locked->forceFill([
                'status' => SignalSubmissionStatus::Withdrawn,
                'reviewed_at' => now(),
            ])->save();

            $this->audit->log('signals.definition_submission_withdrawn', $locked, [
                'signal_definition_id' => (int) $locked->signal_definition_id,
            ], $actor);

            return $locked;
        }));
    }

    private function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }

        $trimmed = Str::limit(Str::squish($note), self::MAX_NOTE, '');

        return $trimmed === '' ? null : $trimmed;
    }
}
