<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Domain errors for Scoring & Matching, rendered centrally (bootstrap/app.php): a friendly message
 * for the user, a stable machine code for the API envelope. Each cause is a named constructor so
 * the code is set in one place.
 */
final class ScoringException extends DomainException
{
    protected string $errorCode = 'scoring.invalid';

    /**
     * The team has no active ICP, so there is no definition to score a lead against.
     */
    public static function noActiveIcp(): self
    {
        $e = new self('Set an ideal customer profile as active, then Alima can grade your leads against it.');
        $e->errorCode = 'scoring.no_active_icp';

        return $e;
    }

    /**
     * A score was asked for on a lead that hasn't been graded yet.
     */
    public static function notScored(): self
    {
        $e = new self('This lead hasn’t been graded yet.');
        $e->errorCode = 'scoring.not_scored';
        $e->status = 404;

        return $e;
    }
}
