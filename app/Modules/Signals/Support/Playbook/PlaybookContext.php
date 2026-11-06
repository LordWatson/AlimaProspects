<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support\Playbook;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalPlaybook;

/**
 * One rule running against one signal: everything a {@see PlaybookStepHandler} is given (S14).
 *
 * The one piece of mutable state is **the lead**, and it is what makes the ordering of a playbook's
 * steps meaningful. A conversion step hands the lead it produced to the context, and the assignment,
 * the stage move, the tag, the task and the draft all read it from there — which is why "Make it a
 * lead" first is the advice the builder gives, and why a rule without it reports "there was no lead
 * to act on" rather than inventing one.
 *
 * It starts out as the lead the signal is already about: a converted signal's own `lead_id`, or the
 * lead S10 matched the event to. So a rule over accounts the team already works needs no conversion
 * step at all, which is the common case and the cheap one.
 *
 * `actor` is a real member, resolved by {@see PlaybookActor} — never null and never a system user.
 * Every module Action the chain calls writes this person into its own audit entry and its own
 * timeline row, so a lead's history reads "Dana moved this to Engaged" with the playbook's run log
 * as the explanation of why. A synthetic actor would make those entries unattributable, and a
 * nullable one would make eight handlers each decide what to do about it.
 */
final class PlaybookContext
{
    public function __construct(
        public readonly Team $team,
        public readonly Signal $signal,
        public readonly SignalPlaybook $playbook,
        public readonly User $actor,
        public readonly PlaybookMode $mode,
        public readonly PlaybookBudget $budget,
        public readonly PlaybookNotices $notices,
        private ?Lead $lead = null,
    ) {}

    /**
     * The lead the chain is acting on, if there is one yet.
     */
    public function lead(): ?Lead
    {
        return $this->lead;
    }

    /**
     * Hand the chain the lead a step produced.
     *
     * Only ever called by the executor, from a step outcome's own `lead` — not by a handler
     * reaching into the context. The difference matters: a handler that set this itself could leave
     * the context pointing at a lead whose step it then reported as failed.
     */
    public function useLead(Lead $lead): void
    {
        $this->lead = $lead;
    }

    /**
     * Whether the steps may actually do anything, or are only being rehearsed.
     *
     * Every handler asks this first. Asked of the mode rather than compared to a case, so a third
     * mode is caught by all eight of them without anyone revisiting eight `match` expressions that
     * still compile.
     */
    public function performs(): bool
    {
        return $this->mode->performs();
    }
}
