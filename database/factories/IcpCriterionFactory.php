<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IcpCriterion>
 */
class IcpCriterionFactory extends Factory
{
    protected $model = IcpCriterion::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'icp_id' => Icp::factory(),
            'team_id' => Icp::factory(),
            'type' => CriterionType::Industry,
            'value' => fake()->randomElement(['SaaS', 'Logistics', 'Hospitality', 'Marketing']),
            'weight' => fake()->numberBetween(20, 90),
            'position' => 0,
        ];
    }

    public function configure(): static
    {
        // Keep the criterion in the same team as its ICP unless the caller set one explicitly.
        return $this->afterMaking(function (IcpCriterion $criterion): void {
            $icp = $criterion->icp()->withoutGlobalScopes()->first();

            if ($icp !== null) {
                $criterion->team_id = $icp->team_id;
            }
        });
    }

    /**
     * A hard exclusion (no weight).
     */
    public function exclusion(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => CriterionType::Exclusion,
            'value' => fake()->domainName(),
            'weight' => null,
        ]);
    }
}
