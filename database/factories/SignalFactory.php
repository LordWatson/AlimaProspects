<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Signal>
 */
class SignalFactory extends Factory
{
    protected $model = Signal::class;

    /**
     * `signal_definition_id` is **null by default** and `event_key` carries the type, which is the
     * shape a signal whose definition has been deleted has (ADR 0018 §4) - the harder case, and so
     * the better default for a fixture. A test that needs the live attribution asks for it with
     * {@see self::forDefinition()}.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $url = $this->faker->unique()->url();

        return [
            'team_id' => Team::factory(),
            'signal_watch_id' => SignalWatch::factory(),
            'signal_run_id' => SignalRun::factory(),
            'signal_definition_id' => null,
            'event_key' => SignalPresetCatalogue::FUNDING_ROUND,
            'status' => SignalStatus::New,
            'dismiss_reason' => null,
            'headline' => 'Dubai startup closes a Series A funding round',
            'summary' => 'A Dubai-based startup has raised a Series A round led by regional investors.',
            'subject_person_name' => 'Jane Founder',
            'subject_person_role' => 'Founder & CEO',
            'subject_company_name' => 'Acme DMCC',
            'counterparty_name' => null,
            'amount_minor' => 4_000_000_00,
            'currency' => Currency::Usd,
            'location' => 'Dubai',
            'confidence' => 80,
            'relevance' => 70,
            'suggested_approach' => 'Introduce yourself on LinkedIn or via a mutual connection.',
            'source_url' => $url,
            'source_name' => 'Gulf Business',
            'published_at' => now()->subDays(2),
            'url_hash' => sha1($url),
            'raw' => [
                'title' => 'Dubai startup closes a Series A funding round',
                'snippet' => 'A Dubai-based startup has raised a Series A round led by regional investors.',
                'link' => $url,
                'source' => 'Gulf Business',
                'date' => '2 days ago',
            ],
            'lead_id' => null,
            'actioned_by' => null,
            'actioned_at' => null,
        ];
    }

    public function acquisition(): static
    {
        return $this->state(fn (array $attributes): array => [
            'event_key' => SignalPresetCatalogue::ACQUISITION,
            'headline' => 'Dubai company acquired in a regional deal',
            'counterparty_name' => 'Regional Holdings PJSC',
        ]);
    }

    /**
     * Attributed to a real signal definition: both the FK and the key, so the row is the shape a
     * run actually writes.
     */
    public function forDefinition(SignalDefinition $definition): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => $definition->team_id,
            'signal_definition_id' => $definition->getKey(),
            'event_key' => $definition->key,
        ]);
    }

    /**
     * A signal of a type the team wrote themselves, keyed by whatever that type's key is.
     */
    public function withEventKey(string $key): static
    {
        return $this->state(fn (array $attributes): array => ['event_key' => $key]);
    }

    public function dismissed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalStatus::Dismissed,
            'dismiss_reason' => SignalDismissReason::NotRelevant,
            'actioned_at' => now(),
        ]);
    }

    public function converted(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => SignalStatus::Converted,
            'actioned_at' => now(),
        ]);
    }
}
