<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\LeadReadiness;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * Lead quality Q4: a lead has two numbers, and readiness is the one allowed to punish a gap.
 *
 * @param  array<string, mixed>  $attributes
 * @param  list<array<string, mixed>>  $contacts
 */
function readinessOf(array $attributes, array $contacts = []): ?int
{
    $team = Team::factory()->create();

    return app(TeamContext::class)->run($team, function () use ($team, $attributes, $contacts): ?int {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'company_number' => null,
            'website' => null,
            'industry' => null,
            'employee_range' => null,
            'technologies' => null,
            'locality' => null,
            'region' => null,
            'postal_code' => null,
            'country' => null,
            'locations' => null,
            ...$attributes,
        ]);

        foreach ($contacts as $contact) {
            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                ...$contact,
            ]);
        }

        return app(LeadReadiness::class)->for($lead);
    });
}

it('gives a bare lead no figure at all rather than a zero', function (): void {
    // "Not computed" and "nothing to reach them by" are different states, and a zero reads as the
    // second. The same reason Scoring writes no row for a lead it could assess nothing about.
    expect(readinessOf(['company_name' => 'Acme']))->toBeNull();
});

it('scores a fully reachable lead 100', function (): void {
    $readiness = readinessOf([
        'website' => 'https://acme.test',
        'industry' => 'Coffee roasting',
        'employee_range' => '11-50',
        'locality' => 'London',
    ], [[
        'email' => 'jane@acme.test',
        'email_verification_status' => EmailVerificationStatus::MxFound,
        'is_role_based' => false,
    ]]);

    expect($readiness)->toBe(100);
});

it('marks down a company we know all about but cannot write to', function (): void {
    $full = readinessOf([
        'website' => 'https://acme.test',
        'industry' => 'Coffee roasting',
        'employee_range' => '11-50',
        'locality' => 'London',
    ]);

    // The whole point of the figure: a complete company record with nobody to contact is not a
    // lead you can work, so the three contact factors outweigh the four company ones.
    expect($full)->not->toBeNull()
        ->and($full)->toBeLessThan(50);
});

it('counts a role mailbox as a contact but not as a named one', function (): void {
    $contact = ['email' => 'info@acme.test', 'email_verification_status' => EmailVerificationStatus::MxFound, 'is_role_based' => true];

    $role = readinessOf(['website' => 'https://acme.test'], [$contact]);
    $named = readinessOf(['website' => 'https://acme.test'], [[...$contact, 'email' => 'jane@acme.test', 'is_role_based' => false]]);

    expect($named)->toBeGreaterThan($role);
});

it('counts an unroutable email as a contact but not a routable one', function (): void {
    $routable = readinessOf([], [[
        'email' => 'jane@acme.test',
        'email_verification_status' => EmailVerificationStatus::MxFound,
        'is_role_based' => false,
    ]]);

    $not = readinessOf([], [[
        'email' => 'jane@acme.test',
        'email_verification_status' => EmailVerificationStatus::NoMxRecord,
        'is_role_based' => false,
    ]]);

    expect($routable)->toBeGreaterThan($not);
});

it('accepts an extracted operating location when there is no postal address', function (): void {
    // Q1's `locations` is the only location a web-sourced lead ever has, so without them such a
    // lead's location factor could never be earned.
    $withLocations = readinessOf(['website' => 'https://acme.test', 'locations' => ['London', 'Manchester']]);
    $without = readinessOf(['website' => 'https://acme.test']);

    expect($withLocations)->toBeGreaterThan($without);
});

it('lets a weight of zero switch a factor off', function (): void {
    config()->set('enrichment.readiness_weights.industry', 0);

    // With industry out of the reckoning, a lead that has only an industry has nothing that counts
    // — but it is still a lead we hold something about, so it gets a figure rather than null.
    expect(readinessOf(['industry' => 'Coffee roasting']))->toBe(0);
});

it('gives no figure when every weight is switched off', function (): void {
    config()->set('enrichment.readiness_weights', []);

    expect(readinessOf(['website' => 'https://acme.test', 'industry' => 'Coffee']))->toBeNull();
});
