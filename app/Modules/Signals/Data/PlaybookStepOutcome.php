<?php

declare(strict_types=1);

namespace App\Modules\Signals\Data;

use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookActionStatus;
use App\Modules\Signals\Enums\PlaybookOutcomeReason;

/**
 * What became of one step, on its way into `signal_playbook_runs.results` and back out again (S14).
 *
 * `reason` is a **key**, never a sentence. The run log is an EN/AR screen like every other
 * (ADR 0007), and a stored sentence would be in whichever language the queue worker happened to be
 * running in — which for a scheduled watch is nobody's. `params` carries the values the sentence is
 * composed from (a count, a stage name, a block reason's own key), so the log can say "refused:
 * this prospect has opted out" in the reader's language from a row written weeks ago.
 *
 * `lead` is not stored. It is how a conversion step hands the lead it produced to the steps after
 * it, which is the whole reason a playbook's actions are ordered — put the conversion first and the
 * assignment, the stage move and the draft all have something to act on.
 */
final readonly class PlaybookStepOutcome
{
    /**
     * @param  array<string, mixed>  $params  Values the screen composes its sentence from.
     * @param  Lead|null  $lead  The lead this step produced, for the steps after it. Never stored.
     */
    private function __construct(
        public PlaybookActionKind $kind,
        public PlaybookActionStatus $status,
        public ?PlaybookOutcomeReason $reason = null,
        public array $params = [],
        public ?Lead $lead = null,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     */
    public static function applied(PlaybookActionKind $kind, array $params = [], ?Lead $lead = null): self
    {
        return new self($kind, PlaybookActionStatus::Applied, null, $params, $lead);
    }

    /**
     * A rehearsal. Carries no lead, because nothing was created — which is why the steps after a
     * simulated conversion report "no lead to act on" rather than pretending.
     *
     * @param  array<string, mixed>  $params
     */
    public static function simulated(PlaybookActionKind $kind, array $params = []): self
    {
        return new self($kind, PlaybookActionStatus::Simulated, null, $params);
    }

    /**
     * A rule said no — the outreach guard, a per-job spend cap, a missing actor. The one status the
     * log surfaces on its own, because it is the one somebody has to be told about.
     *
     * @param  array<string, mixed>  $params
     */
    public static function refused(PlaybookActionKind $kind, PlaybookOutcomeReason $reason, array $params = []): self
    {
        return new self($kind, PlaybookActionStatus::Refused, $reason, $params);
    }

    /**
     * The step did not apply: no lead to act on, no match to attach to, a stage that belongs to
     * nobody. Not a failure, and nothing to retry.
     *
     * @param  array<string, mixed>  $params
     */
    public static function skipped(
        PlaybookActionKind $kind,
        PlaybookOutcomeReason $reason,
        array $params = [],
        ?Lead $lead = null,
    ): self {
        // A skip may still carry a lead, and `already_converted` is why: the conversion step found
        // the signal was a lead already, which is not something it did — but the steps after it
        // need that lead, and reporting "no lead to act on" three lines later would be a lie about
        // our own log.
        return new self($kind, PlaybookActionStatus::Skipped, $reason, $params, $lead);
    }

    /**
     * The module's own Action threw. The one status that means somebody should look at this.
     *
     * @param  array<string, mixed>  $params
     */
    public static function failed(PlaybookActionKind $kind, PlaybookOutcomeReason $reason, array $params = []): self
    {
        return new self($kind, PlaybookActionStatus::Failed, $reason, $params);
    }

    /**
     * Read an outcome back out of a stored row, or null when this build knows neither its kind nor
     * its status — the same forgiving read {@see SignalPlaybookStep::fromStored()} does, for the
     * same rolled-back-deploy reason.
     *
     * @param  array<string, mixed>  $stored
     */
    public static function fromStored(array $stored): ?self
    {
        $kind = PlaybookActionKind::tryFrom(is_string($stored['kind'] ?? null) ? $stored['kind'] : '');
        $status = PlaybookActionStatus::tryFrom(is_string($stored['status'] ?? null) ? $stored['status'] : '');

        if ($kind === null || $status === null) {
            return null;
        }

        $params = $stored['params'] ?? [];

        return new self(
            $kind,
            $status,
            PlaybookOutcomeReason::tryFrom(is_string($stored['reason'] ?? null) ? $stored['reason'] : ''),
            is_array($params) ? $params : [],
        );
    }

    /**
     * @return array{kind: string, status: string, reason: string|null, params: array<string, mixed>}
     *
     * @see PlaybookOutcomeReason for the stable reason keys this writes.
     */
    public function toStored(): array
    {
        return [
            'kind' => $this->kind->value,
            'status' => $this->status->value,
            'reason' => $this->reason?->value,
            'params' => $this->params,
        ];
    }

    /**
     * The shape the log screen and the API read. Labels are translation keys the client passes
     * through `t()`.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        return [
            'kind' => $this->kind->value,
            'kind_label' => $this->kind->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'needs_attention' => $this->status->needsAttention(),
            'reason' => $this->reason?->value,
            'reason_label' => $this->reason?->label(),
            'reason_guidance' => ($this->reason?->guidance() ?? '') === '' ? null : $this->reason?->guidance(),
            'params' => $this->params,
        ];
    }
}
