<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Contracts;

/**
 * Fetches a business's public web presence as plain text for the background check. A deliberately
 * minimal seam (Phase 6): the Lead Sourcing web-search provider (Phase 7b) can later back this with
 * a richer multi-page crawl. Implementations MUST bound time and size and MUST return the page as
 * inert text — the caller treats everything it returns as untrusted data, never instructions.
 */
interface WebContentFetcher
{
    /**
     * Fetch readable text from the given URL. Returns an empty string when the URL is missing,
     * unreachable, or yields nothing usable — the caller decides how to handle an empty result
     * (it never throws for an ordinary fetch failure).
     */
    public function fetch(?string $url): string;
}
