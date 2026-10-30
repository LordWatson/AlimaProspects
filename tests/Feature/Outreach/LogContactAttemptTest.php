<?php

declare(strict_types=1);

use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Actions\LogContactAttemptAction;
use App\Modules\Outreach\Data\LogAttemptData;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Logging an approach, and what an outcome does to the do-not-contact list (Phase 11).
 *
 * The case this file exists for is the opt-out: a member telling us somebody asked to be left
 * alone must suppress that identity **immediately and globally**, in the same transaction, across
 * every channel we hold for them. Everything else here is the surrounding bookkeeping.
 */
function logData(
    OutreachChannel $channel = OutreachChannel::Call,
    ContactOutcome $outcome = ContactOutcome::Pending,
    ?int $contactId = null,
    ?int $messageId = null,
    ?string $notes = null,
    ?string $occurredAt = null,
): LogAttemptData {
    return new LogAttemptData(
        channel: $channel,
        outcome: $outcome,
        occurredAt: $occurredAt === null
            ? now()->utc()->toDateTimeImmutable()
            : Date::parse($occurredAt)->utc()->toDateTimeImmutable(),
        leadContactId: $contactId,
        messageId: $messageId,
        notes: $notes,
    );
}

// --- The log --------------------------------------------------------------------------------------

it('logs an approach the member made themselves', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $attempt = app(TeamContext::class)->run($team, fn (): ContactAttempt => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            channel: OutreachChannel::Call,
            outcome: ContactOutcome::NoAnswer,
            contactId: (int) $contact->getKey(),
            notes: 'Left a voicemail.',
            occurredAt: '2026-10-28 09:30:00',
        )));

    expect($attempt->channel)->toBe(OutreachChannel::Call)
        ->and($attempt->outcome)->toBe(ContactOutcome::NoAnswer)
        ->and($attempt->notes)->toBe('Left a voicemail.')
        // When it happened, not when it was typed — a member logs yesterday's call today.
        ->and($attempt->occurred_at->toDateTimeString())->toBe('2026-10-28 09:30:00');

    $activity = LeadActivity::query()->where('lead_id', $lead->getKey())->sole();

    expect($activity->type)->toBe(LeadActivityType::Contacted)
        ->and($activity->properties['channel'])->toBe('call')
        // The member did this, not Alima.
        ->and($activity->properties['sent'])->toBeFalse();
});

it('marks the draft it was made with as used', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()
        ->channel(OutreachChannel::LinkedIn)
        ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]));

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            channel: OutreachChannel::LinkedIn,
            contactId: (int) $contact->getKey(),
            messageId: (int) $message->getKey(),
        )));

    expect($message->fresh()->status)->toBe(OutreachMessageStatus::Logged);
});

it('does not downgrade a sent email to logged when a follow-up call is logged against it', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()
        ->sent()
        ->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey()]));

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            channel: OutreachChannel::Call,
            contactId: (int) $contact->getKey(),
            messageId: (int) $message->getKey(),
        )));

    // Alima transmitting it is a stronger statement than a member saying they did.
    expect($message->fresh()->status)->toBe(OutreachMessageStatus::Sent);
});

it('updates the one timeline entry when the outcome arrives days later', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $attempt = app(TeamContext::class)->run($team, fn (): ContactAttempt => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(contactId: (int) $contact->getKey())));

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->updateOutcome($team, $attempt->load(['lead.team', 'contact', 'message']), $user, ContactOutcome::MeetingBooked));

    // Subjected to the attempt, so the history reads as one event with an answer, not two events.
    $activities = LeadActivity::query()->where('lead_id', $lead->getKey())->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()->properties['outcome'])->toBe('meeting_booked')
        ->and($attempt->fresh()->outcome)->toBe(ContactOutcome::MeetingBooked);
});

// --- The opt-out ----------------------------------------------------------------------------------

it('suppresses the contact globally, across every channel, the moment an opt-out is logged', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test', contactPhone: '+44 20 7946 0000');

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            channel: OutreachChannel::Call,
            outcome: ContactOutcome::OptedOut,
            contactId: (int) $contact->getKey(),
        )));

    $entries = OutreachSuppression::query()->get();

    // Both identities: somebody who says "stop contacting me" on a call has not given permission
    // to email them instead.
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('scope')->unique()->all())->toBe([SuppressionScope::Global])
        ->and($entries->pluck('reason')->unique()->all())->toBe([SuppressionReason::OptOut])
        ->and($entries->pluck('type')->map->value->sort()->values()->all())->toBe(['email', 'phone'])
        // A global entry belongs to the platform, not to whoever happened to hear it.
        ->and($entries->pluck('team_id')->unique()->all())->toBe([null]);

    // And it is honoured, by this team and by every other.
    [, $otherTeam] = Fixtures::team('Other Co');

    expect(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $team->getKey()))->toBeTrue()
        ->and(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $otherTeam->getKey()))->toBeTrue()
        ->and(app(SuppressionList::class)->isPhoneSuppressed('00442079460000', (int) $team->getKey()))->toBeTrue();
});

