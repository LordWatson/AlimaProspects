<?php

declare(strict_types=1);

namespace App\Modules\Signals\Contracts;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Data\PlaybookDraftOutcome;
use App\Modules\Signals\Services\NoOutreachDrafting;

/**
 * How a playbook prepares an approach without Signals knowing anything about Outreach (S14).
 *
 * **The contract is declared here and bound there**, which is the arrangement `CLAUDE.md` §3
 * describes for Compliance's three seams and ADR 0023 describes for signal sources. Signals is the
 * consumer, so the interface is Signals' own and the module ships a default; Outreach's provider
 * binds the real implementation over it. Nothing in `App\Modules\Signals` imports
 * `App\Modules\Outreach` — the three-contracts rule cuts both ways.
 *
 * **A playbook can prepare an approach. It cannot send one** (docs/compliance.md §3.12). There is
 * no `send` on this interface and there will not be one: `OutreachGuard` answers whether a prospect
 * may be approached, suppression and sender identification and the opt-out are conditions of
 * sending, and a UAE-jurisdiction signal carries a prior-consent restriction nothing published can
 * satisfy. An unattended rule that transmitted would be the platform deciding a legal question on
 * a team's behalf, overnight, with nobody reading it.
 *
 * The two option methods exist so the builder can offer channels and registers without Signals
 * learning Outreach's enums. They are also what the save path validates against — a rule naming a
 * channel the deployment does not have is refused at the form rather than at three in the morning.
 *
 * The default binding is {@see NoOutreachDrafting}, and it is **conservative rather than
 * permissive**: with no drafting module bound, a rule's drafting step is unavailable and says so.
 * The opposite default — silently writing nothing and reporting success — is the one that would
 * hide a misconfiguration for a month.
 */
interface SignalOutreachDrafter
{
    /**
     * The channels a draft may be written for, in the order to offer them.
     *
     * @return list<array{value: string, label: string, description: string}> Labels are translation
     *                                                                        keys, not sentences.
     */
    public function channels(): array;

    /**
     * The registers a draft may be written in.
     *
     * @return list<array{value: string, label: string}>
     */
    public function tones(): array;

    /**
     * Write a draft for this lead and keep it for a person to read.
     *
     * Must be called inside the lead's team context, and **outside any transaction**: the
     * implementation spends AI credit on a model call (CLAUDE.md external-call rule).
     *
     * @param  User  $actor  Whose authority the draft is written under, for the audit trail.
     */
    public function draftForReview(
        Team $team,
        Lead $lead,
        string $channel,
        string $tone,
        User $actor,
    ): PlaybookDraftOutcome;
}
