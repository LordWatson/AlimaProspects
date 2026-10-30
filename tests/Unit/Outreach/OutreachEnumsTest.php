<?php

declare(strict_types=1);

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionScope;

/**
 * The Outreach enums' behaviour, which several compliance decisions hang off directly.
 *
 * These are the cheapest tests in the module and they protect the most: every `match` here is
 * exhaustive, so a case added later cannot compile without answering the question — and these
 * assertions are what catch it answering wrongly.
 */
it('separates direct marketing from a member\'s own act', function (): void {
    // The distinction suppression, sender identification and the UAE restriction all branch on.
    expect(OutreachChannel::Email->isDirectMarketing())->toBeTrue()
        ->and(OutreachChannel::LinkedIn->isDirectMarketing())->toBeFalse()
        ->and(OutreachChannel::Call->isDirectMarketing())->toBeFalse();
});

it('only transmits on email', function (): void {
    expect(OutreachChannel::Email->isSendable())->toBeTrue()
        ->and(OutreachChannel::Email->hasSubject())->toBeTrue()
        ->and(OutreachChannel::LinkedIn->isSendable())->toBeFalse()
        ->and(OutreachChannel::LinkedIn->hasSubject())->toBeFalse()
        ->and(OutreachChannel::Call->isSendable())->toBeFalse();
});

it('offers the permitted channels under a restriction', function (): void {
    expect(OutreachChannel::permittedUnder(null))
        ->toBe([OutreachChannel::Email, OutreachChannel::LinkedIn, OutreachChannel::Call])
        ->and(OutreachChannel::permittedUnder(OutreachRestriction::UaePriorConsent))
        ->toBe([OutreachChannel::LinkedIn, OutreachChannel::Call]);
});

it('knows which outcomes stop us contacting someone, and how widely', function (): void {
    // An opt-out is the person's own instruction, so it binds every team.
    expect(ContactOutcome::OptedOut->suppressionScope())->toBe(SuppressionScope::Global)
        // A dead address is a fact only this team knows.
        ->and(ContactOutcome::Bounced->suppressionScope())->toBe(SuppressionScope::Team)
        ->and(ContactOutcome::NoAnswer->suppressionScope())->toBeNull()
        ->and(ContactOutcome::NotInterested->suppressionScope())->toBeNull()
        ->and(ContactOutcome::MeetingBooked->suppressionScope())->toBeNull();
});

it('treats a sent and a logged message as gone, and a failed one as not', function (): void {
    expect(OutreachMessageStatus::Sent->hasLeftTheBuilding())->toBeTrue()
        ->and(OutreachMessageStatus::Logged->hasLeftTheBuilding())->toBeTrue()
        ->and(OutreachMessageStatus::Draft->hasLeftTheBuilding())->toBeFalse()
        // Not spent — the member can fix the problem and send the same draft again.
        ->and(OutreachMessageStatus::Failed->hasLeftTheBuilding())->toBeFalse();
});

it('tells a compliance refusal from a fixable one', function (): void {
    expect(OutreachBlockReason::Restricted->isCompliance())->toBeTrue()
        ->and(OutreachBlockReason::Suppressed->isCompliance())->toBeTrue()
        ->and(OutreachBlockReason::NoContactPoint->isCompliance())->toBeFalse()
        ->and(OutreachBlockReason::NoSenderIdentity->isCompliance())->toBeFalse()
        ->and(OutreachBlockReason::SendingDisabled->isCompliance())->toBeFalse();
});

it('only lets a team manage its own scope', function (): void {
    expect(SuppressionScope::Team->isTeamManaged())->toBeTrue()
        ->and(SuppressionScope::Global->isTeamManaged())->toBeFalse()
        // The `scope_key` column: a plain comparable string, so uniqueness can be an ordinary
        // unique index despite `team_id` being nullable.
        ->and(SuppressionScope::Global->keyFor(null))->toBe('global')
        ->and(SuppressionScope::Team->keyFor(17))->toBe('team:17');
});
