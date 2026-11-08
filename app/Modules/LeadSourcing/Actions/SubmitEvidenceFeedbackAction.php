<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\AuditLogger;
use App\Modules\LeadSourcing\Enums\EvidenceVerdict;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Models\LeadEvidenceFeedback;
use Illuminate\Support\Facades\DB;

/**
 * Record a member's verdict on one item of evidence, and — where the verdict disputes the fact
 * itself — take that fact out of the lead's confidence immediately (PL1).
 *
 * **Only `wrong` moves a number.** The other three verdicts are kept and reported and change
 * nothing, which is deliberate: "out of date" is what decay already measures, "not relevant" is a
 * statement about this team's interests rather than about the fact, and "useful" is the signal the
 * post-launch outcome loop will learn from. Four buttons each nudging the same weight would mean
 * nobody could say afterwards what a confidence of 61 had been told.
 *
 * **A dispute is one member's, and its effect is the team's.** That is the honest reading of a
 * shared workspace — the first member to notice that a filing names the wrong company should not
 * have to persuade their colleagues before the lead stops claiming it — and the row records who
 * said so. Un-disputing is therefore the same act in reverse: changing your verdict away from
 * `wrong`, when nobody else is still saying it, restores the fact.
 */
final class SubmitEvidenceFeedbackAction
{
    public function __construct(
        private readonly RecalculateLeadConfidenceAction $recalculate,
        private readonly AuditLogger $audit,
    ) {}

    public function handle(
        Team $team,
        LeadEvidence $evidence,
        User $actor,
        EvidenceVerdict $verdict,
        ?string $note = null,
    ): LeadEvidenceFeedback {
        $feedback = DB::transaction(function () use ($team, $evidence, $actor, $verdict, $note): LeadEvidenceFeedback {
            $feedback = LeadEvidenceFeedback::query()->updateOrCreate(
                [
                    'lead_evidence_id' => $evidence->getKey(),
                    'user_id' => $actor->getKey(),
                ],
                [
                    'team_id' => $team->getKey(),
                    'verdict' => $verdict,
                    'note' => $note,
                ],
            );

            // Re-read the verdicts rather than trusting the one just written: a second member may
            // still be disputing this fact, and restoring it on one person's change of mind would
            // silently overrule them.
            $disputed = LeadEvidenceFeedback::query()
                ->where('lead_evidence_id', $evidence->getKey())
                ->where('verdict', EvidenceVerdict::Wrong)
                ->exists();

            if ($evidence->is_disputed !== $disputed) {
                $evidence->forceFill(['is_disputed' => $disputed])->save();
            }

            return $feedback;
        });

        // Outside the transaction and after the dispute flag is settled, so the confidence it
        // writes is the one the evidence now justifies.
        $this->recalculate->handle($evidence->lead, $team);

        $this->audit->log('lead.evidence_flagged', $evidence, [
            'lead_id' => $evidence->lead_id,
            'source' => $evidence->source,
            'verdict' => $verdict->value,
            // The note is the member's own words about a prospect's data; the trail records that
            // one was left, never what it said.
            'has_note' => $note !== null && trim($note) !== '',
        ], $actor);

        return $feedback;
    }
}
