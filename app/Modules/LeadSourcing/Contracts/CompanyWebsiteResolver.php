<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Contracts;

/**
 * Finds the most likely official website for a company that was discovered without one.
 *
 * A Companies House record never carries a website and a Google Places listing often doesn't, yet
 * Enrichment (Phase 8) hangs entirely off a website — no site, no fact extraction, no contacts. This
 * seam lets Enrichment ask for a candidate homepage before it gives up, without taking on the
 * web-search provider itself: the default implementation goes to Serper (ADR 0010), and tests bind a
 * fake.
 *
 * The result is a **candidate only** — the resolver searches, it does not confirm. The caller fetches
 * the page and confirms the company matches before trusting it, because a search can return a
 * competitor or a directory as easily as the company's own site. Implementations return null for a
 * missing name, a disabled or unconfigured provider, or any failure: website resolution is
 * best-effort and must never fail an enrichment run.
 */
interface CompanyWebsiteResolver
{
    /**
     * A candidate homepage URL (scheme + host, e.g. `https://acme.co.uk`) for the company, or null.
     *
     * @param  string  $companyName  The company's name as the lead holds it.
     * @param  string|null  $location  An optional place to disambiguate (locality/region/country).
     */
    public function resolve(string $companyName, ?string $location = null): ?string;
}
