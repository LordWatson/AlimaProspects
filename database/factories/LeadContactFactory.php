<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<LeadContact>
 */
class LeadContactFactory extends Factory
{
    protected $model = LeadContact::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $email = fake()->companyEmail();

        return [
            'team_id' => Team::factory(),
            'lead_id' => Lead::factory(),
            'name' => null,
            'title' => null,
            'email' => $email,
            'phone' => null,
            'email_verification_status' => EmailVerificationStatus::MxFound,
            'email_confidence' => EmailVerificationStatus::MxFound->confidence(),
            'is_role_based' => false,
            'is_personal' => true,
            'source' => SourcedValue::METHOD_AI_EXTRACT,
            'source_url' => 'https://'.fake()->domainName(),
            'method' => SourcedValue::METHOD_AI_EXTRACT,
            'collected_at' => now(),
        ];
    }

    /**
     * A role/business address (info@, sales@) rather than a personal one.
     */
    public function roleBased(): self
    {
        return $this->state(fn (): array => [
            'email' => 'info@'.fake()->domainName(),
            'is_role_based' => true,
            'is_personal' => false,
        ]);
    }
}
