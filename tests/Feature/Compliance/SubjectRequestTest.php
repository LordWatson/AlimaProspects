<?php

declare(strict_types=1);

use App\Modules\Compliance\Actions\EraseSubjectDataAction;
use App\Modules\Compliance\Actions\ResolveSubjectRequestAction;
use App\Modules\Compliance\Actions\VerifySubjectRequestAction;
use App\Modules\Compliance\Enums\SubjectRequestStatus;
use App\Modules\Compliance\Enums\SubjectRequestType;
use App\Modules\Compliance\Exceptions\ComplianceException;
use App\Modules\Compliance\Jobs\PurgeSubjectDataJob;
use App\Modules\Compliance\Mail\VerifySubjectRequestMail;
use App\Modules\Compliance\Models\DataSubjectRequest;
use App\Modules\Compliance\Support\SubjectRequestUrl;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The public privacy-request flow (PL5, docs/compliance.md §3.17).
 *
 * The behaviour worth pinning down is what submission does **not** do. An unverified form must
 * suppress nothing, purge nothing and appear in no queue — otherwise it is a way to erase a
 * competitor's prospects, or to see whether we hold somebody, by typing an address into a page
 * anybody can reach.
 */
it('records a request without acting on it, and emails a confirmation link', function (): void {
    Mail::fake();
    Queue::fake();

    $this->post('/privacy/request', [
        'type' => SubjectRequestType::Erasure->value,
        'email' => ' Dana@Example.com ',
        'name' => 'Dana Example',
    ])->assertRedirect('/privacy/request/sent');

    $request = DataSubjectRequest::query()->sole();

    expect($request->status)->toBe(SubjectRequestStatus::PendingVerification)
        // Normalised on the way in: every downstream use of it is an identity comparison.
        ->and($request->email)->toBe('dana@example.com')
        ->and($request->verified_at)->toBeNull()
        ->and($request->due_at)->toBeNull();

    // Nothing has happened yet, which is the whole point of the pending state.
    expect(OutreachSuppression::query()->count())->toBe(0);
    Queue::assertNothingPushed();

    Mail::assertQueued(VerifySubjectRequestMail::class);
});

it('reuses a pending request and refuses to let a second submission rewrite it', function (): void {
    Mail::fake();

    $payload = ['type' => SubjectRequestType::OptOut->value, 'email' => 'dana@example.com', 'name' => 'Dana'];

    $this->post('/privacy/request', $payload)->assertRedirect();

    // Nobody has proved control of that mailbox yet, so a second submission must not be able to
    // rewrite what the first one said — otherwise anybody who knows an address can edit a pending
    // request's details before the real person clicks their link.
    $this->post('/privacy/request', [
        'type' => SubjectRequestType::OptOut->value,
        'email' => 'dana@example.com',
        'name' => 'Not Dana',
        'phone' => '+44 20 7946 0000',
    ])->assertRedirect();

    $request = DataSubjectRequest::query()->sole();

    expect($request->name)->toBe('Dana')
        ->and($request->phone)->toBeNull();
});

it('will not mail the same address again inside the cooldown', function (): void {
    Mail::fake();
    config()->set('compliance.subject_requests.resend_cooldown', 300);

    $payload = ['type' => SubjectRequestType::OptOut->value, 'email' => 'dana@example.com'];

    $this->post('/privacy/request', $payload)->assertRedirect();
    $this->post('/privacy/request', $payload)->assertRedirect();

    // The per-IP throttle stops one visitor flooding us. This is the other abuse: an endpoint that
    // sends mail to an address the caller names, from a rotating IP.
    Mail::assertQueued(VerifySubjectRequestMail::class, 1);
});

it('mails again once the cooldown has passed, because somebody really may not have had it', function (): void {
    Mail::fake();
    config()->set('compliance.subject_requests.resend_cooldown', 1);

    $payload = ['type' => SubjectRequestType::OptOut->value, 'email' => 'dana@example.com'];

    $this->post('/privacy/request', $payload)->assertRedirect();

    $this->travel(5)->seconds();

    $this->post('/privacy/request', $payload)->assertRedirect();

    Mail::assertQueued(VerifySubjectRequestMail::class, 2);
});

