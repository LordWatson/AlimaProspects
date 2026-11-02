<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use App\Modules\Core\Http\Responses\ApiError;
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

    /**
     * Structured detail a client can act on — the fields at fault, the abilities a token was
     * missing. Prose belongs in the message; this is for anything a client would otherwise have
     * to parse out of it.
     *
     * @var array<string, mixed>
     */
    protected array $details = [];

    public function status(): int
    {
        return $this->status;
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }

    /**
     * @return array<string, mixed>
     */
    public function details(): array
    {
        return $this->details;
    }

    /**
     * The shared JSON error envelope for API/JSON requests ({@see ApiError}), so a domain failure
     * is indistinguishable in shape from a validation failure or a 404.
     */
    public function render(Request $request): ?JsonResponse
    {
        if (! $request->is('api/*') && ! $request->expectsJson()) {
            return null;
        }

        return ApiError::response(
            $this->errorCode(),
            $this->getMessage(),
            $this->status(),
            $this->details(),
        );
    }
}
