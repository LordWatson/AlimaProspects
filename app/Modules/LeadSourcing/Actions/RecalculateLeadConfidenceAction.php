<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Data\LeadConfidenceResult;
use App\Modules\LeadSourcing\Jobs\GenerateWhyNowSummaryJob;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Recompute and store a lead's confidence, timing score and suppression (PL1).
 *
 * Called whenever a lead's evidence changes — a new reason recorded, a fact disputed, a backfill —
 * and **synchronously**, because the whole calculation is arithmetic over rows this process has
 * just written. Queueing it would mean a lead was briefly on the list with the confidence it had
 * before the evidence that justified it, and "flagging a fact as wrong lowers the confidence
 * immediately" would have become "within a queue cycle".
 *
 * The one thing it does queue is the **summary**, and only when the evidence set has actually
 * changed: that is an AI call, it is dispatched from `afterCommit` so a rolled-back write cannot
 * leave a job chasing evidence that was never saved, and the hash gate is what keeps a nightly
 * re-score from being a nightly bill.
 */
final class RecalculateLeadConfidenceAction
{
    public function __construct(
        private readonly LeadConfidence $confidence,
        private readonly TeamContext $teamContext,
    ) {}

    /**
     * Recalculate `$lead` and persist the result, returning what was worked out.
     *
     * `$team` decides the suppression threshold, and defaults to the ambient {@see TeamContext} —
     * which every caller already runs inside and which memoises the team by id, so a backfill of
     * ten thousand leads costs one query for the threshold rather than ten thousand. A caller that
     * holds the team may pass it and save even that.
     */
    public function handle(Lead $lead, ?Team $team = null, ?DateTimeInterface $now = null): LeadConfidenceResult
    {
        $team ??= $this->teamFor($lead);

        // Always from the database rather than from whatever the caller happens to have loaded: an
        // Action that silently judged a lead on a stale relation would produce a confidence nobody
        // could reproduce from the rows.
        $lead->load('evidence');

        $result = $this->confidence->for($lead, $team, $now);

        $hashChanged = $lead->why_now_evidence_hash !== $result->evidenceHash;

        $lead->forceFill([
            'confidence' => $result->confidence,
            'timing_score' => $result->timingScore,
            'confidence_at' => $result->confidence === null ? null : Carbon::now(),
            'suppressed_low_confidence' => $result->suppressed,
        ])->save();

        if ($hashChanged && $result->confidence !== null && $this->wantsASummary($team)) {
            // Not `$lead->why_now_evidence_hash = ...` here: the job re-reads the lead and writes
            // the hash alongside the summary it actually generated, so a failed call leaves the
            // lead asking to be summarised again rather than marked as done with no sentence.
            DB::afterCommit(fn () => GenerateWhyNowSummaryJob::dispatch(
                (int) $lead->team_id,
                (int) $lead->getKey(),
            ));
        }

        return $result;
    }

    /**
     * Whether this team should be paying for a why-now sentence yet (PL1).
     *
     * **This is the one half of the feature that is not dark-launched for free.** The arithmetic
     * above runs for every team whatever the flag says — it is a handful of multiplications over
     * rows we already hold, and running it is what makes the backfill and the first enabled team
     * cheap. A summary is an AI call, so dispatching one for a team that cannot see the panel
     * would be spending their credit allowance on a feature they have not got.
     *
     * No team in context means no answer, and the safe answer to "should we spend money" is no.
     * The lead keeps its evidence and its confidence, and its next recalculation inside a team's
     * context will queue the sentence.
     */
    private function wantsASummary(?Team $team): bool
    {
        return $team instanceof Team && PrelaunchFeature::WhyNow->activeFor($team);
    }

    /**
     * The lead's team from the ambient context, or null when the context is pointing somewhere
     * else — in which case the threshold falls back to the configured default and the lead is
     * corrected by its next recalculation.
     *
     * Deliberately **not** `$lead->team`: a relation read here is a lazy load, and this Action runs
     * inside a model `created` hook, where `preventLazyLoading` rightly fails it.
     */
    private function teamFor(Lead $lead): ?Team
    {
        return $this->teamContext->id() === (int) $lead->team_id
            ? $this->teamContext->team()
            : null;
    }
}
