<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

use App\Modules\Core\Exceptions\DomainException;

/**
 * Base exception for the AI Platform module. Specific failures extend this; all are rendered through
 * the central handler with a friendly message and a stable error code (details go to logs/Sentry).
 */
abstract class AiPlatformException extends DomainException
{
    protected string $errorCode = 'ai.error';
}
