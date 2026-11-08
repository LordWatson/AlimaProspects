<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\LeadSourcing\Models\Lead;
use Spatie\LaravelData\Data;

/**
 * A company, on the way to a vendor that might know who works there (PL2).
 *
 * Built from a lead rather than typed: a company we are asking about is always one we already hold.
 */
final class CompanyQuery extends Data
{
    public function __construct(
        public string $name,
        public ?string $domain = null,
        public ?string $companyNumber = null,
        /** ISO-3166 alpha-2, where known. */
        public ?string $country = null,
    ) {}

    public static function forLead(Lead $lead): self
    {
        return new self(
            name: $lead->company_name,
            domain: $lead->domain(),
            companyNumber: $lead->company_number,
            country: $lead->country,
        );
    }

    /**
     * Whether a people-finder has something to key on. A company name alone is usually not enough
     * to be sure which company is meant, so a domain is what makes this answerable.
     */
    public function isAnswerable(): bool
    {
        return $this->domain !== null || $this->companyNumber !== null;
    }
}
