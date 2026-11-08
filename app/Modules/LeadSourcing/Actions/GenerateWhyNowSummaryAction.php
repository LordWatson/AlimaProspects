<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Actions;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiInputTooLargeException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Exceptions\UnknownPromptException;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Models\LeadEvidence;
use App\Modules\LeadSourcing\Services\LeadConfidence;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Write the one or two sentences at the top of a lead: *why this company, why now* (PL1).
 *
 * The rules this operates under are the ones that make an AI sentence safe to put above a
 * prospect's name:
 *
 * - **It may use the supplied evidence and nothing else.** The prompt is given the strongest few
 *   items and told so explicitly, the lead's own name and sector are given separately so the model
 *   is not left guessing at them, and `WhyNowSummaryTest` asserts the model is handed only the
 *   evidence that was selected. The raw evidence is **always shown beside the summary** in the UI,
 *   which is the real protection: a sentence nobody can check is a sentence nobody should trust.
 * - **Every item is wrapped in {@see UntrustedContent}.** A headline is a stranger's prose and an
 *   inbound event's body is a customer's; both arrive at the prompt as data, never as instructions.
 * - **A failure costs the sentence and never the facts.** Credit exhaustion, an unusable response
 *   and an over-long input all leave the lead with its evidence, its confidence and no summary —
 *   the screen falls back to the strongest evidence title, which is what the list showed before
 *   this feature existed. None of the three burns a queue retry.
 * - **The hash gate is the cost control.** The caller only reaches this Action when the evidence
 *   set has changed, and the hash is written here, with the summary, in one write.
 */
final class GenerateWhyNowSummaryAction
{
    public function __construct(
        private readonly AiClient $ai,
        private readonly LeadConfidence $confidence,
    ) {}

    /**
     * Generate and store the lead's summary, returning it — or null when there was nothing to
     * summarise, the feature is switched off, or the call could not be made.
     */
    public function handle(Lead $lead, ?Icp $icp = null): ?string
    {
        if (! (bool) config('evidence.why_now.enabled', true)) {
            return null;
        }

        $lead->loadMissing('evidence');

        $items = $this->strongest($lead);

        if ($items === []) {
            return null;
        }

        $hash = $this->confidence->for($lead)->evidenceHash;

        try {
            $response = $this->ai->structured((string) config('evidence.why_now.prompt_key', 'lead.why_now'), [
                'company' => UntrustedContent::from($lead->company_name),
                'profile' => UntrustedContent::from($this->profileOf($lead)),
                // The team's own words for who they sell to, so the sentence can say why *this*
                // team should care. Untrusted like everything else: it is free text a member typed.
                'icp' => UntrustedContent::from($this->icpLine($icp)),
                'evidence' => UntrustedContent::from($this->render($items)),
                'today' => Carbon::now()->toFormattedDateString(),
            ]);
        } catch (AiCreditExceededException|AiInputTooLargeException|InvalidAiResponseException|UnknownPromptException $exception) {
            // Logged by id, never by content: a lead's evidence is a prospect's data and a log is
            // the one place it would sit unencrypted and unretained (ADR 0017).
            Log::channel('lead_sourcing')->info('Why-now summary skipped.', [
                'team_id' => $lead->team_id,
                'lead_id' => $lead->getKey(),
                'reason' => Str::afterLast($exception::class, '\\'),
            ]);

            return null;
        }

        $summary = trim((string) $response->get('summary', ''));

        if ($summary === '') {
            return null;
        }

        $lead->forceFill([
            'why_now_summary' => Str::limit($summary, 500, ''),
            'why_now_generated_at' => Carbon::now(),
            // Written with the summary, in the same breath. A hash stamped before the call would
            // mark a lead as summarised even when the call failed, and the sentence would never
            // arrive.
            'why_now_evidence_hash' => $hash,
        ])->save();

        return $summary;
    }

    /**
     * The strongest `why_now.max_evidence_items` items, by what they are worth **today** rather
     * than by date.
     *
     * By current weight because that is the ordering the confidence figure was decided by, and a
     * summary built from different evidence than the number beside it is two explanations of one
     * lead. Disputed items are gone already — they are worth nothing — so a flagged fact cannot
     * come back as a sentence.
     *
     * @return list<LeadEvidence>
     */
    private function strongest(Lead $lead): array
    {
        $max = max(1, (int) config('evidence.why_now.max_evidence_items', 5));

        return array_values($lead->evidence
            ->reject(static fn (LeadEvidence $item): bool => $item->is_disputed)
            ->sortByDesc(static fn (LeadEvidence $item): float => $item->currentWeight())
            ->take($max)
            ->all());
    }

    /**
     * The evidence as the prompt sees it: one line each, dated and attributed.
     *
     * The date is spelled out rather than left relative, because the prompt is asked to mention it
     * and "three weeks ago" would be wrong the moment the summary is read back. Nothing from
     * `payload` is included — it is the provider's raw output and can name a person.
     *
     * @param  list<LeadEvidence>  $items
     */
    private function render(array $items): string
    {
        return implode("\n", array_map(static function (LeadEvidence $item): string {
            $date = ($item->observed_at ?? $item->captured_at)->toFormattedDateString();

            return sprintf(
                '- [%s] %s (%s, %s)%s',
                $item->kind->label(),
                $item->title,
                $item->source_label ?? $item->source,
                $date,
                $item->summary === null ? '' : ' — '.$item->summary,
            );
        }, $items));
    }

    /**
     * The team's ideal customer, in one line, or a plain statement that we were not told.
     */
    private function icpLine(?Icp $icp): string
    {
        if (! $icp instanceof Icp) {
            return __('Not stated.');
        }

        return trim($icp->name.'. '.($icp->description ?? ''));
    }

    /**
     * The handful of company facts the sentence is allowed to lean on, so it can say "the London
     * recruitment firm" without inventing either word.
     */
    private function profileOf(Lead $lead): string
    {
        $facts = array_filter([
            $lead->industry,
            $lead->employee_range,
            $lead->locality,
            $lead->country,
        ], static fn (?string $fact): bool => $fact !== null && trim($fact) !== '');

        return $facts === [] ? __('Nothing known beyond the evidence below.') : implode(' · ', $facts);
    }
}
