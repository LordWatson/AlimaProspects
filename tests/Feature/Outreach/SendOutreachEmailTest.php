<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Outreach\Actions\SendOutreachEmailAction;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\OutreachMessageStatus;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Exceptions\OutreachBlockedException;
use App\Modules\Outreach\Exceptions\OutreachException;
use App\Modules\Outreach\Mail\OutreachMessageMail;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use Database\Factories\OutreachSuppressionFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\Support\OutreachFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Sending a drafted email (Phase 11) — the only thing in the platform that reaches a prospect.
 *
 * The cases that matter: a send is recorded with its provider id and logged as an attempt; a
 * suppression that arrives **after** the draft was written still stops it; a restricted lead is
 * refused; a spent draft can't be sent twice; a provider failure leaves the draft re-sendable; and
 * the sent mail carries sender identification and an opt-out.
 */
beforeEach(function (): void {
    Mail::fake();
    config()->set('outreach.sending_enabled', true);
    config()->set('outreach.from.address', 'outreach@alima.test');
    config()->set('outreach.opt_out.address', 'unsubscribe@alima.test');
});

/**
 * A draft ready to send, for a lead with a contact.
 *
 * @return array{User, Team, OutreachMessage}
 */
function sendableDraft(?string $contactEmail = 'dana@acme.test'): array
{
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team, contactEmail: $contactEmail);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'lead_contact_id' => $contact?->getKey(),
        'created_by' => $user->getKey(),
    ]));

    return [$user, $team, $message];
}

// --- The happy path ------------------------------------------------------------------------------

it('sends the email, records the send, and logs the attempt', function (): void {
    [$user, $team, $message] = sendableDraft();

    $sent = app(TeamContext::class)->run($team, fn (): OutreachMessage => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    Mail::assertSent(OutreachMessageMail::class, fn (OutreachMessageMail $mail): bool => $mail->hasTo('dana@acme.test'));

    expect($sent->status)->toBe(OutreachMessageStatus::Sent)
        // The address used at the time, which is the point of the column.
        ->and($sent->sent_to)->toBe('dana@acme.test')
        ->and($sent->sent_at)->not->toBeNull()
        ->and($sent->error_message)->toBeNull();

    // A sent email leaves exactly the same kind of record as a logged call.
    $attempt = ContactAttempt::query()->where('outreach_message_id', $sent->getKey())->sole();

    expect($attempt->channel)->toBe(OutreachChannel::Email)
        ->and($attempt->outcome)->toBe(ContactOutcome::Pending)
        ->and($attempt->created_by)->toBe($user->getKey());
});

it('puts the approach on the lead timeline, attributed to the member', function (): void {
    [$user, $team, $message] = sendableDraft();

    app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    $activity = LeadActivity::query()->where('lead_id', $message->lead_id)->sole();

    expect($activity->type)->toBe(LeadActivityType::Contacted)
        ->and($activity->user_id)->toBe($user->getKey())
        ->and($activity->properties['channel'])->toBe('email')
        // Alima transmitted this one, which the timeline should not imply of a call.
        ->and($activity->properties['sent'])->toBeTrue();
});

it('writes an audit entry carrying ids only — never the address or the body', function (): void {
    [$user, $team, $message] = sendableDraft();

    app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    $audit = AuditLog::query()->where('action', 'outreach.email_sent')->sole();

    expect($audit->properties)->toHaveKeys(['lead_id', 'lead_contact_id', 'channel', 'locale'])
        ->and(json_encode($audit->properties))->not->toContain('dana@acme.test');
});

// --- The guard, at send time ---------------------------------------------------------------------

it('refuses a send when the contact opted out after the draft was written', function (): void {
    [$user, $team, $message] = sendableDraft();

    // The sequence this test exists for: the draft was allowed, and then they replied "stop".
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'dana@acme.test')
        ->create();

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);

    Mail::assertNothingSent();
    expect($message->fresh()->status)->toBe(OutreachMessageStatus::Draft);
});

