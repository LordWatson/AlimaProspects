<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Onboarding\Enums\CompanySize;
use App\Modules\Onboarding\Enums\ServedRegion;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BusinessProfile>
 */
class BusinessProfileFactory extends Factory
{
    protected $model = BusinessProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'company_name' => fake()->company(),
            'website' => fake()->url(),
            'industry' => fake()->randomElement(['Marketing', 'SaaS', 'Logistics', 'Hospitality', 'Construction']),
            'company_size' => fake()->randomElement(CompanySize::cases()),
            'locations' => [fake()->city()],
            'regions_served' => [ServedRegion::Uk->value],
            'primary_market' => Market::Uk,
            'products_services' => [fake()->words(3, true)],
            'value_proposition' => fake()->sentence(),
            'pricing_min' => 50000,
            'pricing_max' => 500000,
            'pricing_currency' => Currency::Gbp,
            'customer_examples' => [fake()->company(), fake()->company()],
            'version' => 1,
            'completed_at' => null,
        ];
    }

    /**
     * A fully completed profile.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes): array => [
            'completed_at' => now(),
        ]);
    }
}
