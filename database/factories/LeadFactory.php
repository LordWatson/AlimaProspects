<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Enums\LeadStatus;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->company();
        $number = (string) fake()->numberBetween(1_000_000, 9_999_999);

        return [
            'team_id' => Team::factory(),
            'discovery_run_id' => null,
            'source' => LeadSource::CompaniesHouse,
            'status' => LeadStatus::Discovered,
            'company_name' => $name,
            'company_number' => $number,
            'company_status' => 'active',
            'company_type' => 'ltd',
            'incorporated_on' => fake()->dateTimeBetween('-15 years', '-1 year')->format('Y-m-d'),
            'website' => null,
            'locality' => fake()->city(),
            'region' => null,
            'postal_code' => fake()->postcode(),
            'country' => 'GB',
            'address' => ['locality' => fake()->city()],
            'sic_codes' => [],
            'source_url' => 'https://find-and-update.company-information.service.gov.uk/company/'.$number,
            'collected_at' => now(),
            'raw' => ['title' => $name, 'company_number' => $number],
            'dedupe_key' => 'companies_house:no:'.Str::lower($number),
        ];
    }
}
