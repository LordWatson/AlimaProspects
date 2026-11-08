<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Enums\AttemptOutcome;
use App\Modules\Enrichment\Enums\ProviderCapability;
use App\Modules\Enrichment\Models\EnrichmentAttempt;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrichmentAttempt>
 */
class EnrichmentAttemptFactory extends Factory
{
    protected $model = EnrichmentAttempt::class;

    /**
     * A hit that cost something — the row the margin figures are built from.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'enrichment_run_id' => EnrichmentRun::factory(),
            'lead_id' => Lead::factory(),
            'contact_id' => null,
            'capability' => ProviderCapability::EmailVerify,
            'provider' => 'fake_verifier',
            'outcome' => AttemptOutcome::Hit,
            'status_returned' => 'verified',
            'latency_ms' => 120,
            'cost_micros' => 700,
            'error_code' => null,
        ];
    }

    /**
     * A vendor that looked and did not know. Chargeable, and the row that makes a hit rate honest.
     */
    public function miss(): self
    {
        return $this->state(fn (): array => [
            'outcome' => AttemptOutcome::Miss,
            'status_returned' => null,
        ]);
    }

    public function error(string $code = 'enrichment.provider_unavailable'): self
    {
        return $this->state(fn (): array => [
            'outcome' => AttemptOutcome::Error,
            'status_returned' => null,
            'error_code' => $code,
            // An error can still have cost: the request was made.
            'cost_micros' => 0,
        ]);
    }

    /**
     * A call we never made. Costs nothing, and says nothing about the vendor — which is why it is
     * excluded from a hit rate.
     */
    public function skipped(AttemptOutcome $outcome = AttemptOutcome::SkippedCircuitOpen): self
    {
        return $this->state(fn (): array => [
            'outcome' => $outcome,
            'status_returned' => null,
            'latency_ms' => null,
            'cost_micros' => 0,
        ]);
    }

    public function capability(ProviderCapability $capability, string $provider): self
    {
        return $this->state(fn (): array => ['capability' => $capability, 'provider' => $provider]);
    }
}
