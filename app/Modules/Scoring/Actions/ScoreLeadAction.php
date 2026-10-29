<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\ICP\Actions\FindActiveIcpAction;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Data\DeterministicScore;
use App\Modules\Scoring\Data\QualitativeFit;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Services\DeterministicScorer;
use App\Modules\Scoring\Services\ScoringPayloadBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scores one {@see Lead} against an {@see Icp} (Phase 9) with the hybrid method: the
 * {@see DeterministicScorer}'s weighted rule pass **plus** an AI qualitative assessment
 * (`scoring.qualitative_fit`, reasoning tier), blended into one 0–100 score, graded A–D, and stored
 * with its per-criterion breakdown and a grounded explanation.
 *
 * Behaviour that matters:
 *
 * - **The AI call runs outside any transaction** (CLAUDE.md external-call rule); the writes run in
 *   one short transaction, keyed on (lead, ICP) so a re-score updates the row in place and the
 *   member's feedback on it survives.
 * - **Idempotent.** A score already newer than its inputs (the lead's data and the ICP) is returned
 *   untouched unless `$force` is set — so the re-score triggers can fan out freely without
 *   re-spending AI credit.
 * - **A matched exclusion short-circuits.** Grade D, no AI call at all.
 * - **It degrades rather than fails.** If the AI half is unavailable (credit exhausted or
 *   un-validatable output) the rules carry the score and the explanation is simply absent; if the
 *   rules have nothing to assess, the AI carries it. Only when *neither* half has anything to say
 *   is no score written — a lead is never branded "D" out of ignorance.
 */
final class ScoreLeadAction
{
    private const string PROMPT_KEY = 'scoring.qualitative_fit';

    public function __construct(
        private readonly AiClient $ai,
        private readonly DeterministicScorer $scorer,
        private readonly ScoringPayloadBuilder $payload,
        private readonly FindActiveIcpAction $findActiveIcp,
    ) {}

    /**
     * Score the lead against the given ICP, or the team's active one. Returns the stored score, or
     * null when there is nothing to score against or nothing to go on.
     *
     * Must be called inside the lead's team context (the job and controllers guarantee it).
     */
    public function execute(Lead $lead, ?Icp $icp = null, bool $force = false): ?LeadScore
    {
        $icp ??= $this->findActiveIcp->handle((int) $lead->team_id);

        if (! $icp instanceof Icp) {
            Log::channel('lead_sourcing')->info('Lead not scored: the team has no active ICP.', [
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
            ]);

            return null;
        }

        $existing = $this->existingScore($lead, $icp);

        if (! $force && $existing instanceof LeadScore && $existing->isFreshFor($lead, $icp)) {
            return $existing; // already scored against these exact inputs — no AI spend
        }

        $deterministic = $this->scorer->score($lead, $icp);

        // Nothing the rules could assess AND no enriched data for the AI to reason about: refuse
        // before spending anything. A grade off a bare company name would be a guess wearing a
        // number, and the lead will be scored as soon as enrichment gives it something to go on.
        if (! $deterministic->excluded && ! $deterministic->hasSignals() && $lead->enriched_at === null) {
            Log::channel('lead_sourcing')->info('Lead not scored: too little known about it to judge.', [
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                'icp_id' => $icp->getKey(),
            ]);

            return null;
        }

        // --- External work: the qualitative half, outside any transaction ---
        $fit = null;
        $cost = 0;
        $promptVersion = null;
        $model = null;

        if (! $deterministic->excluded) {
            $response = $this->assessQualitatively($lead, $icp);

            if ($response !== null) {
                [$fit, $cost, $promptVersion, $model] = $response;
            }
        }

        if (! $deterministic->excluded && $fit === null) {
            if (! $deterministic->hasSignals()) {
                Log::channel('lead_sourcing')->warning('Lead not scored: neither half of the score was available.', [
                    'team_id' => $lead->team_id,
                    'lead_id' => $lead->getKey(),
                    'icp_id' => $icp->getKey(),
                ]);

                return null;
            }

            // The AI half is down but this lead already holds an AI-backed score. Overwriting it
            // with a rules-only one would replace grounded content with less of it — and a
            // team-wide re-score that ran out of credit part-way would quietly strip every
            // remaining lead's explanation. Leave the score as it stands; the next trigger (or a
            // manual re-score once credit is back) refreshes it, since it is still stale.
            if ($existing instanceof LeadScore && $existing->ai_score !== null) {
                Log::channel('lead_sourcing')->warning('Score left as it stands: the AI half was unavailable for a re-score.', [
                    'team_id' => $lead->team_id,
                    'lead_id' => $lead->getKey(),
                    'icp_id' => $icp->getKey(),
                ]);

                return $existing;
            }
        }

        $score = $this->blend($deterministic, $fit);
        $grade = $deterministic->excluded ? ScoreGrade::D : ScoreGrade::fromScore($score);

        // --- Writes ---
        $leadScore = DB::transaction(function () use (
            $lead, $icp, $deterministic, $fit, $score, $grade, $cost, $promptVersion, $model
        ): LeadScore {
            // Re-read the row under a lock: a forced and an automatic score for the same lead are
            // deliberately distinct queued work, so they can overlap, and the cumulative cost must
            // not lose one of their spends.
            $current = LeadScore::query()
                ->where('lead_id', $lead->getKey())
                ->where('icp_id', $icp->getKey())
                ->lockForUpdate()
                ->first();

            $leadScore = LeadScore::query()->updateOrCreate(
                ['team_id' => $lead->team_id, 'lead_id' => $lead->getKey(), 'icp_id' => $icp->getKey()],
                [
                    'score' => $score,
                    'grade' => $grade,
                    'deterministic_score' => $deterministic->score,
                    'ai_score' => $fit?->score,
                    'breakdown' => $deterministic->breakdown,
                    // An excluded lead gets no prose: the exclusion itself is the explanation, and
                    // inventing a sentence for it would be ungrounded.
                    'explanation' => $fit?->rationale,
                    'strengths' => $fit?->strengths,
                    'concerns' => $fit?->concerns,
                    'excluded' => $deterministic->excluded,
                    'excluded_by' => $deterministic->excludedBy,
                    'prompt_key' => $fit !== null ? self::PROMPT_KEY : null,
                    'prompt_version' => $promptVersion,
                    'model' => $model,
                    // Cumulative: a re-score adds its spend to what this score has already cost.
                    // Per-call detail lives in the AI Platform's usage ledger.
                    'cost_micros' => ($current instanceof LeadScore ? $current->cost_micros : 0) + $cost,
                    'scored_at' => now(),
                ],
            );

            // Advance the pipeline stage, never regress it: later phases add stages beyond Scored
            // (contacted, qualified…) and an automatic re-score must not drag a lead back.
            if (in_array($lead->status, [LeadStatus::Discovered, LeadStatus::Enriched], strict: true)) {
                $lead->forceFill(['status' => LeadStatus::Scored])->save();
            }

            return $leadScore;
        });

        Log::channel('lead_sourcing')->info('Lead scored.', [
            'team_id' => $lead->team_id,
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'score' => $score,
            'grade' => $grade->value,
            'deterministic_score' => $deterministic->score,
            'ai_score' => $fit?->score,
            'excluded' => $deterministic->excluded,
            'cost_micros' => $cost,
        ]);

        return $leadScore;
    }

    /**
     * Run the qualitative prompt. Credit exhaustion and un-validatable output are **not** failures
     * here — the rules can still produce a score — so they are logged and the AI half dropped.
     * Anything else (a transport failure) bubbles so the queue retries it.
     *
     * @return array{QualitativeFit, int, int, string}|null
     */
    private function assessQualitatively(Lead $lead, Icp $icp): ?array
    {
        try {
            $response = $this->ai->structured(self::PROMPT_KEY, [
                'icp' => $this->payload->icpBlock($icp),
                // Provider-sourced and page-extracted facts: data, never instructions.
                'lead' => $this->payload->leadBlock($lead),
            ]);
        } catch (AiCreditExceededException|InvalidAiResponseException $e) {
            $this->logDegraded($lead, $icp, $e);

            return null;
        }

        // The prompt's schema requires a numeric score, but belt and braces: a response without one
        // must count as "no AI half", not as a confident zero dragging the blend down.
        if (! is_numeric($response->data['score'] ?? null)) {
            $this->logDegraded($lead, $icp, InvalidAiResponseException::afterRetries(
                self::PROMPT_KEY,
                1,
                ['score: missing or not numeric'],
            ));

            return null;
        }

        return [
            QualitativeFit::fromAi($response->data),
            $response->costMicros,
            $response->promptVersion,
            $response->model,
        ];
    }

    /**
     * Blend the two halves by the configured weights, dropping whichever half has nothing to say.
     */
    private function blend(DeterministicScore $deterministic, ?QualitativeFit $fit): int
    {
        if ($deterministic->excluded) {
            return 0;
        }

        $deterministicWeight = $deterministic->hasSignals()
            ? max(0.0, (float) config('scoring.blend.deterministic', 0.6))
            : 0.0;

        $qualitativeWeight = $fit !== null
            ? max(0.0, (float) config('scoring.blend.qualitative', 0.4))
            : 0.0;

        $qualitativeScore = $fit !== null ? $fit->score : 0;
        $total = $deterministicWeight + $qualitativeWeight;

        if ($total <= 0.0) {
            // Both weights configured to zero: fall back to whichever half actually ran rather
            // than reporting a meaningless 0.
            return $fit !== null ? $fit->score : $deterministic->score;
        }

        $weighted = $deterministic->score * $deterministicWeight + $qualitativeScore * $qualitativeWeight;

        return max(0, min(100, (int) round($weighted / $total)));
    }

    private function existingScore(Lead $lead, Icp $icp): ?LeadScore
    {
        return LeadScore::query()
            ->where('lead_id', $lead->getKey())
            ->where('icp_id', $icp->getKey())
            ->first();
    }

    private function logDegraded(Lead $lead, Icp $icp, Throwable $exception): void
    {
        Log::channel('ai')->warning('Qualitative fit unavailable; scoring on the rules alone.', [
            'team_id' => $lead->team_id,
            'lead_id' => $lead->getKey(),
            'icp_id' => $icp->getKey(),
            'exception' => $exception->getMessage(),
        ]);
    }
}
