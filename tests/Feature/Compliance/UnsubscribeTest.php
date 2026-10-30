<?php

declare(strict_types=1);

use App\Modules\Compliance\Services\LeadAnonymiser;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Data\SenderIdentity;
use App\Modules\Outreach\Enums\ContactOutcome;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Mail\OutreachMessageMail;
use App\Modules\Outreach\Models\ContactAttempt;
use App\Modules\Outreach\Models\OutreachMessage;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The hosted one-click unsubscribe endpoint (Phase 15, docs/compliance.md §3.3).
 *
 * The behaviour worth pinning down is what the GET does **not** do: mail clients, link checkers and
 * corporate security gateways fetch every URL in an email, and an endpoint that acted on GET would
 * unsubscribe people who never clicked.
 */
it('shows a page with a button and changes nothing on the GET', function (): void {
    [$message] = sentMessage();

    $this->get(unsubscribeUrl($message))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Unsubscribe')
            ->where('done', false)
            ->where('expired', false));

    // RFC 8058 one-click is a POST, and this is why.
    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('needs no session — the signature is the authorisation', function (): void {
    [$message] = sentMessage();

    // The recipient is not a user of the platform, so there is nobody to log in as.
    $this->assertGuest();

    $this->get(unsubscribeUrl($message))->assertOk();
});

it('refuses an unsigned or tampered link', function (): void {
    [$message] = sentMessage();

    $this->get('/unsubscribe/'.$message->getKey())->assertForbidden();

    $this->get(unsubscribeUrl($message).'&tampered=1')->assertForbidden();
});

it('suppresses the address globally on the POST, and the phone number with it', function (): void {
    [$message, $team] = sentMessage();

    $this->post(unsubscribeUrl($message))
        ->assertRedirect(route('unsubscribe.done'));

    $entries = OutreachSuppression::query()->get();

    // An opt-out is a person's wish, not a per-tenant setting (§3.2) — and somebody who
    // unsubscribes from email has not invited a call instead.
    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('scope')->unique()->all())->toBe([SuppressionScope::Global])
        ->and($entries->pluck('reason')->unique()->all())->toBe([SuppressionReason::OptOut])
        ->and($entries->pluck('team_id')->unique()->all())->toBe([null])
        ->and(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $team->getKey()))->toBeTrue()
        ->and(app(SuppressionList::class)->isPhoneSuppressed('+44 20 7946 0000', (int) $team->getKey()))->toBeTrue();
});

it('binds every other team, not just the one that sent the message', function (): void {
    [$message] = sentMessage();
    [, $otherTeam] = Fixtures::team();

    $this->post(unsubscribeUrl($message))->assertRedirect();

    expect(app(SuppressionList::class)->isEmailSuppressed('dana@acme.test', (int) $otherTeam->getKey()))->toBeTrue();
});

it('records the outcome on the approach, so the team sees what happened', function (): void {
    [$message, $team, , $attempt] = sentMessage();

    $this->post(unsubscribeUrl($message))->assertRedirect();

    expect($attempt->refresh()->outcome)->toBe(ContactOutcome::OptedOut);

    // The timeline entry for that attempt is updated in place — one `Contacted` entry per attempt,
    // as Phase 11 established, rather than a second one stacked on top.
    $properties = app(TeamContext::class)->run($team, fn (): array => (array) ContactAttempt::query()
        ->whereKey($attempt->getKey())
        ->sole()
        ->lead
        ?->activities()
        ->first()
        ?->properties);

    expect($properties['outcome'])->toBe(ContactOutcome::OptedOut->value);
});

it('leaves an outcome a member already recorded alone', function (): void {
    [$message, $team, , $attempt] = sentMessage();

    app(TeamContext::class)->run($team, fn () => $attempt->forceFill([
        'outcome' => ContactOutcome::MeetingBooked,
    ])->save());

    $this->post(unsubscribeUrl($message))->assertRedirect();

    // A member who has recorded a meeting knows something this click does not; the suppression
    // stands either way, which is the part that matters.
    expect($attempt->refresh()->outcome)->toBe(ContactOutcome::MeetingBooked)
        ->and(OutreachSuppression::query()->count())->toBe(2);
});

it('is idempotent — a second click says the same thing', function (): void {
    [$message] = sentMessage();

    $this->post(unsubscribeUrl($message))->assertRedirect();
    $this->post(unsubscribeUrl($message))->assertRedirect();

    expect(OutreachSuppression::query()->count())->toBe(2);
});

