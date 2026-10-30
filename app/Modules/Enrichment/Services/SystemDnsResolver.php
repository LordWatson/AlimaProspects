<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Services;

use App\Modules\Enrichment\Contracts\DnsResolver;

/**
 * The production {@see DnsResolver}: a bounded, read-only DNS lookup using PHP's resolver. Used by
 * the {@see EmailVerifier} to confirm a domain can receive mail. It never connects to the mail
 * server (no SMTP probe in the MVP — ADR 0008); MX presence proves routability, not deliverability.
 */
final class SystemDnsResolver implements DnsResolver
{
    public function hasMailExchanger(string $domain): bool
    {
        $domain = trim($domain);

        if ($domain === '') {
            return false;
        }

        // Primary signal: an MX record. Fall back to A/AAAA (RFC 5321 implicit MX) so a small domain
        // that accepts mail on its own host isn't wrongly marked unroutable.
        return checkdnsrr($domain, 'MX')
            || checkdnsrr($domain, 'A')
            || checkdnsrr($domain, 'AAAA');
    }
}
