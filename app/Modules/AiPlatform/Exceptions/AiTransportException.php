<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

use Throwable;

/**
 * The provider/transport failed (network error, timeout, provider 5xx, auth). The underlying detail
 * is attached as the previous exception for logs/Sentry; the user sees a friendly message.
 */
final class AiTransportException extends AiPlatformException
{
    protected int $status = 502;

    protected string $errorCode = 'ai.transport_failed';

    public static function from(Throwable $previous): self
    {
        return new self('The AI service is temporarily unavailable. Please try again shortly.', 0, $previous);
    }
}
