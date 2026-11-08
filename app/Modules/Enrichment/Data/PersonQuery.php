<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Models\LeadContact;
use Spatie\LaravelData\Data;

/**
 * Everything we know about a person, on the way to a vendor that might know their email or their
 * number (PL2).
 *
 * Every field is optional because every vendor wants a different subset, and the subset it wants is
 * not something a caller should have to know: one of them keys on `domain` + first/last, another on
 * a LinkedIn URL alone, a third on a full name and a company name. The adapter takes what it needs
 * and {@see self::isAnswerable()} is how it refuses, cheaply, before spending anything.
 */
final class PersonQuery extends Data
{
    public function __construct(
        public ?string $firstName = null,
        public ?string $lastName = null,
        public ?string $fullName = null,
        public ?string $companyName = null,
        /** The registrable domain — what most finders key on, and the one field worth having. */
        public ?string $domain = null,
        public ?string $linkedinUrl = null,
        /** ISO-3166 alpha-2, where known. Some phone finders need it to disambiguate a number. */
        public ?string $country = null,
    ) {}

    /**
     * Build the query from a contact we already hold, which is the ordinary case: the lead gave us
     * the company and a name, and the vendor is being asked for the part we are missing.
     */
    public static function forContact(LeadContact $contact): self
    {
        $lead = $contact->lead;

        return new self(
            firstName: $contact->first_name,
            lastName: $contact->last_name,
            fullName: $contact->name,
            companyName: $lead?->company_name,
            domain: $lead?->domain(),
            linkedinUrl: $contact->linkedin_url,
            country: $lead?->country,
        );
    }

    /**
     * Whether there is enough here for **any** finder to work with: somebody's name, and somewhere
     * to look for them.
     *
     * Asked before a provider is called rather than after it answers, because a vendor that bills
     * per attempt charges for a question we could have known was unanswerable.
     */
    public function isAnswerable(): bool
    {
        $hasName = $this->fullName !== null
            || ($this->firstName !== null && $this->lastName !== null)
            || $this->linkedinUrl !== null;

        $hasCompany = $this->domain !== null || $this->companyName !== null || $this->linkedinUrl !== null;

        return $hasName && $hasCompany;
    }

    /**
     * The person's name as one string, however it was supplied.
     */
    public function name(): ?string
    {
        if ($this->fullName !== null && trim($this->fullName) !== '') {
            return trim($this->fullName);
        }

        $parts = array_filter([$this->firstName, $this->lastName]);

        return $parts === [] ? null : trim(implode(' ', $parts));
    }
}
