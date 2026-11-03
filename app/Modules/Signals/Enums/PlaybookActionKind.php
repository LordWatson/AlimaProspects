<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * What one step of a playbook does (S14).
 *
 * An enum rather than a row, and ADR 0018 §1's test cuts that way cleanly: **a class exists per
 * case.** Each kind is a handler that calls the owning module's Action — Leads Workspace's
 * assignment, its stage move, its tagging, its tasks; Signals' own conversion; Outreach's drafting
 * through a contract; the notification dispatcher — so the application branches on this value and
 * it is a closed set. A signal *type* is a row because nothing branches on which type it is; a
 * playbook action is an enum because everything does.
 *
 * Three properties each case has to answer, because each is a brake somewhere else:
 *
 * - {@see self::needsLead()} — whether the step is about a lead. A signal with no lead cannot be
 *   assigned, staged, tagged, tasked or drafted for, so those steps are skipped with a reason
 *   rather than failing the run. Putting a conversion first in the chain is what supplies one.
 * - {@see self::spends()} — whether performing it costs the team money. The two that do are capped
 *   per job independently of everything else (`signals.playbooks.max_conversions_per_run`,
 *   `max_drafts_per_run`).
 * - {@see self::isTerminalForOutreach()} — whether the step is the one that may be refused by the
 *   outreach guard. Exactly one is, and naming it here is what keeps the refusal a first-class
 *   recorded outcome rather than a special case buried in a handler.
 *
 * Values are stable storage keys — they are written into `signal_playbooks.actions` and into every
 * `signal_playbook_runs.results` row already stored, so renaming one orphans a team's rules and
 * their history. Labels are translation keys the screens pass through `t()` (EN/AR, ADR 0007).
 */
enum PlaybookActionKind: string
{
    /** Make a lead from the signal, or fill in the company the team already has. */
    case ConvertToLead = 'convert_to_lead';

    /**
     * Attach the signal to the lead it is **about** (`signals.matched_lead_id`, S10) rather than
     * making a new one. Distinct from {@see self::ConvertToLead} because the question it answers is
     * different: this one declines when there is no match, where conversion would invent a lead.
     */
    case AttachToMatchedLead = 'attach_to_matched_lead';

    /** Put the lead in somebody's hands. */
    case AssignToMember = 'assign_to_member';

    /** Move the lead to a column of the board. */
    case MoveToPipelineStage = 'move_to_pipeline_stage';

    /** Label the lead. */
    case AddTag = 'add_tag';

    /** Tell somebody. Aggregated per recipient per job — never one notification per signal. */
    case Notify = 'notify';

    /**
     * Write an approach **for review**. It is never sent: `OutreachGuard` answers whether a
     * prospect may be approached, and a UAE-jurisdiction signal carries a prior-consent restriction
     * that nothing published can satisfy (docs/compliance.md §3.12). A refusal is recorded on the
     * run with its reason and shown in the log.
     */
    case DraftOutreach = 'draft_outreach';

    /** Put a job on somebody's list. */
    case CreateTask = 'create_task';

    public function label(): string
    {
        return match ($this) {
            self::ConvertToLead => 'Make it a lead',
            self::AttachToMatchedLead => 'Add it to the lead it is about',
            self::AssignToMember => 'Assign the lead',
            self::MoveToPipelineStage => 'Move it to a stage',
            self::AddTag => 'Tag the lead',
            self::Notify => 'Tell someone',
            self::DraftOutreach => 'Draft an approach for review',
            self::CreateTask => 'Create a task',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::ConvertToLead => 'Creates the lead, or fills in the company you already have.',
            self::AttachToMatchedLead => 'Only when Alima matched the event to one of your leads. Nothing new is created.',
            self::AssignToMember => 'Whoever you name, or the person already working the lead.',
            self::MoveToPipelineStage => 'One of your own columns.',
            self::AddTag => 'Up to three labels, created on first use.',
            self::Notify => 'One message per person per check, however many signals matched.',
            self::DraftOutreach => 'Written and kept as a draft for a person to read. Alima never sends it.',
            self::CreateTask => 'With a due date counted in days from the event.',
        };
    }

    /**
     * Whether this step needs a lead to act on, so a signal that has none skips it with a reason.
     */
    public function needsLead(): bool
    {
        return match ($this) {
            self::ConvertToLead, self::AttachToMatchedLead, self::Notify => false,
            self::AssignToMember, self::MoveToPipelineStage, self::AddTag,
            self::DraftOutreach, self::CreateTask => true,
        };
    }

    /**
     * Whether performing this step spends the team's money, and so meets its own per-job cap.
     */
    public function spends(): bool
    {
        return match ($this) {
            self::ConvertToLead, self::AttachToMatchedLead, self::DraftOutreach => true,
            self::AssignToMember, self::MoveToPipelineStage, self::AddTag,
            self::Notify, self::CreateTask => false,
        };
    }

    /**
     * Whether this is the step the outreach guard may refuse. Exactly one is.
     */
    public function isTerminalForOutreach(): bool
    {
        return $this === self::DraftOutreach;
    }

    /**
     * Whether a step of this kind can usefully appear twice in one playbook.
     *
     * Only tagging and tasks can: "tag it Hot and tag it Acquisition" and "chase Monday, review
     * Friday" are both real. Converting twice, assigning twice or drafting twice are a member
     * having made a mistake in the builder, and saying so there is kinder than letting the second
     * one silently win.
     */
    public function isRepeatable(): bool
    {
        return match ($this) {
            self::AddTag, self::CreateTask => true,
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $kind): string => $kind->value, self::cases());
    }
}
