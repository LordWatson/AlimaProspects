<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

/**
 * Why a playbook step did not simply happen (S14).
 *
 * The closed set of Alima's **own** explanations, so the run log can say something true in the
 * reader's language about a row written weeks ago by a queue worker with no locale. Values are
 * stable storage keys written into `signal_playbook_runs.results`; the labels are translation keys
 * the screens pass through `t()` (EN/AR, ADR 0007).
 *
 * One reason deliberately defers to somebody else: {@see self::OutreachBlocked} carries the guard's
 * own block reason and its guidance in the step's `params`, because *which* rule refused an
 * approach is Outreach's judgement and duplicating its vocabulary here would be a copy that drifts
 * (see `Signals\Contracts\SignalOutreachDrafter`).
 */
enum PlaybookOutcomeReason: string
{
    /** The step is about a lead and the signal has none. Put a conversion before it. */
    case NoLead = 'no_lead';

    /** Nothing to attach to: Alima did not match this event to one of the team's leads. */
    case NoMatch = 'no_match';

    /** Already a lead, from an earlier conversion. The steps after this one still run. */
    case AlreadyConverted = 'already_converted';

    /** The article named a person and no company, so there is nothing to file as a lead. */
    case NoCompanyName = 'no_company_name';

    /** The named stage is not one of this team's columns any more. */
    case StageNotFound = 'stage_not_found';

    /** Nobody the rule named is still a member of this team. */
    case NoMembers = 'no_members';

    /** The step carries no labels to add. */
    case NoTags = 'no_tags';

    /** The step carries no title, so there is no task to create. */
    case NoTitle = 'no_title';

    /**
     * The job's conversion ceiling was reached (`signals.playbooks.max_conversions_per_run`). A
     * refusal rather than a skip: nothing was wrong with the signal, and the team should see that
     * their rules wanted to do more than we let them.
     */
    case ConversionCapReached = 'conversion_cap_reached';

    /** The job's drafting ceiling was reached (`signals.playbooks.max_drafts_per_run`). */
    case DraftCapReached = 'draft_cap_reached';

    /**
     * `OutreachGuard` refused this approach. The step's `params` carry the guard's own reason key,
     * label and guidance, plus whether it was a compliance refusal — which is the half that is
     * final.
     */
    case OutreachBlocked = 'outreach_blocked';

    /**
     * Drafting is not available at all — no sendable channel configured on this deployment, or a
     * channel and register this build does not offer. Distinct from a block: nothing about this
     * prospect refused, so the message to the team is a different one.
     */
    case DraftingUnavailable = 'drafting_unavailable';

    /**
     * The rule has nobody to act as. Its creator has left the team and the team has no owner, so
     * there is no member whose authority the audit trail could name — see `SignalPlaybookActor`.
     */
    case NoActor = 'no_actor';

    /**
     * This step's kind is known but nothing is wired up to perform it — a rolled-back deploy, or a
     * handler withdrawn. Recorded rather than left out of the run, because a step that silently
     * vanished from the log would make the counts lie about what the rule did.
     */
    case StepUnavailable = 'step_unavailable';

    /**
     * The lead already carries as many labels as the workspace allows, so this one was not added.
     * Reported rather than swallowed: `SyncLeadTagsAction` caps a lead at twenty and keeps the
     * existing ones, so recording the step as done would be the log telling a lie.
     */
    case TagLimitReached = 'tag_limit_reached';

    /** The owning module's Action threw. The one reason that means somebody should look. */
    case ActionFailed = 'action_failed';

    public function label(): string
    {
        return match ($this) {
            self::NoLead => 'There was no lead to act on',
            self::NoMatch => 'This event was not about a company you already have',
            self::AlreadyConverted => 'It was already a lead',
            self::NoCompanyName => 'The story named no company',
            self::StageNotFound => 'That stage is no longer on your board',
            self::NoMembers => 'Nobody named is still on the team',
            self::NoTags => 'No labels were set',
            self::NoTitle => 'No task title was set',
            self::ConversionCapReached => 'This check had already made as many leads as we allow at once',
            self::DraftCapReached => 'This check had already written as many drafts as we allow at once',
            self::OutreachBlocked => 'This prospect may not be approached',
            self::DraftingUnavailable => 'Drafting is not available',
            self::NoActor => 'The person who built this rule can no longer act for the team',
            self::StepUnavailable => 'This step is not available in this version of Alima',
            self::TagLimitReached => 'The lead already has as many labels as we allow',
            self::ActionFailed => 'Something went wrong',
        };
    }

    /**
     * What the team can do about it, where there is something. Empty for the ones that are simply
     * the rule working as written.
     */
    public function guidance(): string
    {
        return match ($this) {
            self::NoLead => 'Put “Make it a lead” before this step.',
            self::NoMatch => 'Use “Make it a lead” instead, or narrow the rule to matched accounts.',
            self::StageNotFound => 'Pick a stage that still exists.',
            self::NoMembers => 'Name someone who is still on the team.',
            self::ConversionCapReached, self::DraftCapReached => 'Narrow the rule so it matches fewer signals.',
            self::OutreachBlocked => 'Approach via LinkedIn or a referral instead.',
            self::DraftingUnavailable => 'Check your sender settings, then try the rule again.',
            self::NoActor => 'Rebuild the rule, or ask an owner to.',
            self::StepUnavailable => 'Open the rule and save it again to tidy it up.',
            self::TagLimitReached => 'Remove a label from the lead, or take this step out of the rule.',
            self::ActionFailed => 'Get in touch with us and we will put this right.',
            self::AlreadyConverted, self::NoCompanyName, self::NoTags, self::NoTitle => '',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $reason): string => $reason->value, self::cases());
    }
}