it('audits it with no actor, because the recipient is not a user', function (): void {
    [$message, $team] = sentMessage();

    $this->post(unsubscribeUrl($message))->assertRedirect();

    $entry = AuditLog::query()->where('action', 'compliance.opt_out_recorded')->sole();

    expect($entry->user_id)->toBeNull()
        ->and($entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['source'])->toBe('one_click_unsubscribe')
        // Never the address: the hash and the mask are the only forms we keep (§3.9).
        ->and(json_encode($entry->properties))->not->toContain('dana@acme.test');
});

it('says the link is no longer active when the message has been purged', function (): void {
    [$message] = sentMessage();
    $url = unsubscribeUrl($message);

    OutreachMessage::withoutGlobalScopes()->whereKey($message->getKey())->delete();

    $this->get($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('expired', true));

    // Answered with a page rather than a redirect to the confirmation, because the confirmation
    // says "done" and this is not done.
    $this->post($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('expired', true)->where('done', false));
});

it('says the link is no longer active once retention has anonymised the lead', function (): void {
    [$message, $team] = sentMessage();
    $url = unsubscribeUrl($message);

    // The sweep deleted the contact and cleared `sent_to`, so the message survives with no
    // identity on it. Left to the central exception handler this would become a toast and a
    // `back()` — sending a mail client's one-click POST to `/`.
    app(TeamContext::class)->run($team, fn (): bool => app(LeadAnonymiser::class)->handle(
        Lead::withoutGlobalScopes()->findOrFail($message->lead_id),
    ));

    $this->post($url)
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('expired', true)->where('done', false));

    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('meters the public endpoint by caller', function (): void {
    [$message] = sentMessage();

    config()->set('outreach.unsubscribe.rate_limit', 1);

    $this->get(unsubscribeUrl($message))->assertOk();
    $this->get(unsubscribeUrl($message))->assertStatus(429);
});

it('shows a confirmation that discloses nothing', function (): void {
    $this->get(route('unsubscribe.done'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/Unsubscribe')
            ->where('done', true)
            ->where('action', null));
});

it('is left out of the footer entirely when the endpoint is switched off', function (): void {
    config()->set('outreach.unsubscribe.enabled', false);

    [$message] = sentMessage();

    $mail = new OutreachMessageMail(
        $message,
        new SenderIdentity(
            fromAddress: 'outreach@alima.test',
            fromName: 'Acme Ltd via Alima',
            replyTo: 'member@alima.test',
            replyToName: 'A Member',
            senderName: 'Acme Ltd',
            senderWebsite: 'acme.test',
            optOutAddress: 'unsubscribe@alima.test',
            privacyNoticeUrl: null,
        ),
    );

    $headers = $mail->headers();

    // Advertising one-click against a `mailto:`-only header is worse than not advertising it —
    // Gmail and Yahoo read the mismatch as a bad signal.
    expect($headers->text)->not->toHaveKey('List-Unsubscribe-Post')
        ->and($headers->text['List-Unsubscribe'])->toBe('<mailto:unsubscribe@alima.test?subject=unsubscribe>')
        ->and($mail->render())->not->toContain('/unsubscribe/');
});

/**
 * A signed one-click URL for a message, the way the mail footer builds it.
 */
function unsubscribeUrl(OutreachMessage $message): string
{
    return URL::temporarySignedRoute('unsubscribe.show', now()->addDays(400), [
        'message' => $message->getKey(),
    ]);
}

/**
 * A sent message to a contact with both an address and a number, and the approach it was logged as.
 *
 * @return array{OutreachMessage, Team, LeadContact, ContactAttempt}
 */
function sentMessage(): array
{
    [$user, $team] = Fixtures::team();

    return app(TeamContext::class)->run($team, function () use ($team, $user): array {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Acme Ltd']);

        $contact = LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Dana Buyer',
            'email' => 'dana@acme.test',
            'phone' => '+44 20 7946 0000',
        ]);

        $message = OutreachMessage::factory()
            ->forContact((int) $contact->getKey())
            ->sent('dana@acme.test')
            ->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'created_by' => $user->getKey(),
            ]);

        $attempt = Fixtures::attempt($team, $lead, now()->subDay(), (int) $contact->getKey());
        $attempt->forceFill(['outreach_message_id' => $message->getKey()])->save();

        return [$message, $team, $contact, $attempt];
    });
}