it('says nothing about whether we hold the person', function (): void {
    Mail::fake();

    [, $team] = Fixtures::team();
    heldContact($team, 'dana@example.com');

    $this->post('/privacy/request', [
        'type' => SubjectRequestType::Access->value,
        'email' => 'dana@example.com',
    ])->assertRedirect('/privacy/request/sent');

    // The same confirmation as for an address nobody holds — the page carries no parameters at all.
    $this->get('/privacy/request/sent')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/PrivacyRequest')
            ->where('submitted', true)
            ->where('verified', false));
});

it('refuses the form when a configured challenge fails', function (): void {
    config()->set('services.turnstile.site_key', 'site');
    config()->set('services.turnstile.secret', 'secret');

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $this->post('/privacy/request', [
        'type' => SubjectRequestType::OptOut->value,
        'email' => 'dana@example.com',
        'turnstile_token' => 'nope',
        // The central handler turns a `DomainException` into a redirect with a toast for a web
        // request; what matters here is that the submission was refused and nothing was written.
    ])->assertRedirect();

    expect(DataSubjectRequest::query()->count())->toBe(0);
});

it('passes the form when no challenge is configured — an unreachable opt-out is worse', function (): void {
    Mail::fake();

    expect((string) config('services.turnstile.secret'))->toBe('');

    $this->post('/privacy/request', [
        'type' => SubjectRequestType::OptOut->value,
        'email' => 'dana@example.com',
    ])->assertRedirect('/privacy/request/sent');
});

it('throttles submissions per IP', function (): void {
    Mail::fake();
    config()->set('compliance.subject_requests.rate_limit', 2);

    foreach (range(1, 2) as $i) {
        $this->post('/privacy/request', [
            'type' => SubjectRequestType::OptOut->value,
            'email' => "person{$i}@example.com",
        ])->assertRedirect();
    }

    $this->post('/privacy/request', [
        'type' => SubjectRequestType::OptOut->value,
        'email' => 'third@example.com',
    ])->assertStatus(429);
});

it('suppresses platform-wide on verification and starts the statutory clock', function (): void {
    Queue::fake();

    $request = pending(SubjectRequestType::OptOut, phone: '+44 20 7946 0000');

    $this->get(SubjectRequestUrl::verification($request))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('verified', true));

    $request->refresh();

    expect($request->status)->toBe(SubjectRequestStatus::Open)
        ->and($request->verified_at)->not->toBeNull()
        ->and($request->due_at?->toDateString())
        ->toBe(now()->addDays(30)->toDateString());

    $entries = OutreachSuppression::query()->get();

    // **The email only.** The click proved control of a mailbox and nothing else; a number anybody
    // could have typed into a public form must not be blocked across every tenant on the strength
    // of it. The number stays on the request for an operator.
    expect($entries)->toHaveCount(1)
        ->and($entries->pluck('scope')->all())->toBe([SuppressionScope::Global])
        ->and($entries->pluck('reason')->all())->toBe([SuppressionReason::OptOut])
        ->and($entries->pluck('type')->all())->toBe([SuppressionType::Email])
        ->and($request->phone)->not->toBeNull();

    // An opt-out stops contact; it does not delete a business from somebody's CRM.
    Queue::assertNotPushed(PurgeSubjectDataJob::class);
});

it('queues the cross-tenant purge for an erasure only', function (): void {
    Queue::fake();

    $request = pending(SubjectRequestType::Erasure);

    $this->get(SubjectRequestUrl::verification($request))->assertOk();

    Queue::assertPushed(PurgeSubjectDataJob::class);
});

