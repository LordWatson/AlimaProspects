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
 * "Make it a lead" — the step that supplies the lead everything after it needs (S14).
 *
 * Calls {@see ConvertSignalToLeadAction} with the signal's own prefill, which is the same Action
 * and the same data object the convert form posts. Nothing about an unattended conversion is
 * special: the same dedupe key, the same fill-gaps-never-overwrite merge, the same contact with no
 * address, the same re-asserted outreach restriction, the same post-commit enrichment.
 *
 * Two outcomes that are not failures:
 *
 * - **A signal that is already a lead** reports `already_converted` and still hands the lead on.
 *   The Action returns the existing lead for a repeat, so the honest status is "it wasn't me", and
 *   reporting "no lead to act on" three steps later would be a lie about our own log.
 * - **A story that named no company** reports `no_company_name`. The convert form asks a member in
 *   that case (a relocation signal often names a person and no company); a rule has nobody to ask,
 *   and inventing a holding entity is exactly what `ConvertSignalData` refuses to do.
 *
 * This is one of the two steps that **spends**, so it meets {@see PlaybookBudget} before it runs.
 */
final class ConvertToLeadHandler implements PlaybookStepHandler
{
    public function __construct(private readonly ConvertSignalToLeadAction $convert) {}

    public function kind(): PlaybookActionKind
    {
        return PlaybookActionKind::ConvertToLead;
    }

    public function handle(PlaybookContext $context, SignalPlaybookStep $step): PlaybookStepOutcome
    {
        $signal = $context->signal;

        // Already a lead — from an earlier conversion, a member's own click, or an earlier step of
        // this very chain. Hand it on and say so.
        $existing = $context->lead();

        if ($existing instanceof Lead && $signal->lead_id !== null) {
            return PlaybookStepOutcome::skipped(
                $this->kind(),
                PlaybookOutcomeReason::AlreadyConverted,
                ['lead_id' => (int) $existing->getKey()],
                $existing,
            );
        }

        $prefill = ConvertSignalData::prefillFrom($signal);

        if (trim($prefill->companyName) === '') {
            return PlaybookStepOutcome::skipped($this->kind(), PlaybookOutcomeReason::NoCompanyName);
        }

        if (! $context->performs()) {
            // A rehearsal counts its own hypotheticals, so the log can say where a live rule would
            // have hit the ceiling instead of quietly stopping short of it.
            if (! $context->budget->mayConvertInRehearsal()) {
                return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::ConversionCapReached);
            }

            $context->budget->recordRehearsedConversion();

            return PlaybookStepOutcome::simulated($this->kind(), ['company' => $prefill->companyName]);
        }

        if (! $context->budget->mayConvert()) {
            return PlaybookStepOutcome::refused($this->kind(), PlaybookOutcomeReason::ConversionCapReached);
        }

        try {
            $lead = $this->convert->handle($signal, $context->actor, $prefill);
        } catch (SignalsException $e) {
            // The Action's own refusal, mapped by its **code** rather than all funnelled into one
            // reason: "already converted" and "no company to convert" are different answers, and
            // the log said the second for both (found in the S14 review).
            return PlaybookStepOutcome::skipped($this->kind(), self::reasonFor($e));
        }

        $context->budget->recordConversion();

        return PlaybookStepOutcome::applied(
            $this->kind(),
            ['lead_id' => (int) $lead->getKey()],
            $lead,
        );
    }

    /**
     * The conversion Action's own refusal, as a reason the log can explain.
     *
     * Shared with {@see AttachToMatchedLeadHandler} because both call the same Action and meet the
     * same refusals; a second copy would be the one that fell behind when a refusal was added.
     * Mapped by the exception's **code** rather than its message, which is prose and translated.
     */
    public static function reasonFor(SignalsException $e): PlaybookOutcomeReason
    {
        return $e->errorCode() === 'signals.already_converted'
            ? PlaybookOutcomeReason::AlreadyConverted
            : PlaybookOutcomeReason::NoCompanyName;
    }
}
