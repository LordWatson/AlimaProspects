<?php

declare(strict_types=1);

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\OutreachRestriction;

it('ties the prior-consent restriction to the UAE market only', function (): void {
    // The UK runs on a legitimate-interest basis assessed per team (the LIA), so there is no
    // blanket per-lead limit to record. UAE PDPL needs the person's prior consent, which nothing a
    // team discovers about someone can supply (ADR 0007).
    expect(Market::Uae->outreachRestriction())->toBe(OutreachRestriction::UaePriorConsent)
        ->and(Market::Uk->outreachRestriction())->toBeNull();
});

it('refuses direct marketing under prior consent', function (): void {
    // Outreach (Phase 11) branches on this, never on the case itself, so a restriction added later
    // can't be missed by a `match` that still compiles.
    expect(OutreachRestriction::UaePriorConsent->allowsDirectMarketing())->toBeFalse();
});

it('reads a restriction off a lead’s raw payload', function (): void {
    expect(OutreachRestriction::fromRaw(['outreach_restriction' => 'uae_prior_consent']))
        ->toBe(OutreachRestriction::UaePriorConsent)
        ->and(OutreachRestriction::RAW_KEY)->toBe('outreach_restriction');
});

it('reads nothing rather than failing on a missing or unrecognised value', function (): void {
    // A row written before a case was renamed must not crash the lead detail screen.
    expect(OutreachRestriction::fromRaw(null))->toBeNull()
        ->and(OutreachRestriction::fromRaw([]))->toBeNull()
        ->and(OutreachRestriction::fromRaw(['outreach_restriction' => 'something_else']))->toBeNull()
        ->and(OutreachRestriction::fromRaw(['outreach_restriction' => 42]))->toBeNull();
});

it('has a label and a line of guidance for every restriction', function (OutreachRestriction $restriction): void {
    expect($restriction->label())->not->toBe('')
        ->and($restriction->guidance())->not->toBe('');
})->with(OutreachRestriction::cases());
