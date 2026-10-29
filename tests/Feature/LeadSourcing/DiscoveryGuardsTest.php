<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\LeadSourcing\Contracts\LeadInterestGate;
use App\Modules\LeadSourcing\Contracts\LeadQuotaSource;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\LeadQuota;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Services\CompaniesHouseProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('refuses a run and returns a 429 envelope when the lead allowance is exhausted', function (): void {
    $user = createUserWithTeam();

    app()->instance(LeadQuotaSource::class, new class implements LeadQuotaSource
    {
        public function quotaFor(Team $team): LeadQuota
        {
            return new LeadQuota(limit: 10, used: 10);
        }
    });

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['companies_house']])
        ->assertStatus(429)
        ->assertJsonPath('error.code', 'lead_sourcing.quota_exhausted');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('runs the LIA gate before creating a run', function (): void {
    $user = createUserWithTeam();

    app()->instance(LeadInterestGate::class, new class implements LeadInterestGate
    {
        public function assertDiscoveryAllowed(Team $team): void
        {
            throw LeadSourcingException::interestAssessmentRequired();
        }
    });

    $this->actingAs($user, 'sanctum')
        ->postJson(route('api.v1.lead-sourcing.runs.store'), ['sources' => ['companies_house']])
        ->assertStatus(403)
        ->assertJsonPath('error.code', 'lead_sourcing.lia_required');

    expect(DiscoveryRun::query()->count())->toBe(0);
});

it('refuses a provider call while the circuit is open, without sending a request', function (): void {
    config()->set('lead-sourcing.providers.companies_house.api_key', 'test-key');

    // Trip the breaker past its threshold (config default 5).
    $cb = app(CircuitBreaker::class);
    foreach (range(1, 5) as $ignored) {
        $cb->recordFailure('companies_house');
    }

    Http::fake();

    expect(fn () => app(CompaniesHouseProvider::class)->discover(
        new DiscoveryQuery(source: LeadSource::CompaniesHouse, terms: 'marketing', limit: 25),
    ))->toThrow(LeadSourcingException::class);

    Http::assertNothingSent();
});
