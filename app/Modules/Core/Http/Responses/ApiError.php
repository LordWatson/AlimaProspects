<?php

declare(strict_types=1);

namespace App\Modules\Core\Http\Responses;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Exceptions\ApiExceptionRenderer;
use App\Modules\Core\Exceptions\DomainException;
use App\Modules\Core\Http\Middleware\ApiVersion;
use Illuminate\Http\JsonResponse;

/**
 * The one place that knows the shape of an `/api/v1` error (Phase 17).
 *
 * Every failure — a domain exception, a failed validation, a missing record, a throttle — answers
 * with the same envelope:
 *
 * ```json
 * { "error": { "code": "validation_failed", "message": "…", "details": { "email": ["…"] } } }
 * ```
 *
 * `code` is the contract and is safe to branch on; `message` is prose for a human and may be
 * translated; `details` is present only when there is something structured to say (field errors,
 * the abilities a token was missing) and is never a second copy of the message.
 *
 * Both callers go through here — {@see DomainException::render()} and
 * {@see ApiExceptionRenderer} — so the two cannot drift.
 */
final class ApiError
{
    /**
     * @param  array<string, mixed>  $details
     * @return array{error: array{code: string, message: string, details?: array<string, mixed>}}
     */
    public static function payload(string $code, string $message, array $details = []): array
    {
        $error = [
            'code' => $code,
            'message' => $message,
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return ['error' => $error];
    }

    /**
     * The version is stamped here as well as in {@see ApiVersion}, and not by accident: the
     * middleware sets the header on the way back out, and an exception never comes back out
     * through it — it is caught by the handler above. Without this, every error response would be
     * the one kind of response missing the header a client uses to decide how to parse it, which
     * is exactly when it is needed most. An explicit header still wins, so a 429 keeps its own.
     *
     * @param  array<string, mixed>  $details
     * @param  array<string, string>  $headers
     */
    public static function response(
        ApiErrorCode|string $code,
        string $message,
        int $status,
        array $details = [],
        array $headers = [],
    ): JsonResponse {
        return new JsonResponse(
            self::payload($code instanceof ApiErrorCode ? $code->value : $code, $message, $details),
            $status,
            [ApiVersion::HEADER => ApiVersion::VERSION, ...$headers],
        );
    }
}
