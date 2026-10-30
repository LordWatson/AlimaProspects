<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

final class BackgroundCheckException extends DomainException
{
    protected string $errorCode = 'business_intel.invalid';

    /**
     * The report is not in a state that can be accepted (not ready, or already accepted).
     */
    public static function notReviewable(): self
    {
        $e = new self('This background check can’t be accepted yet.');
        $e->errorCode = 'business_intel.not_reviewable';

        return $e;
    }

    /**
     * A re-run was requested while one is still in progress.
     */
    public static function alreadyRunning(): self
    {
        $e = new self('A background check is already running — give it a moment.');
        $e->errorCode = 'business_intel.already_running';

        return $e;
    }
}
