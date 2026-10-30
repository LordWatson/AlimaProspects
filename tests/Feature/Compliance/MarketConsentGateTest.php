<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Http\Resources\LeadResource;
use App\Modules\Outreach\Contracts\LeadRestrictionResolver;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Services\OutreachGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutreachFixtures;

uses(RefreshDatabase::class);

/**
 * The market-aware outreach consent gate (Phase 15, docs/compliance.md §2 / ADR 0007).
 *
 * This closes the hole the recorded flag left. Signals stamps `leads.raw.outreach_restriction` on
 * the leads it converts, but a company sourced from a UAE business listing by Lead Sourcing carries
 * no flag at all — so before this gate, a team could cold email a Dubai prospect that UAE PDPL
 * requires prior consent to approach.
 */
it('restricts a prospect whose own country is the UAE, with no flag on the record', function (): void {
    [$user, $team] = OutreachFixtures::team();
    [$lead, $contact] = OutreachFixtures::lead($team, ['country' => 'AE']);

    $restriction = app(LeadRestrictionResolver::class)->restrictionFor($team, $lead);

    expect($restriction)->toBe(OutreachRestriction::UaePriorConsent)
        // Nothing stamped it: the flag is only set by the module that created the lead, and Lead
        // Sourcing does not set one.
        ->and(OutreachRestriction::fromRaw($lead->raw))->toBeNull();

    $decision = app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact, $user);

    expect($decision->allowed)->toBeFalse()
        ->and($decision->reasons)->toContain(OutreachBlockReason::Restricted);
});

it('leaves a UK prospect open for a UK team', function (): void {
    [$user, $team] = OutreachFixtures::team();
    [$lead, $contact] = OutreachFixtures::lead($team, ['country' => 'GB']);

    expect(app(LeadRestrictionResolver::class)->restrictionFor($team, $lead))->toBeNull()
        ->and(app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact, $user)->allowed)->toBeTrue();
});

it('presumes the team’s market when the lead’s country is unknown', function (): void {
    [$user, $team] = OutreachFixtures::team();
    $team->forceFill(['market' => Market::Uae->value])->save();

    [$lead, $contact] = OutreachFixtures::lead($team, ['country' => null]);

    // A presumption rather than a fact, and the stricter one by design (§2: default to the stricter
    // rule when a prospect's jurisdiction may be the UAE).
    expect(app(LeadRestrictionResolver::class)->restrictionFor($team->refresh(), $lead))
        ->toBe(OutreachRestriction::UaePriorConsent)
        ->and(app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact, $user)->allowed)
        ->toBeFalse();
});

it('does not restrict a known UK prospect just because the team trades in the UAE', function (): void {
    [$user, $team] = OutreachFixtures::team();
    $team->forceFill(['market' => Market::Uae->value])->save();

    [$lead, $contact] = OutreachFixtures::lead($team, ['country' => 'GB']);

    // The restriction protects the person being approached, and the regime that protects them is
    // the one where they are. The team's market is only a fallback.
    expect(app(LeadRestrictionResolver::class)->restrictionFor($team->refresh(), $lead))->toBeNull()
        ->and(app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact, $user)->allowed)->toBeTrue();
});

it('never drops a restriction recorded on the lead, whatever the country says', function (): void {
    [, $team] = OutreachFixtures::team();

    // A Dubai founder's lead filed under a UK holding company — precisely the case the Signals
    // deviation in §5a exists for.
    [$lead] = OutreachFixtures::lead($team, [
        'country' => 'GB',
        'raw' => [OutreachRestriction::RAW_KEY => OutreachRestriction::UaePriorConsent->value],
    ]);

    expect(app(LeadRestrictionResolver::class)->restrictionFor($team, $lead))
        ->toBe(OutreachRestriction::UaePriorConsent);
});

it('falls through to the team’s market for a country outside both markets', function (): void {
    [, $team] = OutreachFixtures::team();
    [$lead] = OutreachFixtures::lead($team, ['country' => 'FR']);

    // Not treated as cleared: a third country means somebody is working outside the assessed
    // scope, which is the team's judgement to make and the LIA's to cover. A UK team's own basis
    // applies, so there is no per-lead restriction.
    expect(app(LeadRestrictionResolver::class)->restrictionFor($team, $lead))->toBeNull();
});

it('leaves the introduction channels open on a restricted lead', function (): void {
    [$user, $team] = OutreachFixtures::team();
    [$lead, $contact] = OutreachFixtures::lead($team, ['country' => 'AE']);

    $decisions = app(OutreachGuard::class)->decideAll($team, $lead, $contact, $user);

    // The restriction narrows the approach rather than ending it: LinkedIn and a call script are
    // the member's own act on a platform where the other person chooses whether to answer.
    expect($decisions[OutreachChannel::Email->value]->allowed)->toBeFalse()
        ->and($decisions[OutreachChannel::LinkedIn->value]->allowed)->toBeTrue()
        ->and($decisions[OutreachChannel::Call->value]->allowed)->toBeTrue();
});

it('sends the effective restriction to clients, not just the stamped one', function (): void {
    [$user, $team] = OutreachFixtures::team();
    [$lead] = OutreachFixtures::lead($team, ['country' => 'AE']);

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => LeadResource::make($lead)->resolve(request()),
    );

    // A screen that said "no restriction" while the guard refuses email would promise a send that
    // never happens, which is the inconsistency this resolver exists to remove.
    expect($payload['outreach_restriction'])->not->toBeNull()
        ->and($payload['outreach_restriction']['value'])->toBe(OutreachRestriction::UaePriorConsent->value)
        ->and($payload['outreach_restriction']['allows_direct_marketing'])->toBeFalse()
        ->and($user->getKey())->not->toBeNull();
});
