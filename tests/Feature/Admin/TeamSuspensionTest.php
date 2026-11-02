<?php

declare(strict_types=1);

use App\Modules\Admin\Exceptions\AdminException;
use App\Modules\Admin\Services\TeamSuspension;
use App\Modules\AiPlatform\Contracts\CreditSource;
use App\Modules\Core\Models\AuditLog;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\AdminFixtures as Fixtures;
use Tests\Support\OutreachFixtures;

uses(RefreshDatabase::class);

/*
 * Suspension (Phase 19a) — what stops, and just as importantly what does not.
 *
 * Suspension stops **sourcing, sending and spending**: the three things that cost us money or reach
 * a member of the public. It is not a lockout. Members sign in and read their own data exactly as
 * before, which is deliberate twice over — it punishes the colleague who did nothing to bolt the
 * door on them, and a tenant who cannot reach their own data cannot exercise their rights over it.
 *
 * And it bites in the **guards**, not in middleware: a queued discovery job and a scheduled signal
 * watch spend a team's allowance with no request behind them (ADR 0012's argument, unchanged).
 */

it('suspends a tenant with a reason, and records who did it', function (): void {
    [, $team] = Fixtures::tenant('Acme Ltd');
    $operator = Fixtures::operator();

    $this->actingAs($operator)
        ->post('/admin/teams/'.$team->getKey().'/suspend', [
            'reason' => 'Three spam complaints from one campaign.',
        ])
        ->assertRedirect('/admin/teams/'.$team->getKey());

    $team->refresh();

    expect($team->isSuspended())->toBeTrue()
        ->and($team->suspension_reason)->toBe('Three spam complaints from one campaign.');

    $entry = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', 'admin.team.suspended')
        ->latest('id')
        ->first();

    expect($entry)->not->toBeNull()
        ->and((int) $entry->team_id)->toBe((int) $team->getKey())
        ->and($entry->properties['operator_id'])->toBe((int) $operator->getKey());
});

it('insists on a reason worth reading', function (): void {
    [, $team] = Fixtures::tenant();

    // "Abuse" is a category, not a reason. A suspension nobody explained is one the next operator
    // cannot safely lift, so the customer stays switched off over a missing sentence.
    $this->actingAs(Fixtures::operator())
        ->post('/admin/teams/'.$team->getKey().'/suspend', ['reason' => 'abuse'])
        ->assertSessionHasErrors('reason');

    expect($team->refresh()->isSuspended())->toBeFalse();
});

it('refuses to suspend twice, so the original timestamp survives', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::suspend($team, 'The original reason, recorded properly.');

    $this->withoutExceptionHandling()
        ->actingAs(Fixtures::operator())
        ->post('/admin/teams/'.$team->getKey().'/suspend', [
            'reason' => 'A second, later reason that would overwrite the first.',
        ]);
})->throws(AdminException::class, 'already suspended');

it('keeps the reason after a suspension is lifted', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::suspend($team, 'Non-payment, chased three times.');

    $this->actingAs(Fixtures::operator())
        ->post('/admin/teams/'.$team->getKey().'/restore', ['note' => 'Paid, invoice 1234.'])
        ->assertRedirect();

    $team->refresh();

    // A lifted suspension is a thing that happened. The next operator should be able to see that
    // the account once stopped and why — which is the history a null here would throw away.
    expect($team->isSuspended())->toBeFalse()
        ->and($team->suspension_reason)->toBe('Non-payment, chased three times.');
});

it('stops a suspended tenant spending and sourcing', function (): void {
    [, $team] = Fixtures::tenant();

    expect(app(CreditSource::class)->allowanceFor($team)->limitMicros)->toBeGreaterThan(0);

    Fixtures::suspend($team);

    expect(app(CreditSource::class)->allowanceFor($team)->isExhausted())->toBeTrue()
        ->and(app(LeadQuotaSource::class)->quotaFor($team)->isExhausted())->toBeTrue();
});

it('stops a suspended tenant sending, without blaming the prospect', function (): void {
    [, $team] = OutreachFixtures::team();
    [$lead, $contact] = OutreachFixtures::lead($team);

    $allowed = app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact);
    expect($allowed->allowed)->toBeTrue();

    Fixtures::suspend($team);

    $blocked = app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact);

    // `TeamSuspended`, not `Restricted`. Binding over the restriction resolver would have been the
    // short cut, and it would have told the member that *this prospect* may not be approached —
    // a false statement about somebody else's legal position, recorded as though it were true.
    expect($blocked->allowed)->toBeFalse()
        ->and($blocked->reasons)->toContain(OutreachBlockReason::TeamSuspended)
        ->and($blocked->reasons)->not->toContain(OutreachBlockReason::Restricted)
        ->and(OutreachBlockReason::TeamSuspended->isCompliance())->toBeFalse();
});

it('stops a suspended tenant from a queue with no request behind it', function (): void {
    [, $team] = Fixtures::tenant();
    Fixtures::suspend($team);

    // The reason suspension lives in the guards rather than in middleware. Resolved from the
    // container with no request in play at all, which is what a queued job looks like.
    $this->app->forgetInstance(TeamSuspension::class);

    expect(app(CreditSource::class)->allowanceFor($team)->isExhausted())->toBeTrue()
        ->and(app(LeadQuotaSource::class)->quotaFor($team)->isExhausted())->toBeTrue();
});

it('still lets a suspended tenant sign in and read its own data', function (): void {
    [$owner, $team] = Fixtures::tenant();
    Fixtures::lead($team, 'Acme Ltd');
    Fixtures::suspend($team);

    // Not a lockout. Locking people out of data they own is both the wrong lever and a
    // data-protection problem: they cannot exercise their rights through a door we have bolted.
    $this->actingAs($owner)->get(route('dashboard'))->assertOk();
    $this->actingAs($owner)->get(route('leads.index'))->assertOk();
});

it('does not answer for a team it looked at three jobs ago', function (): void {
    [, $team] = Fixtures::tenant();
    $suspension = app(TeamSuspension::class);

    expect($suspension->isSuspended($team))->toBeFalse();

    $team->forceFill(['suspended_at' => now(), 'suspension_reason' => 'Later.'])->save();

    // Still memoised — which is correct inside one request, and exactly why `forget()` exists for
    // a long-lived queue worker.
    expect($suspension->isSuspended((int) $team->getKey()))->toBeFalse();

    $suspension->forget();

    expect($suspension->isSuspended((int) $team->getKey()))->toBeTrue();
});
