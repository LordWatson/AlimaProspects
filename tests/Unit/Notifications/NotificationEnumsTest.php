<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\DigestFrequency;
use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use Carbon\CarbonImmutable;

/**
 * The Notifications enums, where several product decisions live outright.
 *
 * The cheapest tests in the module and the ones that protect the most: every `match` in these enums
 * is exhaustive, so a type added later cannot compile without answering each question — and these
 * assertions are what catch it answering wrongly.
 */

// --- Channels -------------------------------------------------------------------------------------

it('sends in-app over both Laravel channels', function (): void {
    // One thing as far as a member is concerned — "tell me in Alima" — and two drivers underneath:
    // `database` for the bell on the next page load, `broadcast` so it arrives without one.
    expect(NotificationChannel::InApp->drivers())->toBe(['database', 'broadcast'])
        ->and(NotificationChannel::Mail->drivers())->toBe(['mail'])
        ->and(NotificationChannel::Push->drivers())->toHaveCount(1);
});

it('names the preference column for each channel', function (): void {
    expect(NotificationChannel::InApp->column())->toBe('in_app')
        ->and(NotificationChannel::Mail->column())->toBe('mail')
        ->and(NotificationChannel::Push->column())->toBe('push');
});

// --- Types ----------------------------------------------------------------------------------------

it('is conservative about email by default', function (): void {
    // In-app costs a member nothing; an email they didn't ask for costs them their attention and
    // costs us their trust in the next one. So email is on by default only where the thing is
    // time-sensitive and they would be cross to have missed it.
    expect(NotificationType::TaskDue->defaultChannels())->toContain(NotificationChannel::Mail)
        ->and(NotificationType::LeadAssigned->defaultChannels())->toContain(NotificationChannel::Mail)
        ->and(NotificationType::CreditLow->defaultChannels())->toContain(NotificationChannel::Mail)
        // Useful, not urgent: an email per finished search would train people to filter us.
        ->and(NotificationType::DiscoveryComplete->defaultChannels())->not->toContain(NotificationChannel::Mail)
        ->and(NotificationType::TopLeads->defaultChannels())->not->toContain(NotificationChannel::Mail)
        ->and(NotificationType::SignalsDetected->defaultChannels())->not->toContain(NotificationChannel::Mail);
});

it('never defaults a type to nothing at all', function (): void {
    foreach (NotificationType::cases() as $type) {
        // A type whose defaults were empty would be a notification nobody ever receives and nobody
        // ever notices is missing.
        expect($type->defaultChannels())->not->toBeEmpty();
    }
});

it('keeps the urgent types out of the digest', function (): void {
    // Holding a reminder until 8am tomorrow would make the reminder useless, and an assignment
    // rude.
    expect(NotificationType::TaskDue->isDigestible())->toBeFalse()
        ->and(NotificationType::LeadAssigned->isDigestible())->toBeFalse()
        ->and(NotificationType::CreditLow->isDigestible())->toBeFalse()
        ->and(NotificationType::Digest->isDigestible())->toBeFalse()
        ->and(NotificationType::DiscoveryComplete->isDigestible())->toBeTrue()
        ->and(NotificationType::TopLeads->isDigestible())->toBeTrue();
});

it('keeps billing to the owners and admins', function (): void {
    expect(NotificationType::CreditLow->isAdminOnly())->toBeTrue()
        ->and(NotificationType::TaskDue->isAdminOnly())->toBeFalse();
});

it('leaves the digest out of the per-type switches', function (): void {
    // It is switched on and off by its own frequency setting; showing it twice would let a member
    // set a frequency and still receive nothing.
    expect(NotificationType::configurable())->not->toContain(NotificationType::Digest)
        ->and(NotificationType::configurable())->toContain(NotificationType::TaskDue);
});

// --- The digest's clock ---------------------------------------------------------------------------

it('is due only at the chosen hour', function (): void {
    $hour = 8;

    expect(DigestFrequency::Daily->isDue(CarbonImmutable::parse('2026-10-28 08:05:00'), $hour, null))->toBeTrue()
        ->and(DigestFrequency::Daily->isDue(CarbonImmutable::parse('2026-10-28 08:59:00'), $hour, null))->toBeTrue()
        ->and(DigestFrequency::Daily->isDue(CarbonImmutable::parse('2026-10-28 09:00:00'), $hour, null))->toBeFalse()
        ->and(DigestFrequency::Daily->isDue(CarbonImmutable::parse('2026-10-28 07:59:00'), $hour, null))->toBeFalse();
});

it('is due weekly only on a Monday', function (): void {
    // 2026-10-26 is a Monday; the 27th is a Tuesday.
    expect(DigestFrequency::Weekly->isDue(CarbonImmutable::parse('2026-10-26 08:10:00'), 8, null))->toBeTrue()
        ->and(DigestFrequency::Weekly->isDue(CarbonImmutable::parse('2026-10-27 08:10:00'), 8, null))->toBeFalse();
});

it('is never due when it is off', function (): void {
    expect(DigestFrequency::Off->isDue(CarbonImmutable::parse('2026-10-28 08:00:00'), 8, null))->toBeFalse();
});

it('will not send twice inside one window', function (): void {
    $now = CarbonImmutable::parse('2026-10-28 08:10:00');

    expect(DigestFrequency::Daily->isDue($now, 8, $now->subMinutes(30)))->toBeFalse()
        ->and(DigestFrequency::Daily->isDue($now, 8, $now->subDay()))->toBeTrue();

    $monday = CarbonImmutable::parse('2026-10-26 08:10:00');

    expect(DigestFrequency::Weekly->isDue($monday, 8, $monday->subDay()))->toBeFalse()
        ->and(DigestFrequency::Weekly->isDue($monday, 8, $monday->subWeek()))->toBeTrue();
});

it('looks back over the window it covers', function (): void {
    $now = CarbonImmutable::parse('2026-10-28 08:00:00');

    expect(DigestFrequency::Daily->windowStart($now)->toDateTimeString())->toBe('2026-10-27 08:00:00')
        ->and(DigestFrequency::Weekly->windowStart($now)->toDateTimeString())->toBe('2026-10-21 08:00:00');
});
