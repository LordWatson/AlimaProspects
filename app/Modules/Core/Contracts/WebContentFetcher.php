<?php

declare(strict_types=1);

namespace App\Modules\Core\Contracts;

/**
 * Fetches a public web page as plain, inert text — the single SSRF-safe fetch seam shared across
 * modules (Business Intelligence's background check, Enrichment's fact extraction). Implementations
 * MUST bound time and size, reject any host that resolves off the public internet, and return the
 * page stripped to text: the caller treats everything it returns as untrusted data, never
 * instructions. Config lives in `config/web-fetch.php`.
 */
interface WebContentFetcher
{
    /**
     * Fetch readable text from the given URL. Returns an empty string when the URL is missing,
     * unreachable, disallowed, or yields nothing usable — the caller decides how to handle an empty
     * result (it never throws for an ordinary fetch failure).
     */
    public function fetch(?string $url): string;
}
