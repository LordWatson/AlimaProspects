<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Models\SignalWatchlist;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalWatchlist>
 */
class SignalWatchlistFactory extends Factory
{
    protected $model = SignalWatchlist::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'name' => 'Accounts I am working',
            'source_kind' => WatchlistSourceKind::Manual,
            'is_active' => true,
            'sync_settings' => null,
            'entries_count' => 0,
            'synced_at' => null,
            'created_by' => null,
        ];
    }

    /**
     * A list mirrored from the team's pipeline — the kind the phase exists for.
     *
     * @param  list<int>  $stages
     * @param  list<int>  $owners
     */
    public function pipeline(array $stages = [], array $owners = []): static
    {
        return $this->state(fn (array $attributes): array => [
            'name' => 'My pipeline',
            'source_kind' => WatchlistSourceKind::Pipeline,
            'sync_settings' => ['stages' => $stages, 'owners' => $owners],
        ]);
    }

    public function paused(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }
}
