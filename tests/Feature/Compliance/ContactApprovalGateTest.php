<?php

declare(strict_types=1);

use App\Modules\Compliance\Services\SuppressionApprovalGate;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Actions\RevealContactValueAction;
use App\Modules\Enrichment\Contracts\ContactApprovalGate;
use App\Modules\Enrichment\Contracts\ContactNumberScreener;
use App\Modules\Enrichment\Exceptions\EnrichmentException;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Enrichment\Services\AllowAllContactApproval;
use App\Modules\Enrichment\Services\EnrichmentWaterfall;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\ComplianceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * One gate on every path that stores or sells a contact detail (PL5).
 *
 * This file is half behaviour and half **architecture**: the acceptance criterion for PL5 is that
 * `SuppressionChecker` is reached from every outbound path, and the way that stays true is a test
 * over the seam rather than a test per screen. A new path that forgets to ask would pass a
 * behaviour test it never wrote.
 */
it('binds the suppression-backed gate over Enrichment\'s permissive default', function (): void {
    $gate = app(ContactApprovalGate::class);

    expect($gate)->toBeInstanceOf(SuppressionApprovalGate::class)
        ->and($gate)->not->toBeInstanceOf(AllowAllContactApproval::class);
});

it('reaches the gate from every path that stores or reveals a contact', function (): void {
    // Constructor dependencies rather than a behaviour assertion per screen: this is the list that
    // has to grow when PL6's export and PL7's sync arrive, and a path that never asks is a path
    // whose own test would not notice.
    $paths = [
        RevealContactValueAction::class => [ContactApprovalGate::class, ContactNumberScreener::class],
        EnrichmentWaterfall::class => [ContactApprovalGate::class],
    ];

    foreach ($paths as $class => $expected) {
        $parameters = (new ReflectionClass($class))->getConstructor()?->getParameters() ?? [];

        $types = array_map(
            static fn (ReflectionParameter $p): ?string => ($t = $p->getType()) instanceof ReflectionNamedType
                ? $t->getName()
                : null,
            $parameters,
        );

        foreach ($expected as $contract) {
            expect($types)->toContain($contract);
        }
    }
});

it('refuses to charge for an address the team may not use', function (): void {
    [$member, $team] = Fixtures::team();

    $contact = app(TeamContext::class)->run($team, function () use ($team): LeadContact {
        $lead = Lead::factory()->create(['team_id' => $team->getKey()]);

        return LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'email' => 'dana@example.com',
            // A paid vendor found it, so it is masked until somebody spends on it.
            'email_source' => 'vendor',
        ]);
    });

    app(SuppressionList::class)->suppress(
        type: SuppressionType::Email,
        value: 'dana@example.com',
        scope: SuppressionScope::Global,
        reason: SuppressionReason::OptOut,
    );

    // A bill and a dead end is the worst of both outcomes, which is why the gate is asked before
    // the charge rather than after it.
    expect(fn () => app(RevealContactValueAction::class)->email($team, $contact, $member))
        ->toThrow(EnrichmentException::class);

    expect($contact->refresh()->revealed_email_at)->toBeNull();
});

it('treats a suppressed domain as covering everyone at the company', function (): void {
    [, $team] = Fixtures::team();

    app(SuppressionList::class)->suppress(
        type: SuppressionType::Domain,
        value: 'acme.com',
        scope: SuppressionScope::Team,
        reason: SuppressionReason::Manual,
        teamId: (int) $team->getKey(),
    );

    $gate = app(ContactApprovalGate::class);

    // "Stop contacting anyone at this company" has to mean that, including the next person
    // enrichment would find there.
    expect($gate->allowsEmail($team, 'someone-new@acme.com'))->toBeFalse()
        ->and($gate->allowsEmail($team, 'dana@other.com'))->toBeTrue();
});

it('keeps one team\'s own list to itself', function (): void {
    [, $first] = Fixtures::team();
    [, $second] = Fixtures::team();

    app(SuppressionList::class)->suppress(
        type: SuppressionType::Email,
        value: 'dana@example.com',
        scope: SuppressionScope::Team,
        reason: SuppressionReason::Manual,
        teamId: (int) $first->getKey(),
    );

    $gate = app(ContactApprovalGate::class);

    expect($gate->allowsEmail($first, 'dana@example.com'))->toBeFalse()
        ->and($gate->allowsEmail($second, 'dana@example.com'))->toBeTrue();
});
