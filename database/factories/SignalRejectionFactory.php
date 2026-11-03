<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalRejectionVerdict;
use App\Modules\Signals\Models\SignalRejection;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalRejection>
 */
class SignalRejectionFactory extends Factory
{
    protected $model = SignalRejection::class;

    /**
     * Inside the window by default — `last_seen_at` is now — because "a rejection that is still
     * honoured" is the state nearly every test is about. {@see self::expired()} is the other one.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'dedupe_hash' => sha1($this->faker->unique()->uuid()),
            'definition_fingerprint' => sha1('acquisition'),
            'verdict' => SignalRejectionVerdict::NotEvent,
            'seen_count' => 1,
            'first_seen_at' => now(),
            'last_seen_at' => now(),
        ];
    }

    public function belowThreshold(): static
    {
        return $this->state(fn (array $attributes): array => [
            'verdict' => SignalRejectionVerdict::BelowThreshold,
        ]);
    }

    /**
     * Past the retention window, so a run classifies the article again and the sweep deletes the
     * row. Measured from `last_seen_at`, which is the column both of those read.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes): array => [
            'first_seen_at' => now()->subDays(120),
            'last_seen_at' => now()->subDays(90),
        ]);
    }
}