it('refuses a send to anyone at a suppressed domain', function (): void {
    [$user, $team, $message] = sendableDraft();

    // "Stop contacting anyone at this company" has to mean that.
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Domain, 'acme.test')
        ->create();

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    Mail::assertNothingSent();
});

it('honours another team\'s global opt-out', function (): void {
    [, $otherTeam] = Fixtures::team('Other Co');
    [$user, $team, $message] = sendableDraft();

    // Written as a global entry by whichever team heard it — and binding on every other
    // (docs/compliance.md §3.2).
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'dana@acme.test')
        ->create(['created_by' => null, 'team_id' => null]);

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    expect($otherTeam->exists)->toBeTrue();
});

it('does not apply one team\'s own list to another team', function (): void {
    [, $otherTeam] = Fixtures::team('Other Co');
    [$user, $team, $message] = sendableDraft();

    // The other team's housekeeping, not a person's wish — so it must not stop us.
    OutreachSuppressionFactory::new()
        ->forValue(SuppressionType::Email, 'dana@acme.test')
        ->forTeam((int) $otherTeam->getKey())
        ->create();

    $sent = app(TeamContext::class)->run($team, fn (): OutreachMessage => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($sent->status)->toBe(OutreachMessageStatus::Sent);
    Mail::assertSent(OutreachMessageMail::class);
});

it('refuses to send to a lead carrying the prior-consent restriction', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::restrictedLead($team, contactEmail: 'dana@acme.test');

    // A draft that predates the restriction, or was written for another channel and switched:
    // either way the send is the moment that counts.
    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'lead_contact_id' => $contact->getKey(),
        'created_by' => $user->getKey(),
    ]));

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    Mail::assertNothingSent();
});

it('refuses to send without a verified sender identity', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    // No business profile, so nothing can identify the sender in the footer.
    [$lead, $contact] = Fixtures::lead($team, contactEmail: 'dana@acme.test');

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'lead_contact_id' => $contact->getKey(),
        'created_by' => $user->getKey(),
    ]));

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    Mail::assertNothingSent();
});

it('refuses to send when sending is switched off for the deployment', function (): void {
    config()->set('outreach.sending_enabled', false);

    [$user, $team, $message] = sendableDraft();

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    Mail::assertNothingSent();
});

it('refuses to send a draft with no recipient', function (): void {
    [$user, $team, $message] = sendableDraft(contactEmail: null);

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachBlockedException::class);
    Mail::assertNothingSent();
});

// --- Idempotency and failure ---------------------------------------------------------------------

it('refuses to send the same draft twice', function (): void {
    [$user, $team, $message] = sendableDraft();

    app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    $again = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->fresh()->load(['lead.team', 'contact']), $user));

    expect($again)->toThrow(OutreachException::class);
    Mail::assertSentCount(1);
});

it('sends once when two requests race for the same draft', function (): void {
    [$user, $team, $message] = sendableDraft();

    // What a double-tapped Send button, or a retried request, actually looks like: two callers
    // each holding a model they read while it still said `draft`. The in-memory `isSpent()` check
    // passes for both, so the only thing standing between the prospect and two identical emails is
    // the conditional claim in the action.
    $first = $message->load(['lead.team', 'contact']);
    $second = OutreachMessage::query()->with(['lead.team', 'contact'])->findOrFail($message->getKey());

    expect($first->isSpent())->toBeFalse()
        ->and($second->isSpent())->toBeFalse();

    app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)->handle($team, $first, $user));

    $loser = fn () => app(TeamContext::class)->run(
        $team,
        fn () => app(SendOutreachEmailAction::class)->handle($team, $second, $user),
    );

    expect($loser)->toThrow(OutreachException::class);

    Mail::assertSentCount(1);
    expect(ContactAttempt::query()->count())->toBe(1)
        ->and($message->fresh()->status)->toBe(OutreachMessageStatus::Sent);
});

