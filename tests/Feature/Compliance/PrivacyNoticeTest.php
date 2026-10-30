<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Outreach\Services\SenderIdentityResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\OutreachFixtures;

uses(RefreshDatabase::class);

/**
 * The hosted privacy notice (Phase 15, docs/compliance.md §3.7).
 *
 * The thing that matters is that it is reachable by somebody who is not a user: it exists for the
 * person who received a cold email, and a notice behind a login is not a notice.
 */
it('is readable without logging in', function (): void {
    $this->assertGuest();

    $this->get(route('privacy-notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('public/PrivacyNotice')
            ->has('notice.version')
            ->has('notice.hosting_location'));
});

it('states the real retention windows rather than a vague period', function (): void {
    config()->set('compliance.retention.defaults.uncontacted_lead_days', 180);
    config()->set('compliance.retention.defaults.contacted_lead_days', 540);

    $this->get(route('privacy-notice'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('notice.uncontacted_days', 180)
            ->where('notice.contacted_days', 540));
});

it('is what the outreach footer links to when nothing else is configured', function (): void {
    config()->set('outreach.privacy_notice_url', null);

    [$user, $team] = OutreachFixtures::team();

    $sender = app(TeamContext::class)->run(
        $team,
        fn () => app(SenderIdentityResolver::class)->resolve($team, $user),
    );

    // A deployment should have a working notice without setting a variable — the footer's link is
    // never missing (docs/compliance.md §3.3).
    expect($sender?->privacyNoticeUrl)->toBe(route('privacy-notice'));
});

it('leaves a configured notice alone', function (): void {
    config()->set('outreach.privacy_notice_url', 'https://example.test/privacy');

    [$user, $team] = OutreachFixtures::team();

    $sender = app(TeamContext::class)->run(
        $team,
        fn () => app(SenderIdentityResolver::class)->resolve($team, $user),
    );

    expect($sender?->privacyNoticeUrl)->toBe('https://example.test/privacy');
});
