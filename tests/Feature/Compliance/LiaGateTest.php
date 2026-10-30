<?php

declare(strict_types=1);

use App\Modules\Compliance\Enums\LiaStatus;
use App\Modules\Compliance\Models\LiaRecord;
use App\Modules\Compliance\Services\LegitimateInterestGate;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * The legitimate-interest assessment and the gate it opens (Phase 15, docs/compliance.md §2).
 *
 * The gate is the one thing in this module that stops the platform doing something, so it is tested
 * from both directions: every state that must close it, and the one state that opens it.
 */
it('refuses discovery when the team has no assessment on record', function (): void {
    [, $team] = Fixtures::team();

    expect(fn () => app(LegitimateInterestGate::class)->assertDiscoveryAllowed($team))
        ->toThrow(LeadSourcingException::class);

    expect(app(LegitimateInterestGate::class)->isOpen($team))->toBeFalse();
});

it('refuses discovery on a draft — saving is not signing', function (): void {
    [, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => LiaRecord::factory()->draft()->create(['team_id' => $team->getKey()]));

    expect(fn () => app(LegitimateInterestGate::class)->assertDiscoveryAllowed($team))
        ->toThrow(LeadSourcingException::class);
});

it('refuses discovery on an assessment past its review date, and says so differently', function (): void {
    [, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => LiaRecord::factory()->expired()->create(['team_id' => $team->getKey()]));

    // The *reason* matters: a team with a lapsed review has to be told to re-affirm what they have,
    // not to write it again from scratch.
    try {
        app(LegitimateInterestGate::class)->assertDiscoveryAllowed($team);
        $this->fail('The gate should have refused an expired assessment.');
    } catch (LeadSourcingException $e) {
        expect($e->errorCode())->toBe('lead_sourcing.lia_expired');
    }
});

it('refuses discovery once the basis is withdrawn', function (): void {
    [, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, fn () => LiaRecord::factory()->withdrawn()->create(['team_id' => $team->getKey()]));

    expect(app(LegitimateInterestGate::class)->isOpen($team))->toBeFalse();
});

it('allows discovery with an assessment in force for the team’s market', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::activeLia($team);

    app(LegitimateInterestGate::class)->assertDiscoveryAllowed($team);

    expect(app(LegitimateInterestGate::class)->isOpen($team))->toBeTrue();
});

it('is keyed on the team’s own market, not on any assessment it happens to hold', function (): void {
    [, $team] = Fixtures::team();

    // A UAE team with a UK assessment. The two markets are different regimes (ADR 0007) and one
    // assessment cannot stand in for the other — which is the whole reason the record is per market.
    $team->forceFill(['market' => Market::Uae->value])->save();
    Fixtures::activeLia($team, Market::Uk);

    expect(app(LegitimateInterestGate::class)->isOpen($team->refresh()))->toBeFalse();

    Fixtures::activeLia($team, Market::Uae);

    expect(app(LegitimateInterestGate::class)->isOpen($team->refresh()))->toBeTrue();
});

it('never opens one team’s gate with another team’s assessment', function (): void {
    [, $teamA] = Fixtures::team();
    $teamB = Team::factory()->create();

    Fixtures::activeLia($teamA);

    expect(app(LegitimateInterestGate::class)->isOpen($teamB))->toBeFalse();
});

// --- The record's own in-force rule -------------------------------------------------------------
// Not in tests/Unit: Eloquent reads config, so a model needs a booted application. It sits here,
// beside the gate that asks it.

it('treats an assessment as in force only while all three conditions hold', function (): void {
    $record = new LiaRecord;
    $record->forceFill([
        'status' => LiaStatus::Active,
        'completed_at' => Carbon::parse('2026-01-01'),
        'review_due_at' => Carbon::parse('2027-01-01'),
    ]);

    expect($record->isInForce(Carbon::parse('2026-06-01')))->toBeTrue()
        // Past the review date the gate closes, and the state is distinguishable from "never
        // signed" so the member is told to re-affirm rather than to start again.
        ->and($record->isInForce(Carbon::parse('2027-02-01')))->toBeFalse()
        ->and($record->hasExpired(Carbon::parse('2027-02-01')))->toBeTrue()
        ->and($record->hasExpired(Carbon::parse('2026-06-01')))->toBeFalse();
});

it('does not treat an unsigned assessment as in force however complete it looks', function (): void {
    $record = new LiaRecord;
    $record->forceFill([
        'status' => LiaStatus::Draft,
        'completed_at' => null,
        'review_due_at' => Carbon::parse('2027-01-01'),
    ]);

    expect($record->isInForce(Carbon::parse('2026-06-01')))->toBeFalse()
        ->and($record->hasExpired(Carbon::parse('2026-06-01')))->toBeFalse();
});

it('closes the gate the moment a basis is withdrawn, review date or not', function (): void {
    $record = new LiaRecord;
    $record->forceFill([
        'status' => LiaStatus::Active,
        'completed_at' => Carbon::parse('2026-01-01'),
        'review_due_at' => Carbon::parse('2027-01-01'),
        'withdrawn_at' => Carbon::parse('2026-03-01'),
    ]);

    expect($record->isInForce(Carbon::parse('2026-06-01')))->toBeFalse();
});

