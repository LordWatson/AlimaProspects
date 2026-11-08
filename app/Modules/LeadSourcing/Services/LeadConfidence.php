<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Services;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Actions\RecalculateLeadConfidenceAction;
use App\Modules\LeadSourcing\Data\LeadConfidenceResult;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use DateTimeInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * How much a lead's evidence is worth (PL1): 0–100, deterministic, no AI and no network.
 *
 * **Three figures, three different questions, and this is the third.** `lead_scores.score` answers
 * *should we want them* and is deliberately blind to missing data. `leads.readiness` answers *can
 * we reach them* and is the one figure allowed to punish missing data. Confidence answers *how much
 * do we actually know* — it is a measure of our own evidence, not a verdict on the company, which
 * is why the UI never dresses it as a grade and why a low-confidence lead is **hidden rather than
 * marked down**.
 *
 * The arithmetic, in full:
 *
 * ```
 * item_score  = reliability × decay(observed_at)      // and 0 for a disputed item
 * confidence  = clamp(max(item_score) + corroboration, 0, 100)
 * timing      = max(item_score) over trigger-event items only
 * ```
 *
 * Three decisions inside that are worth stating, because each is a way the figure could be made to
 * lie:
 *
 * - **`max`, not a sum or an average.** One Companies House filing is strong evidence; forty web
 *   hits are not stronger than it. Summing would let a chatty provider out-argue a registry, and
 *   averaging would mean *adding* a weak reason to a strong lead lowered its confidence — so a team
 *   would be punished for finding out more.
 * - **Corroboration counts distinct sources, never rows.** Two independent sources agreeing is the
 *   thing worth paying for; one source saying it twice is not.
 * - **Unknown is not zero** (ADR 0022). A lead with no evidence gets `null`, and a null is left out
 *   of everything rather than scored nought — otherwise the figure would measure how much Alima had
 *   got round to looking rather than how much there is to know.
 *
 * Pure: it reads the evidence it is given and `config/evidence.php`, and writes nothing.
 * {@see RecalculateLeadConfidenceAction} persists the answer.
 */
final class LeadConfidence
{
    /**
     * Work out a lead's confidence from its evidence.
     *
     * Loads `evidence` if the caller has not. Pass `$team` to have the suppression decided against
     * that team's own threshold; without it the config default is used, which is what a console
     * render or a unit test wants.
     */
    public function for(Lead $lead, ?Team $team = null, ?DateTimeInterface $now = null): LeadConfidenceResult
    {
        $lead->loadMissing('evidence');

        return $this->fromEvidence($lead->evidence, $team, $now);
    }

    /**
     * The same arithmetic over an explicit set of evidence — what the unit tests and the backfill
     * use, so neither has to build a lead to ask a question about five rows.
     *
     * @param  Collection<int, LeadEvidence>  $evidence
     */
    public function fromEvidence(Collection $evidence, ?Team $team = null, ?DateTimeInterface $now = null): LeadConfidenceResult
    {
        $now ??= Carbon::now();

        // A disputed item is *present* but worth nothing, so it is dropped here rather than
        // weighted at zero: left in, it would still count towards corroboration, and a member
        // flagging a fact as wrong would have raised the lead's confidence by agreeing it exists.
        $counted = $evidence->reject(static fn (LeadEvidence $item): bool => $item->is_disputed);

        if ($counted->isEmpty()) {
            return LeadConfidenceResult::unjudged();
        }

        $weights = $counted->map(static fn (LeadEvidence $item): float => $item->currentWeight($now));

        $strongest = (float) $weights->max();
        $sourceCount = $counted->pluck('source')->unique()->count();

        $confidence = (int) round(min(100.0, max(0.0, $strongest + $this->corroboration($sourceCount))));

        return new LeadConfidenceResult(
            confidence: $confidence,
            timingScore: $this->timingScore($counted, $now),
            suppressed: $confidence < $this->thresholdFor($team),
            sourceCount: $sourceCount,
            evidenceHash: $this->hash($counted),
        );
    }

    /**
     * The team's line for "too thin to show me", clamped into the range the settings screen offers
     * so a stale or hand-edited value cannot hide a whole team's leads.
     *
     * A null `min_lead_confidence` means the config default rather than "no threshold": that is
     * what makes moving the default move every team that never expressed a preference.
     */
    public function thresholdFor(?Team $team): int
    {
        $threshold = $team instanceof Team && $team->min_lead_confidence !== null
            ? $team->min_lead_confidence
            : (int) config('evidence.default_min_confidence', 50);

        return max(
            (int) config('evidence.min_threshold', 30),
            min((int) config('evidence.max_threshold', 90), (int) $threshold),
        );
    }

    /**
     * Which band the pill shows. Null in, null out — "not judged yet" is a band of its own and the
     * screen says so in words rather than colouring it as weak.
     */
    public function band(?int $confidence): ?string
    {
        if ($confidence === null) {
            return null;
        }

        return match (true) {
            $confidence >= (int) config('evidence.bands.high', 75) => 'high',
            $confidence >= (int) config('evidence.bands.medium', 50) => 'medium',
            default => 'low',
        };
    }

    /**
     * The bonus for independent agreement: `corroboration_bonus` per source beyond the first,
     * capped. Zero for a single source, which is the ordinary case.
     */
    private function corroboration(int $sourceCount): float
    {
        $extra = max(0, $sourceCount - 1) * (int) config('evidence.corroboration_bonus', 10);

        return (float) min($extra, (int) config('evidence.corroboration_max', 20));
    }

    /**
     * The strongest decayed **trigger event**, or null when the lead has none.
     *
     * Deliberately not "the strongest anything": a company's sector is as true next quarter as it
     * is today, so letting a firmographic fact set the timing score would make every enriched lead
     * look urgent and the ordering worthless. No corroboration bonus either — two outlets covering
     * one funding round is not two reasons to call.
     *
     * @param  Collection<int, LeadEvidence>  $evidence
     */
    private function timingScore(Collection $evidence, DateTimeInterface $now): ?int
    {
        $timing = $evidence
            ->filter(static fn (LeadEvidence $item): bool => $item->kind->isTiming())
            ->map(static fn (LeadEvidence $item): float => $item->currentWeight($now));

        return $timing->isEmpty() ? null : (int) round(min(100.0, (float) $timing->max()));
    }

    /**
     * The identity of a lead's evidence set, used as the why-now summary's cache key.
     *
     * Over the evidence **ids and their disputed state** rather than over the rendered text: a
     * summary has to be regenerated when a fact is added or disputed, and must not be regenerated
     * because a decay figure moved overnight. Sorted, so the same set in a different query order is
     * the same hash.
     *
     * @param  Collection<int, LeadEvidence>  $evidence
     */
    private function hash(Collection $evidence): string
    {
        $parts = $evidence
            ->map(static fn (LeadEvidence $item): string => $item->getKey().':'.($item->is_disputed ? '1' : '0'))
            ->sort()
            ->values()
            ->implode('|');

        return sha1($parts);
    }
}
