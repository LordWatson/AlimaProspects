<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Support\SignalPreset;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SignalDefinition>
 */
class SignalDefinitionFactory extends Factory
{
    protected $model = SignalDefinition::class;

    /**
     * The default is v1's funding-round type, because that is what most of the suite's fixtures
     * mean by "a signal type" — the same shape the `SignalFactory` builds a signal for.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'key' => SignalPresetCatalogue::FUNDING_ROUND,
            'name' => 'Funding round',
            'description' => 'A business raises a funding round, putting fresh capital in founders’ hands.',
            'category' => SignalCategory::Growth,
            'icon' => SignalIcon::TrendingUp,
            'is_active' => true,
            'query_templates' => [
                '{location} startup raises funding',
                'UAE Series B funding',
                '{location} startup Series A funding round',
            ],
            'match_keywords' => ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
            'classification_rules' => [
                'includes' => ['a named company closing a named round'],
                'excludes' => ['funding trackers and weekly round-ups'],
            ],
            'thresholds' => ['min_confidence' => null, 'min_relevance' => null],
            'suggested_approach_hint' => null,
            'markets' => [Market::Uae->value],
            'preset_key' => SignalPresetCatalogue::FUNDING_ROUND,
            'preset_version' => 1,
            'version' => 1,
            'created_by' => null,
        ];
    }

    /**
     * A platform preset: no team, and the flag that stops `BelongsToTeam` handing it whichever team
     * happened to be active (see {@see SignalDefinition::$isPlatformPreset}).
     */
    public function preset(): static
    {
        return $this->state(fn (array $attributes): array => [
            'team_id' => null,
            'preset_key' => null,
            'preset_version' => null,
        ])->afterMaking(static function (SignalDefinition $definition): void {
            $definition->isPlatformPreset = true;
        });
    }

    /**
     * One of the catalogue's presets, by key — as a team's own clone unless {@see self::preset()} is
     * chained on. This is how a test says "the team has the acquisition type" without restating its
     * templates.
     */
    public function fromCatalogue(string $key): static
    {
        $preset = collect(SignalPresetCatalogue::all())
            ->firstOrFail(static fn (SignalPreset $candidate): bool => $candidate->key === $key);

        return $this->state(function (array $attributes) use ($preset): array {
            $row = $preset->toRow();

            // The team (or the lack of one) is the caller's business, not the catalogue's.
            unset($row['team_id']);

            return [
                ...$row,
                'preset_key' => $preset->key,
                'preset_version' => $preset->version,
            ];
        });
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes): array => ['is_active' => false]);
    }

    /**
     * A type the team wrote themselves: no provenance, no market restriction, its own category.
     */
    public function custom(string $key = 'hiring_surge', string $name = 'Hiring surge'): static
    {
        return $this->state(fn (array $attributes): array => [
            'key' => $key,
            'name' => $name,
            'description' => 'A business is hiring hard, which says where it is investing.',
            'category' => SignalCategory::Hiring,
            'icon' => SignalIcon::Users,
            'query_templates' => ['{location} company hiring surge'],
            'match_keywords' => ['hiring', 'recruit'],
            'markets' => null,
            'preset_key' => null,
            'preset_version' => null,
        ]);
    }

    /**
     * @param  array{min_confidence?: int|null, min_relevance?: int|null}  $thresholds
     */
    public function withThresholds(array $thresholds): static
    {
        return $this->state(fn (array $attributes): array => [
            'thresholds' => [
                'min_confidence' => $thresholds['min_confidence'] ?? null,
                'min_relevance' => $thresholds['min_relevance'] ?? null,
            ],
        ]);
    }
}