it('counts the days to a review, negative once it is overdue', function (): void {
    $record = new LiaRecord;
    $record->forceFill([
        'status' => LiaStatus::Active,
        'completed_at' => Carbon::parse('2026-01-01'),
        'review_due_at' => Carbon::parse('2026-06-11'),
    ]);

    expect($record->reviewDueInDays(Carbon::parse('2026-06-01')))->toBe(10)
        ->and($record->reviewDueInDays(Carbon::parse('2026-06-21')))->toBeLessThan(0);
});

// --- The screen ---------------------------------------------------------------------------------

it('shows the assessment screen with the team’s market selected', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::activeLia($team);

    $this->actingAs($user)
        ->get(route('compliance.lia.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('compliance/Lia')
            ->where('assessment.market', 'uk')
            ->where('assessment.in_force', true)
            ->where('team_market', 'uk')
            ->where('can.manage', true));
});

it('saves a draft without opening the gate', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->put(route('compliance.lia.update'), [
            'market' => 'uk',
            'purpose' => 'Still thinking about this.',
            'necessity' => '',
            'balancing' => '',
            'sign' => false,
        ])
        ->assertRedirect();

    $record = LiaRecord::withoutGlobalScopes()->sole();

    expect($record->status)->toBe(LiaStatus::Draft)
        ->and($record->completed_at)->toBeNull()
        ->and(app(LegitimateInterestGate::class)->isOpen($team))->toBeFalse();
});

it('signs the assessment off, stamps who and when, and opens the gate', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)
        ->put(route('compliance.lia.update'), liaPayload())
        ->assertRedirect();

    $record = LiaRecord::withoutGlobalScopes()->sole();

    expect($record->status)->toBe(LiaStatus::Active)
        ->and($record->completed_by)->toBe($user->getKey())
        ->and($record->completed_at)->not->toBeNull()
        ->and($record->review_due_at)->not->toBeNull()
        ->and($record->version)->toBe(1)
        ->and(app(LegitimateInterestGate::class)->isOpen($team))->toBeTrue();
});

it('refuses to sign a perfunctory assessment', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->put(route('compliance.lia.update'), liaPayload(['balancing' => 'Fine.']))
        ->assertSessionHasErrors('balancing');

    expect(LiaRecord::withoutGlobalScopes()->count())->toBe(0);
});

it('bumps the version when the assessment is re-affirmed, but not when it is merely saved', function (): void {
    [$user, $team] = Fixtures::team();

    $this->actingAs($user)->put(route('compliance.lia.update'), liaPayload())->assertRedirect();
    $this->actingAs($user)->put(route('compliance.lia.update'), liaPayload(['sign' => false]))->assertRedirect();

    expect(LiaRecord::withoutGlobalScopes()->sole()->version)->toBe(1);

    $this->actingAs($user)->put(route('compliance.lia.update'), liaPayload())->assertRedirect();

    // The counter exists so an audit entry can say which assessment was in force when a run
    // happened; moving it for a typo fix would make it say nothing.
    expect(LiaRecord::withoutGlobalScopes()->sole()->version)->toBe(2)
        ->and(app(LegitimateInterestGate::class)->isOpen($team))->toBeTrue();
});

it('closes the gate on withdrawal and keeps the record', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::activeLia($team);

    $this->actingAs($user)
        ->delete(route('compliance.lia.withdraw'))
        ->assertRedirect();

    $record = LiaRecord::withoutGlobalScopes()->sole();

    // Kept, not deleted: "there was a basis, and it ran until this date" is the accountability.
    expect($record->status)->toBe(LiaStatus::Withdrawn)
        ->and($record->withdrawn_at)->not->toBeNull()
        ->and(app(LegitimateInterestGate::class)->isOpen($team))->toBeFalse();
});

it('re-affirming after a withdrawal clears it and starts a new version', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::activeLia($team);

    $this->actingAs($user)->delete(route('compliance.lia.withdraw'))->assertRedirect();
    $this->actingAs($user)->put(route('compliance.lia.update'), liaPayload())->assertRedirect();

    $record = LiaRecord::withoutGlobalScopes()->sole();

    expect($record->status)->toBe(LiaStatus::Active)
        ->and($record->withdrawn_at)->toBeNull()
        ->and($record->version)->toBe(2)
        ->and(app(LegitimateInterestGate::class)->isOpen($team))->toBeTrue();
});

it('lets any member read the assessment but only an admin write it', function (): void {
    [, $team] = Fixtures::team();
    Fixtures::activeLia($team);

    $plainMember = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($plainMember)
        ->get(route('compliance.lia.edit'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('can.manage', false));

    $this->actingAs($plainMember)
        ->put(route('compliance.lia.update'), liaPayload())
        ->assertForbidden();
});

/**
 * A complete, signable assessment.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function liaPayload(array $overrides = []): array
{
    return [
        'market' => 'uk',
        'purpose' => 'To identify UK businesses whose published characteristics match our ideal-customer profile and approach them about a relevant service.',
        'necessity' => 'Public company and business-listing data is the only way to identify these businesses; we hold role-based contact points in preference to personal ones.',
        'balancing' => 'Business contact data, a relevant approach, an opt-out honoured platform-wide, and nothing retained past its window.',
        'safeguards' => 'Role-based data preferred; retention windows enforced nightly.',
        'signals_scope' => null,
        'sign' => true,
        ...$overrides,
    ];
}