it('is idempotent — a second click changes nothing and queues nothing', function (): void {
    Queue::fake();

    $request = pending(SubjectRequestType::Erasure);
    $url = SubjectRequestUrl::verification($request);

    $this->get($url)->assertOk();
    $verifiedAt = $request->refresh()->verified_at;

    $this->get($url)->assertOk();

    expect($request->refresh()->verified_at?->toIso8601String())
        ->toBe($verifiedAt?->toIso8601String());

    Queue::assertPushed(PurgeSubjectDataJob::class, 1);
});

it('refuses an unsigned verification link', function (): void {
    $request = pending(SubjectRequestType::OptOut);

    $this->get('/privacy/request/'.$request->getKey().'/verify')->assertForbidden();

    expect($request->refresh()->verified_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The cross-tenant purge
|--------------------------------------------------------------------------
*/

it('erases the person from every team that holds them', function (): void {
    [, $first] = Fixtures::team();
    [, $second] = Fixtures::team();
    [, $third] = Fixtures::team();

    heldContact($first, 'dana@example.com');
    heldContact($second, 'dana@example.com');
    heldContact($third, 'someone-else@example.com');

    // Not `Queue::fake()`: the point here is the job's own work, and the test queue runs it
    // synchronously on dispatch — so verifying is the whole trigger, exactly as in production.
    $request = pending(SubjectRequestType::Erasure);
    app(VerifySubjectRequestAction::class)->handle($request);

    expect(LeadContact::query()->withoutGlobalScopes()->where('email', 'dana@example.com')->count())
        ->toBe(0)
        // The other person's record is untouched, which is the half a cross-tenant job could
        // plausibly get wrong.
        ->and(LeadContact::query()->withoutGlobalScopes()->where('email', 'someone-else@example.com')->count())
        ->toBe(1);

    $request->refresh();

    expect($request->purged_at)->not->toBeNull()
        ->and($request->purge_summary['teams_touched'] ?? null)->toBe(2)
        // Counts, never values: a record of what we erased for privacy reasons must not be a copy.
        ->and(json_encode($request->purge_summary))->not->toContain('dana@example.com');
});

it('leaves records matched only by an unverified phone number alone', function (): void {
    [, $team] = Fixtures::team();

    $contact = app(TeamContext::class)->run($team, function () use ($team): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        return LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'someone-else@example.com',
            'phone' => '+44 20 7946 0000',
        ]);
    });

    // The request names a number the submitter never proved control of. Erasing on it would be
    // erasing a stranger's records on a stranger's say-so.
    $request = pending(SubjectRequestType::Erasure, phone: '+44 20 7946 0000');
    app(VerifySubjectRequestAction::class)->handle($request);

    expect(LeadContact::query()->withoutGlobalScopes()->whereKey($contact->getKey())->exists())
        ->toBeTrue();
});

it('re-queues the purge when a verified erasure was never swept', function (): void {
    Queue::fake();

    $request = pending(SubjectRequestType::Erasure);
    $request->forceFill(['status' => 'open', 'verified_at' => now()->subHour()])->save();

    // A queue that was down for the first click used to leave this lost for ever: the row was
    // verified, the person had been told it was done, and every later click returned early.
    app(VerifySubjectRequestAction::class)->handle($request);

    Queue::assertPushed(PurgeSubjectDataJob::class);
});

it('does not stamp the purge as done while a team is still unswept', function (): void {
    [, $team] = Fixtures::team();
    heldContact($team, 'dana@example.com');

    $request = pending(SubjectRequestType::Erasure);
    $request->forceFill(['status' => 'open', 'verified_at' => now()])->save();

    // A tenant this pass cannot touch, simulated the bluntest honest way: the table the erasure
    // reads and writes is gone. `purged_at` must stay null and the job must fail, because
    // `purged_at` is what the admin queue reads as "the deletion half is done" — and reporting
    // success with a failure counted beside it is how a tenant keeps holding somebody for ever,
    // out of reach of the job's own retries.
    Schema::drop('lead_contacts');

    expect(fn () => (new PurgeSubjectDataJob((int) $request->getKey()))
        ->handle(app(EraseSubjectDataAction::class)))
        ->toThrow(RuntimeException::class);

    $request->refresh();

    expect($request->purged_at)->toBeNull()
        ->and($request->purge_summary['teams_failed'] ?? null)->toBe(1);
});

