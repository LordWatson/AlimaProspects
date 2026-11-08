<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Enums\EnrichmentRunState;
use App\Modules\Enrichment\Enums\EnrichmentScope;
use App\Modules\Enrichment\Models\EnrichmentRun;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EnrichmentRun>
 */
class EnrichmentRunFactory extends Factory
{
    protected $model = EnrichmentRun::class;

    /**
     * A run in flight, which is what a test usually wants to act on.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'requested_by' => User::factory(),
            'scope' => EnrichmentScope::People,
            'state' => EnrichmentRunState::Queued,
            'started_at' => null,
            'finished_at' => null,
            'summary' => null,
            'error_code' => null,
        ];
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    public function completed(array $summary = []): self
    {
        return $this->state(fn (): array => [
            'state' => EnrichmentRunState::Completed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'summary' => $summary,
        ]);
    }

    /**
     * Stopped before spending — a ceiling, a flag, nothing to do. **Not** a failure, which is the
     * distinction the provider panel depends on.
     */
    public function refused(string $code = 'enrichment.no_contacts'): self
    {
        return $this->state(fn (): array => [
            'state' => EnrichmentRunState::Refused,
            'finished_at' => now(),
            'error_code' => $code,
            'summary' => [],
        ]);
    }

    public function failed(): self
    {
        return $this->state(fn (): array => [
            'state' => EnrichmentRunState::Failed,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
            'error_code' => 'enrichment.run_failed',
        ]);
    }

    public function scope(EnrichmentScope $scope): self
    {
        return $this->state(fn (): array => ['scope' => $scope]);
    }
}
