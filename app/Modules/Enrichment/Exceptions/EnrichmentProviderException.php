<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Exceptions;

use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Enrichment\Data\EnrichmentResult;

/**
 * A paid provider **failed** — as distinct from finding nothing, which is an
 * {@see EnrichmentResult} with a null value and not an error at all.
 *
 * Rendered centrally (bootstrap/app.php) with a friendly message and a stable code. A member sees
 * a provider failure at most as "Alima couldn't look just now"; which vendor, and why, is the
 * operator's business and goes to the logs and to PL3's attempt record.
 *
 * **Which vendor failed stays out of the response envelope.** It is readable from
 * {@see self::$provider} for the log and the attempt record, and deliberately not in `details`,
 * which is for what a *client* can act on.
 *
 * Each cause is a named constructor so the code is set in one place, and the three are genuinely
 * different decisions for the caller: a **misconfiguration** will fail identically on every retry,
 * a **transport** failure is worth trying the next provider for, and an **unreadable response**
 * means our mapping is wrong and somebody has to look — in which case every result already stored
 * from that vendor is suspect.
 */
final class EnrichmentProviderException extends DomainException
{
    protected string $errorCode = 'enrichment.provider_failed';

    /** The provider's config key. Set by every constructor below; 'unknown' is unreachable. */
    public private(set) string $provider = 'unknown';

    /** Whatever the vendor said about the failure, for the log only. Never a message to a member. */
    public private(set) ?string $detail = null;

    /**
     * No API key, or a key the vendor rejected.
     *
     * Not retryable — and not the next provider's problem either, but the waterfall carries on,
     * because one unconfigured vendor must not take the others down with it.
     */
    public static function notConfigured(string $provider): self
    {
        $e = new self('Alima can’t reach one of its data providers. We’re on it.');
        $e->errorCode = 'enrichment.provider_not_configured';
        $e->provider = $provider;

        return $e;
    }

    /**
     * The vendor was unreachable, slow past its timeout, or returned a 5xx after its retries.
     */
    public static function unavailable(string $provider, ?string $detail = null): self
    {
        $e = new self('Alima couldn’t look that up just now. Try again in a moment.');
        $e->errorCode = 'enrichment.provider_unavailable';
        $e->provider = $provider;
        $e->detail = $detail;

        return $e;
    }

    /**
     * The vendor answered with something our adapter cannot read.
     */
    public static function unreadable(string $provider, ?string $detail = null): self
    {
        $e = new self('Alima couldn’t read a reply from one of its data providers.');
        $e->errorCode = 'enrichment.provider_unreadable';
        $e->provider = $provider;
        $e->detail = $detail;

        return $e;
    }
}