it('never purges on an unverified request, however it is invoked', function (): void {
    [, $team] = Fixtures::team();
    heldContact($team, 'dana@example.com');

    $request = pending(SubjectRequestType::Erasure);

    (new PurgeSubjectDataJob((int) $request->getKey()))
        ->handle(app(EraseSubjectDataAction::class));

    expect(LeadContact::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and($request->refresh()->purged_at)->toBeNull();
});

/*
|--------------------------------------------------------------------------
| The operator queue
|--------------------------------------------------------------------------
*/

it('closes a verified request and records who did it', function (): void {
    Queue::fake();

    [$operator] = Fixtures::team();

    $request = pending(SubjectRequestType::Access);
    app(VerifySubjectRequestAction::class)->handle($request);

    app(ResolveSubjectRequestAction::class)->handle(
        $request,
        SubjectRequestStatus::Completed,
        $operator,
        'Answered by email with the data we hold.',
    );

    $request->refresh();

    expect($request->status)->toBe(SubjectRequestStatus::Completed)
        ->and($request->handled_by)->toBe($operator->getKey())
        ->and($request->completed_at)->not->toBeNull();
});

it('refuses to close an unverified request', function (): void {
    [$operator] = Fixtures::team();

    expect(fn () => app(ResolveSubjectRequestAction::class)->handle(
        pending(SubjectRequestType::Access),
        SubjectRequestStatus::Completed,
        $operator,
    ))->toThrow(ComplianceException::class);
});

it('refuses a rejection with no reason', function (): void {
    Queue::fake();

    [$operator] = Fixtures::team();

    $request = pending(SubjectRequestType::Access);
    app(VerifySubjectRequestAction::class)->handle($request);

    expect(fn () => app(ResolveSubjectRequestAction::class)->handle(
        $request,
        SubjectRequestStatus::Rejected,
        $operator,
    ))->toThrow(ComplianceException::class);
});

/*
|--------------------------------------------------------------------------
| Retention
|--------------------------------------------------------------------------
*/

it('sweeps closed requests past the window and never an open one', function (): void {
    config()->set('compliance.subject_requests.retain_days', 10);

    $closed = pending(SubjectRequestType::OptOut);
    $closed->forceFill([
        'status' => SubjectRequestStatus::Completed->value,
        'verified_at' => now()->subDays(60),
        'completed_at' => now()->subDays(40),
    ])->save();

    // Verified three years ago and still unanswered. Live work, not stale data.
    $openForever = pending(SubjectRequestType::Access);
    $openForever->forceFill([
        'status' => SubjectRequestStatus::Open->value,
        'verified_at' => now()->subYears(3),
    ])->save();

    // Never verified, and its link expired long ago. Neither a record nor live work — just an
    // address somebody typed into a public form, which is the thing the clear-text column cannot
    // be justified by keeping for ever.
    $abandoned = pending(SubjectRequestType::OptOut);
    $abandoned->forceFill(['created_at' => now()->subDays(90)])->save();

    $this->artisan('compliance:purge --sync')->assertSuccessful();

    expect(DataSubjectRequest::query()->pluck('id')->all())
        ->toBe([(int) $openForever->getKey()]);
});

/*
|--------------------------------------------------------------------------
| Helpers
|--------------------------------------------------------------------------
*/

function pending(SubjectRequestType $type, ?string $phone = null): DataSubjectRequest
{
    return DataSubjectRequest::query()->create([
        'type' => $type->value,
        'status' => SubjectRequestStatus::PendingVerification->value,
        'email' => 'dana@example.com',
        'phone' => $phone,
    ]);
}

/**
 * A contact one team holds for this address — the thing a purge has to reach.
 */
function heldContact(Team $team, string $email): LeadContact
{
    return app(TeamContext::class)->run($team, function () use ($team, $email): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        return LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => $email,
            'email_hash' => app(SuppressionList::class)->hash($email),
        ]);
    });
}
