<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\ContactIdentity;
use App\Modules\Enrichment\Data\SourcedValue;
use App\Modules\Enrichment\Enums\ContactEnrichmentState;
use App\Modules\Enrichment\Enums\EmailVerificationStatus;
use App\Modules\Enrichment\Enums\PhoneType;
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
            'first_name' => null,
            'last_name' => null,
            'title' => null,
            'department' => null,
            'seniority' => null,
            'linkedin_url' => null,
            'email' => $email,
            // Written alongside the address, never derived later: a contact whose hash is missing
            // matches nothing on the do-not-contact list (PL2).
            'email_hash' => ContactIdentity::hash((string) ContactIdentity::email($email)),
            // Null source: the default contact is one the free-tier extraction read off a public
            // page, which is what the vast majority of them are and which needs **no reveal**.
            'email_source' => null,
            'phone' => null,
            'email_verification_status' => EmailVerificationStatus::MxFound,
            'email_confidence' => EmailVerificationStatus::MxFound->confidence(),
            'is_role_based' => false,
            'is_personal' => true,
            'is_inferred' => false,
            'source' => SourcedValue::METHOD_AI_EXTRACT,
            'source_url' => 'https://'.fake()->domainName(),
            'method' => SourcedValue::METHOD_AI_EXTRACT,
            'collected_at' => now(),
            'enrichment_state' => ContactEnrichmentState::Idle,
        ];
    }

    /**
     * A contact a **paid vendor** produced — so its address is masked until somebody reveals it.
     *
     * The state most tests of the reveal rule want, and the one a factory makes easy to get wrong:
     * the difference between this and the default is one nullable column, and that column is what
     * decides whether a member can read the address.
     */
    public function fromVendor(string $provider = 'zerobounce'): self
    {
        return $this->state(fn (): array => [
            'email_source' => $provider,
            'email_verification_status' => EmailVerificationStatus::Verified,
            'email_confidence' => EmailVerificationStatus::Verified->confidence(),
            'email_verified_at' => now(),
            'enrichment_state' => ContactEnrichmentState::Done,
            'enriched_at' => now(),
        ]);
    }

    /**
     * Already paid for and already revealed — what a contact looks like after PL4 charged for it.
     */
    public function revealed(): self
    {
        return $this->fromVendor()->afterCreating(static function (LeadContact $contact): void {
            // `forceFill`, because a reveal is not fillable: only the Action that charged for it
            // may claim one happened.
            $contact->forceFill(['revealed_email_at' => now()])->save();
        });
    }

    /**
     * A named person with a mobile, the way a people-finder plus a phone-finder leaves one.
     */
    public function withMobile(string $number = '+447700904821'): self
    {
        return $this->state(fn (): array => [
            'name' => 'Jane Doe',
            'first_name' => 'Jane',
            'last_name' => 'Doe',
            'title' => 'Operations Director',
            'phone' => $number,
            'phone_e164' => $number,
            'phone_hash' => ContactIdentity::hash((string) ContactIdentity::phone($number)),
            'phone_type' => PhoneType::Mobile,
            'phone_source' => 'somevendor',
            'phone_found_at' => now(),
        ]);
    }

    /**
     * A role/business address (info@, sales@) rather than a personal one.
     */
    public function roleBased(): self
    {
        return $this->state(function (): array {
            $email = 'info@'.fake()->domainName();

            return [
                'email' => $email,
                'email_hash' => ContactIdentity::hash((string) ContactIdentity::email($email)),
                'is_role_based' => true,
                'is_personal' => false,
            ];
        });
    }
}
