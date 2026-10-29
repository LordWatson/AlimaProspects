<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Contracts;

/**
 * A thin seam over DNS lookups so the free-tier email check (Phase 8) can be driven by a fake in
 * tests — the enrichment pipeline must never depend on real DNS to be testable (CLAUDE.md test
 * rules). The system implementation uses PHP's resolver; a fake returns canned answers.
 */
interface DnsResolver
{
    /**
     * Whether the domain can receive mail — true if it publishes an MX record, or (per RFC 5321's
     * implicit-MX fallback) an A/AAAA record. A lookup failure returns false rather than throwing.
     */
    public function hasMailExchanger(string $domain): bool;
}
