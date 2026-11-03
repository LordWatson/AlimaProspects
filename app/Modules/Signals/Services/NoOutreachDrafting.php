<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Contracts\SignalOutreachDrafter;
use App\Modules\Signals\Data\PlaybookDraftOutcome;

/**
 * Signals' own answer when nothing writes drafts (S14): there is no drafting here.
 *
 * The module's default binding for {@see SignalOutreachDrafter}, so Signals stands up without
 * Outreach — the same arrangement `RecordedLeadRestriction` gives Outreach without Compliance, and
 * `AlwaysAllowTeamSending` gives it without Admin.
 *
 * It is the one of those defaults that is **conservative rather than permissive**, and the
 * difference is worth being explicit about. A permissive default makes sense where it keeps a
 * module working for a rule nobody has applied; here the question is "may this prospect be
 * approached", and a default that answered yes would be Signals deciding a compliance question
 * Outreach exists to decide. So the builder offers no channels — which stops the step being
 * configurable at all — and a step stored by a build that had Outreach reports `unavailable`
 * rather than quietly reporting success.
 */
final class NoOutreachDrafting implements SignalOutreachDrafter
{
    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public function channels(): array
    {
        return [];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function tones(): array
    {
        return [];
    }

    public function draftForReview(
        Team $team,
        Lead $lead,
        string $channel,
        string $tone,
        User $actor,
    ): PlaybookDraftOutcome {
        return PlaybookDraftOutcome::unavailable();
    }
}
