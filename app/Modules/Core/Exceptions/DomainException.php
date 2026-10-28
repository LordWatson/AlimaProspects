<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Base class for module domain exceptions. Each module extends this with specific exceptions.
 * Rendered centrally (bootstrap/app.php): user-facing message is friendly; internal detail goes
 * to logs/Sentry. Carries an HTTP status and a stable, machine-readable error code.
 */
abstract class DomainException extends RuntimeException
{
    protected int $status = 422;

    protected string $errorCode = 'domain_error';

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * Consistent JSON error envelope for API/Inertia JSON requests.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode(),
                'message' => $this->getMessage(),
            ],
        ], $this->status());
    }
}
