<?php

declare(strict_types=1);

namespace App\Modules\Signals\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Signals, rendered centrally (bootstrap/app.php): a friendly message for the
 * user, a stable machine code for the API envelope. Each cause is a named constructor so the code is
 * set in one place.
 */
final class SignalsException extends DomainException
{
    protected string $errorCode = 'signals.invalid';

    /**
     * Whether a queued run that hit this error is worth retrying. A transient news-provider failure
     * is; a configuration error (missing key, wrong driver) never will be, so the S3 job fails fast
     * instead of burning its retries.
     */
    protected bool $retryable = false;

    public function isRetryable(): bool
    {
        return $this->retryable;
    }

    /**
     * The news provider failed (timeout, 5xx/429, or a tripped circuit breaker). Transient —
     * retryable.
     */
    public static function newsProviderFailed(): self
    {
        $e = new self('We couldn’t reach the news service. Please try again shortly.');
        $e->errorCode = 'signals.news_provider_failed';
        $e->status = 502;
        $e->retryable = true;

        return $e;
    }

    /**
     * The news provider is missing required configuration (e.g. the Serper API key) — never
     * retryable.
     */
    public static function newsProviderMisconfigured(): self
    {
        $e = new self('Signals isn’t set up yet. An administrator needs to add the news service’s API key.');
        $e->errorCode = 'signals.news_provider_misconfigured';
        $e->status = 503;

        return $e;
    }

    /**
     * A news driver other than the supported one was configured — a config error, not transient.
     */
    public static function newsProviderUnavailable(): self
    {
        $e = new self('That news source isn’t available.');
        $e->errorCode = 'signals.news_provider_unavailable';
        $e->status = 503;

        return $e;
    }

    /**
     * The signal has already been converted into a lead, so it can't be converted again.
     */
    public static function alreadyConverted(): self
    {
        $e = new self('This signal has already been converted into a lead.');
        $e->errorCode = 'signals.already_converted';
        $e->status = 409;

        return $e;
    }

    /**
     * A run was requested for a watch that isn't active.
     */
    public static function watchInactive(): self
    {
        $e = new self('This watch is paused. Turn it back on before running it.');
        $e->errorCode = 'signals.watch_inactive';

        return $e;
    }

    /**
     * "Run now" was triggered again too soon (rate-limited per watch).
     */
    public static function runThrottled(int $seconds): self
    {
        $minutes = (int) ceil($seconds / 60);
        $e = new self("This watch was run recently. Try again in about {$minutes} minute(s).");
        $e->errorCode = 'signals.run_throttled';
        $e->status = 429;

        return $e;
    }

    /**
     * The signal names no company to build a lead from, and none was supplied.
     */
    public static function noSubjectToConvert(): self
    {
        $e = new self('This signal has no company to convert. Add a company name — the person’s business or holding entity — so Alima knows who the lead is.');
        $e->errorCode = 'signals.no_subject';

        return $e;
    }
}
