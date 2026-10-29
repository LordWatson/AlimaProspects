<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Lead Sourcing, rendered centrally (bootstrap/app.php): a friendly message for
 * the user, a stable machine code for the API envelope. Each cause is a named constructor so the
 * code is set in one place.
 */
final class LeadSourcingException extends DomainException
{
    protected string $errorCode = 'lead_sourcing.invalid';

    /**
     * The selected provider isn't live in this build.
     */
    public static function providerUnavailable(string $source): self
    {
        $e = new self('That lead source isn’t available yet.');
        $e->errorCode = 'lead_sourcing.provider_unavailable';

        return $e;
    }

    /**
     * No provider is registered for the requested source.
     */
    public static function unknownProvider(string $source): self
    {
        $e = new self('We don’t recognise that lead source.');
        $e->errorCode = 'lead_sourcing.unknown_provider';

        return $e;
    }

    /**
     * There's nothing to search with — no terms given and the ICP implies none.
     */
    public static function nothingToSearch(): self
    {
        $e = new self('Add some search terms, or give your active ideal-customer profile an industry signal, so Alima knows what to look for.');
        $e->errorCode = 'lead_sourcing.nothing_to_search';

        return $e;
    }

    /**
     * The team has used up its lead allowance for the period.
     */
    public static function quotaExhausted(): self
    {
        $e = new self('You’ve reached your lead allowance for this period. It resets at the start of your next cycle, or you can upgrade your plan.');
        $e->errorCode = 'lead_sourcing.quota_exhausted';
        $e->status = 429;

        return $e;
    }

    /**
     * Discovery is blocked because the team has no lawful basis on record for its market.
     */
    public static function interestAssessmentRequired(): self
    {
        $e = new self('Before sourcing leads, your team needs a legitimate-interest assessment on record for this market.');
        $e->errorCode = 'lead_sourcing.lia_required';
        $e->status = 403;

        return $e;
    }

    /**
     * The upstream provider failed (timeout, error, or tripped circuit breaker).
     */
    public static function providerFailed(string $source): self
    {
        $e = new self('We couldn’t reach that lead source. Please try again shortly.');
        $e->errorCode = 'lead_sourcing.provider_failed';
        $e->status = 502;

        return $e;
    }
}
