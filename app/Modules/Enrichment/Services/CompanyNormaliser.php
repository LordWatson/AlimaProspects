<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Support\Str;

/**
 * Light, deterministic clean-up of a lead's own company fields at the start of enrichment (Phase 8):
 * canonicalises the website to a scheme-qualified URL (dropping anything that isn't http(s)),
 * normalises the country to an upper-case ISO-3166 alpha-2, and trims the company name. It only ever
 * tidies values already on the record — it adds no new facts (AI extraction does that) — so it needs
 * no source attribution of its own.
 */
final class CompanyNormaliser
{
    /**
     * Return the attributes that should change on the lead, or an empty array if nothing needs it.
     *
     * @return array<string, mixed>
     */
    public function normalize(Lead $lead): array
    {
        $changes = [];

        $website = $this->canonicalUrl($lead->website);
        if ($website !== $lead->website) {
            $changes['website'] = $website;
        }

        $name = trim((string) $lead->company_name);
        if ($name !== '' && $name !== $lead->company_name) {
            $changes['company_name'] = $name;
        }

        if ($lead->country !== null) {
            $country = Str::upper(Str::substr(trim($lead->country), 0, 2));
            if ($country !== $lead->country) {
                $changes['country'] = $country;
            }
        }

        return $changes;
    }

    /**
     * A scheme-qualified https URL for a bare domain, the value unchanged if already http(s), or null
     * for anything non-http (so a `javascript:`/junk value is never kept). Reused when applying an
     * AI-extracted website.
     */
    public function canonicalUrl(?string $website): ?string
    {
        $website = trim((string) $website);
        if ($website === '') {
            return null;
        }

        if (Str::startsWith(Str::lower($website), ['http://', 'https://'])) {
            return $website;
        }

        // A bare domain with no scheme — safe to assume https. Anything with a different scheme is
        // rejected (returns null) rather than silently coerced.
        if (Str::contains($website, ':')) {
            return null;
        }

        return 'https://'.$website;
    }
}