it('leaves a claimed-but-unfinished send stranded rather than re-sendable', function (): void {
    [$user, $team, $message] = sendableDraft();

    // A message in `Sending` is a send that went out and whose bookkeeping did not finish. A
    // second email to a prospect is not recoverable; a missing attempt row is, from the log and
    // the provider's dashboard. So it must refuse.
    $message->forceFill(['status' => OutreachMessageStatus::Sending])->save();

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->fresh()->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachException::class);
    Mail::assertNothingSent();
});

it('releases the claim on a provider failure so the same draft can be sent again', function (): void {
    [$user, $team, $message] = sendableDraft();

    Mail::shouldReceive('to')->once()->andThrow(new RuntimeException('Domain not verified.'));

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachException::class);

    // Failed, not stranded: the team fixes the domain and sends the same draft.
    expect($message->fresh()->status)->toBe(OutreachMessageStatus::Failed)
        ->and($message->fresh()->isSpent())->toBeFalse();
});

it('refuses to send a channel Alima does not transmit', function (): void {
    [$user, $team] = Fixtures::team();
    [$lead, $contact] = Fixtures::lead($team);

    $message = app(TeamContext::class)->run($team, fn (): OutreachMessage => OutreachMessage::factory()
        ->channel(OutreachChannel::Call)
        ->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'lead_contact_id' => $contact->getKey(),
            'created_by' => $user->getKey(),
        ]));

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachException::class);
    Mail::assertNothingSent();
});

it('leaves a failed send re-sendable, with the provider\'s reason on the row', function (): void {
    [$user, $team, $message] = sendableDraft();

    // The realistic failure: the sending domain isn't verified yet.
    Mail::shouldReceive('to')->andThrow(new RuntimeException('Domain alima.test is not verified.'));

    $attempt = fn () => app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    expect($attempt)->toThrow(OutreachException::class);

    $failed = $message->fresh();

    expect($failed->status)->toBe(OutreachMessageStatus::Failed)
        ->and($failed->error_message)->toContain('not verified')
        // Not spent — the member can fix the domain and try the same draft again.
        ->and($failed->isSpent())->toBeFalse()
        ->and(ContactAttempt::query()->count())->toBe(0);
});

// --- What actually goes out ----------------------------------------------------------------------

it('identifies the sender and offers an unambiguous opt-out', function (): void {
    [$user, $team, $message] = sendableDraft();

    app(TeamContext::class)->run($team, fn () => app(SendOutreachEmailAction::class)
        ->handle($team, $message->load(['lead.team', 'contact']), $user));

    Mail::assertSent(OutreachMessageMail::class, function (OutreachMessageMail $mail) use ($user): bool {
        $rendered = $mail->render();

        // Sender identification: the team is named, and so is the platform that transmitted it
        // (docs/compliance.md §3.3, UAE TDRA).
        expect($rendered)->toContain('Alima Test Co')
            ->and($rendered)->toContain('Sent via Alima on behalf of')
            // An unambiguous opt-out. Since Phase 15 the footer's link is the **hosted** one-click
            // endpoint (docs/compliance.md §3.3) rather than a `mailto:`, so the thing to assert is
            // that a signed unsubscribe URL is in the body — the `mailto:` route survives in the
            // header, which the headers assertion below covers.
            ->and($rendered)->toContain('/unsubscribe/')
            ->and($rendered)->toContain('signature=');

        $headers = $mail->headers();

        // Both routes offered, HTTPS first, with `List-Unsubscribe-Post` beside it — which is what
        // makes a mail client's own one-click button work (RFC 8058).
        expect($headers->text['List-Unsubscribe'] ?? '')->toContain('/unsubscribe/')
            ->and($headers->text['List-Unsubscribe'] ?? '')->toContain('mailto:unsubscribe@alima.test')
            ->and($headers->text['List-Unsubscribe-Post'] ?? '')->toBe('List-Unsubscribe=One-Click');

        $envelope = $mail->envelope();

        return $envelope->from->address === 'outreach@alima.test'
            // "Acme Ltd via Alima" — both parties named.
            && str_contains((string) $envelope->from->name, 'Alima Test Co')
            // A reply reaches the member, which is the opt-out route the footer offers.
            && $envelope->replyTo[0]->address === $user->email;
    });
});
