<?php

declare(strict_types=1);

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Http\Middleware\ApiVersion;
use App\Modules\Core\Http\Requests\PaginatedIndexRequest;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

/*
 * The two promises a client builds its plumbing on (Phase 17): which version answered, and what a
 * page looks like.
 */

uses(RefreshDatabase::class);

it('stamps the version on an answer', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    $this->actingAs($user)
        ->getJson(route('api.v1.leads.index'))
        ->assertOk()
        ->assertApiVersion();
});

it('stamps the version on a failure too', function (): void {
    // A client that reads the header to decide how to parse the body needs it most when the body
    // is an error.
    $this->getJson(route('api.v1.leads.index'))
        ->assertUnauthorized()
        ->assertApiVersion();
});

it('accepts a client pinning the version it was written against', function (): void {
    [$user] = Fixtures::team();

    foreach (['1', 'v1', ' v1 '] as $pin) {
        $this->actingAs($user)
            ->withHeader(ApiVersion::HEADER, $pin)
            ->getJson(route('api.v1.leads.stages'))
            ->assertOk()
            ->assertApiVersion();
    }
});

it('refuses a client pinned to a version it does not serve', function (): void {
    [$user] = Fixtures::team();

    // Better than a best-effort answer: a client that asked for v2 and silently got v1 would
    // misread every response it then parsed.
    $response = $this->actingAs($user)
        ->withHeader(ApiVersion::HEADER, '2')
        ->getJson(route('api.v1.leads.stages'));

    $response->assertApiError(ApiErrorCode::UnsupportedApiVersion, 400);

    expect($response->json('error.details.supported'))->toBe([ApiVersion::VERSION]);
});

it('pages the lists that grow, with the same envelope everywhere', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'First', 'dedupe_key' => 'no:first']);
    Fixtures::lead($team, ['company_name' => 'Second', 'dedupe_key' => 'no:second']);

    app(TeamContext::class)->run($team, function () use ($team): void {
        DiscoveryRun::factory()->count(2)->create(['team_id' => $team->getKey()]);
    });

    $paged = [
        route('api.v1.leads.index'),
        route('api.v1.lead-sourcing.runs.index'),
        route('api.v1.signals.index'),
        route('api.v1.notifications.index'),
        route('api.v1.insights.index'),
        route('api.v1.outreach.suppressions.index'),
    ];

    foreach ($paged as $url) {
        $this->actingAs($user)
            ->getJson($url.'?per_page=1')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'last_page', 'per_page', 'total'],
            ])
            ->assertJsonPath('meta.per_page', 1);
    }
});

it('caps the page size on every list that accepts one', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    $beyond = PaginatedIndexRequest::MAX_PER_PAGE + 1;

    // Without a ceiling, `per_page` is a way to ask for the whole table in one query - an export
    // nobody authorised, at the database's expense.
    foreach ([
        route('api.v1.leads.index'),
        route('api.v1.lead-sourcing.runs.index'),
        route('api.v1.signals.index'),
        route('api.v1.outreach.suppressions.index'),
    ] as $url) {
        $this->actingAs($user)
            ->getJson($url.'?per_page='.$beyond)
            ->assertApiValidationErrors('per_page');
    }
});

it('returns the bounded lists without a page, and says so once', function (): void {
    [$user] = Fixtures::team();

    // These cannot grow unboundedly - a member's teams, tokens and handsets, a team's stages and
    // ICPs - so they answer with `data` alone. A client has to know which is which; this is the
    // list, and the OpenAPI document is where it is written down.
    foreach ([
        route('api.v1.teams.index'),
        route('api.v1.auth.tokens.index'),
        route('api.v1.push-devices.index'),
        route('api.v1.leads.stages'),
        route('api.v1.icps.index'),
    ] as $url) {
        $this->actingAs($user)
            ->getJson($url)
            ->assertOk()
            ->assertJsonStructure(['data'])
            ->assertJsonMissingPath('meta')
            ->assertJsonMissingPath('links');
    }
});
