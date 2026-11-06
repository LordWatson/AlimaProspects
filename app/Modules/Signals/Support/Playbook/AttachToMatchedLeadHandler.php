<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Data\PlaybookStepOutcome;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;
use App\Modules\Signals\Exceptions\SignalsException;

/**
 * "Add it to the lead it is about" — S10's attach, as a rule (S14).
 *
 * The same {@see ConvertSignalToLeadAction} with a named target, which is the arrangement S10
 * settled on: a parallel attach implementation would be a second place for the
 * fill-gaps-never-overwrite rule to live, and the second one would be the one that got it wrong.
 *
 * **Declines where a conversion would invent.** With no `matched_lead_id` there is nothing to attach
 * to, and the honest answer is `no_match` — not "make a lead instead". A rule that silently fell
 * back to conversion would turn "keep me posted about my accounts" into "fill my pipeline with
 * strangers", which is the opposite instruction.
 *
 * It spends — a lead's gaps are filled and the signal is marked converted — so it meets
 * {@see PlaybookBudget} alongside the conversion step. The two share one counter because they are
 * the same Action doing the same amount of work.
 */
final class AttachToMatchedLeadHandler implements PlaybookStepHandler
{
    public function __construct(private readonly ConvertSignalToLeadAction $convert) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::AttachToMatchedLead;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $signal = $context->signal;

        if ($signal->lead_id !== null) {
            $existing = $context->lead();

            return PlaybookStepOutcome::skipped(
                $this->kind(),
                PlaybookOutcomeReason::AlreadyConverted,
                $existing instanceof Lead ? ['lead_id' => (int) $existing->getKey()] : [],
                $existing,
            );
        }

        // Read off the loaded relation: the executor eager-loads `matchedLead`, and a lazy read in
        // a job walking two hundred signals is what `preventLazyLoading` exists to catch.
        $matched = $signal->relationLoaded('matchedLead') ? $signal->matchedLead : null;

        if (! $matched instanceof Lead) {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoMatch);
        }

        if (! $context->performs()) {
            if (! $context->budget->mayConvertInRehearsal()) {
                return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::ConversionCapReached);
            }

            $context->budget->recordRehearsedConversion();

            return PlaybookStepOutcome::simulated($this->kind(), ['lead_id' => (int) $matched->getKey()]);
        }

        if (! $context->budget->mayConvert()) {
            return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::ConversionCapReached);
        }

        $prefill = ConvertSignalData::prefillFrom($signal);

        // **The lead's own name backs up the article's.** A conversion refuses a story that named
        // no company, because it would have to invent the lead; an attach has the lead in hand, so
        // the company name is already known and refusing would be pedantry. This is the one place
        // the two steps genuinely differ in their data rather than only in their target.
        $data = new ConvertSignalData(
            companyName: trim($prefill->companyName) !== ''
                ? $prefill->companyName
                : (string) $matched->company_name,
            website: $prefill->website,
            personName: $prefill->personName,
            personRole: $prefill->personRole,
            locality: $prefill->locality,
            country: $prefill->country,
            note: $prefill->note,
        );

        try {
            $lead = $this->convert->handle($signal, $context->actor, $data, $matched);
        } catch (SignalsException $e) {
            return PlaybookStepOutcome::skipped($this->kind(), ConvertToLeadHandler::reasonFor($e));
        }

        $context->budget->recordConversion();

        return PlaybookStepOutcome::applied(
            $this->kind(),
            ['lead_id' => (int) $lead->getKey()],
            $lead,
        );
    }
}
