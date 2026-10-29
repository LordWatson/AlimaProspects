<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PipelineStage>
 */
class PipelineStageFactory extends Factory
{
    protected $model = PipelineStage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'team_id' => Team::factory(),
            'name' => Str::title($name),
            'slug' => Str::lower($name),
            'position' => fake()->numberBetween(0, 10),
            'is_default' => false,
            'outcome' => null,
        ];
    }

    public function named(string $name, int $position = 0): self
    {
        return $this->state(fn (): array => [
            'name' => $name,
            'slug' => Str::lower($name),
            'position' => $position,
        ]);
    }

    /** The stage a lead sits in before anyone has decided anything about it. */
    public function default(): self
    {
        return $this->state(fn (): array => ['is_default' => true, 'position' => 0]);
    }

    public function outcome(StageOutcome $outcome): self
    {
        return $this->state(fn (): array => ['outcome' => $outcome]);
    }
}
