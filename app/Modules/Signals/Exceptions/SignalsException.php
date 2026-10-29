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
     * The signal names no company to build a lead from, and none was supplied.
     */
    public static function noSubjectToConvert(): self
    {
        $e = new self('This signal has no company to convert. Add a company name — the person’s business or holding entity — so Alima knows who the lead is.');
        $e->errorCode = 'signals.no_subject';

        return $e;
    }
}
