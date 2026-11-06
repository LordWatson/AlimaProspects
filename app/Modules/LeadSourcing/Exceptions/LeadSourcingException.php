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
     * Whether a queued run that hit this error is worth retrying. A transient provider failure is;
     * a configuration / unavailable-provider error never will be, so the job fails fast instead of
     * burning its retries.
     */
    protected bool $retryable = false;

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

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
     * The provider is missing something an operator has to supply — an API key for the HTTP
     * providers, the seeded prompt template for the AI one — and is therefore **never retryable**.
     *
     * The message deliberately no longer names the API key. A provider can be unconfigured in more
     * than one way, and the first time this fired for the AI source the cause was an unseeded
     * prompt row: "an administrator needs to add its API key" sent the reader looking for a key
     * that was already there. Which piece is missing is in the log, with its error code.
     */
    public static function providerMisconfigured(string $source): self
    {
        $e = new self('That lead source isn’t set up yet. An administrator needs to finish setting it up.');
        $e->errorCode = 'lead_sourcing.provider_misconfigured';
        $e->status = 503;

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
     * The team has an assessment on record, but it is past the review date they set. Separate from
     * {@see self::interestAssessmentRequired()} because the member has to be told a different
     * thing: re-affirm what is there, not write it from scratch.
     */
    public static function interestAssessmentExpired(): self
    {
        $e = new self('Your legitimate-interest assessment is due for review. Confirm it still holds and sourcing will reopen.');
        $e->errorCode = 'lead_sourcing.lia_expired';
        $e->status = 403;

        return $e;
    }

    /**
     * The team has used up its **AI** allowance, and the selected source is the AI one. Distinct
     * from {@see self::quotaExhausted()} — that is the lead allowance — because the two are fixed by
     * doing different things, and telling a member the wrong one sends them to the wrong screen.
     * Never retryable: the next attempt would be refused for the same reason.
     */
    public static function aiAllowanceExhausted(): self
    {
        $e = new self('You’ve used up your AI allowance for this period, so AI research can’t run. The other lead sources still can.');
        $e->errorCode = 'lead_sourcing.ai_allowance_exhausted';
        $e->status = 429;

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
        $e->retryable = true;

        return $e;
    }
}