it('suppresses only the address, only for this team, on a bounce', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test', contactPhone: '+44 20 7946 0000');

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            channel: OutreachChannel::Email,
            outcome: ContactOutcome::Bounced,
            contactId: (int) $contact->getKey(),
        )));

    $entry = OutreachSuppression::query()->sole();

    // A dead address is a fact about the address, and only this team knows it. The phone number is
    // untouched — a bounce says nothing about whether they want to be called.
    expect($entry->type)->toBe(SuppressionType::Email)
        ->and($entry->scope)->toBe(SuppressionScope::Team)
        ->and($entry->reason)->toBe(SuppressionReason::Bounce)
        ->and($entry->team_id)->toBe($team->getKey());

    [, $otherTeam] = Fixtures::team('Other Co');

    expect(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $otherTeam->getKey()))->toBeFalse();
});

it('suppresses when the outcome is changed to an opt-out later, not only when it is logged as one', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    $attempt = app(TeamContext::class)->run($team, fn (): ContactAttempt => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(contactId: (int) $contact->getKey())));

    expect(OutreachSuppression::query()->count())->toBe(0);

    // The usual shape of a real opt-out: the reply comes days after the approach.
    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->updateOutcome($team, $attempt->load(['lead.team', 'contact', 'message']), $user, ContactOutcome::OptedOut));

    expect(OutreachSuppression::query()->where('type', 'email')->exists())->toBeTrue();
});

it('stores only a masked preview of a suppressed address', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            outcome: ContactOutcome::OptedOut,
            contactId: (int) $contact->getKey(),
        )));

    $entry = OutreachSuppression::query()->where('type', 'email')->sole();

    // A register of people who want nothing to do with us is not somewhere to keep their addresses
    // in the clear (docs/compliance.md §3.9).
    expect($entry->value_preview)->toBe('d***@acme.test')
        ->and($entry->getAttributes())->not->toHaveKey('value')
        ->and($entry->value_hash)->toBe(hash('sha256', 'dana@acme.test'));
});

it('is idempotent — the same opt-out heard twice is one entry', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    foreach (range(1, 2) as $ignored) {
        app(TeamContext::class)->run($team, fn () => app(LogContactAttemptAction::class)
            ->handle($team, $lead, $user, logData(
                outcome: ContactOutcome::OptedOut,
                contactId: (int) $contact->getKey(),
            )));
    }

    expect(OutreachSuppression::query()->count())->toBe(1)
        ->and(ContactAttempt::query()->count())->toBe(2);
});

it('does not fall over when an opt-out is logged with no contact identified', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team, contactEmail: null);

    $attempt = app(TeamContext::class)->run($team, fn (): ContactAttempt => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(outcome: ContactOutcome::OptedOut)));

    // The attempt is recorded; there is simply nothing identified to suppress.
    expect($attempt->outcome)->toBe(ContactOutcome::OptedOut)
        ->and(OutreachSuppression::query()->count())->toBe(0);
});

// --- Tenancy ---------------------------------------------------------------------------------------

it('ignores a contact and a message belonging to another team', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead] = Fixtures::lead($team, contactEmail: null);

    [, $otherTeam] = Fixtures::team('Other Co');
    [$otherLead, $otherContact] = Fixtures::lead($otherTeam);
    $otherMessage = app(TeamContext::class)->run($otherTeam, fn (): OutreachMessage => OutreachMessage::factory()
        ->create(['team_id' => $otherTeam->getKey(), 'lead_id' => $otherLead->getKey()]));

    $attempt = app(TeamContext::class)->run($team, fn (): ContactAttempt => app(LogContactAttemptAction::class)
        ->handle($team, $lead, $user, logData(
            contactId: (int) $otherContact->getKey(),
            messageId: (int) $otherMessage->getKey(),
        )));

    expect($attempt->lead_contact_id)->toBeNull()
        ->and($attempt->outreach_message_id)->toBeNull()
        ->and($otherMessage->fresh()->status)->toBe(OutreachMessageStatus::Draft);
});

it('lets a plain member log an approach and refuses a read-only one', function (): void {
    [, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $member = Fixtures::member($team, TeamRole::Member);
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($member)
        ->post(route('outreach.log', $lead->getKey()), [
            'channel' => 'call',
            'outcome' => 'no_answer',
            'lead_contact_id' => $contact->getKey(),
        ])
        ->assertRedirect();

    expect(ContactAttempt::query()->count())->toBe(1);

    $this->actingAs($readOnly)
        ->post(route('outreach.log', $lead->getKey()), [
            'channel' => 'call',
            'lead_contact_id' => $contact->getKey(),
        ])
        ->assertForbidden();

    expect(ContactAttempt::query()->count())->toBe(1);
});
