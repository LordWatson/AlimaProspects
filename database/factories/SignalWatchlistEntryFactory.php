<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalWatchlistEntry>
 */
class SignalWatchlistEntryFactory extends Factory
{
    protected $model = SignalWatchlistEntry::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'signal_watchlist_id' => SignalWatchlist::factory(),
            'entity_type' => WatchlistEntityType::Company,
            // `normalised_name` is set by the model's mutator from this, which is also what the
            // unique index is on — a factory that set it by hand could store a pair that disagrees.
            'name' => 'Acme Engineering Ltd',
            'aliases' => null,
            'domain' => null,
            'lead_id' => null,
            'is_active' => true,
        ];
    }

    /** An entry the pipeline sync created: a name that **is** one of the team's leads. */
    public function forLead(Lead $lead): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $lead->team_id,
            'name' => $lead->company_name,
            'lead_id' => $lead->getKey(),
            'domain' => $lead->domain(),
        ]);
    }

    public function person(string $name = 'Jane Founder'): static
    {
        return $this->state(fn (array $attributes): array => [
            'entity_type' => WatchlistEntityType::Person,
            'name' => $name,
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
