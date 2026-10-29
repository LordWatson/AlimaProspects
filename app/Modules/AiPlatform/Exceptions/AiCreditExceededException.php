<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

/**
 * The team has used its AI credit allowance for the current period. Blocks the run before any
 * provider call is made (margin protection — see mvp.md "Cost model & margin protection"). The
 * real allowance comes from Billing; until then a stubbed source supplies a generous default.
 */
final class AiCreditExceededException extends AiPlatformException
{
    protected int $status = 402; // Payment Required

    protected string $errorCode = 'ai.credit_exceeded';

    public static function make(): self
    {
        return new self('Your team has used its AI allowance for this period. Please upgrade or wait for it to reset.');
    }
}
