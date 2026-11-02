<?php

declare(strict_types=1);

namespace App\Modules\Core\Exceptions;

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Http\Responses\ApiError;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Exception\RequestExceptionInterface;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every `/api/v1` failure as the one envelope (Phase 17).
 *
 * Without this, a client has three error shapes to parse: `{error: {...}}` from a domain
 * exception, `{message, errors}` from a failed validation and a bare `{message}` from everything
 * the framework raises itself. One of those is a contract; the other two are Laravel's defaults
 * leaking through. A mobile app that has to guess which one it got will guess wrong on the error
 * path, which is exactly where it can least afford to.
 *
 * Scoped to `api/*` **by path, deliberately** — not by `expectsJson()`. Inertia requests also
 * expect JSON, and the web forms depend on Laravel's native `{message, errors}` 422 for their
 * error bags; widening this to every JSON request would silently break every form in the app.
 */
final class ApiExceptionRenderer
{
    /**
     * Null means "not ours" — the framework carries on rendering as it would have.
     */
    public function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('api/*')) {
            return null;
        }

        return match (true) {
            // A domain exception renders itself through the same envelope; leave it alone.
            $e instanceof DomainException => null,
            // Carries its own finished response (`abort($response)`, a FormRequest overriding
            // `failedValidation`, a vendor package). The handler returns it one `match` arm later;
            // taking it as "unhandled" would throw away a deliberate answer and send a 500 in its
            // place.
            $e instanceof HttpResponseException => null,
            $e instanceof ValidationException => $this->validation($e),
            $e instanceof AuthenticationException => ApiError::response(
                ApiErrorCode::Unauthenticated,
                ApiErrorCode::Unauthenticated->message(),
                JsonResponse::HTTP_UNAUTHORIZED,
            ),
            $e instanceof HttpExceptionInterface => $this->http($e),
            default => $this->unexpected($e),
        };
    }

    /**
     * Field errors go in `details`, keyed by input name, exactly as the validator reported them.
     */
    private function validation(ValidationException $e): JsonResponse
    {
        return ApiError::response(
            ApiErrorCode::ValidationFailed,
            $e->getMessage(),
            $e->status,
            $e->errors(),
        );
    }

    /**
     * Anything that already carries an HTTP status — aborts, 404s, 405s, throttles.
     *
     * The exception's own headers are preserved: a 429 without its `Retry-After` is a 429 the
     * client has to guess about, and a 405 without `Allow` is worse than no answer.
     */
    private function http(HttpExceptionInterface $e): JsonResponse
    {
        $status = $e->getStatusCode();
        $code = ApiErrorCode::fromStatus($status);
        $message = trim($e->getMessage());

        // Three messages not to pass on: the empty one from an `abort()` with no text, an internal
        // grumble about a malformed request, and **anything on a 5xx**. A server-side failure's
        // message describes our internals — `abort(503, 'redis pool exhausted')` is for the logs,
        // not for a prospect's phone — and the status already told the client what to do.
        if ($message === '' || $status >= 500 || $e instanceof RequestExceptionInterface) {
            $message = $code->message();
        }

        /** @var array<string, string> $headers */
        $headers = $e->getHeaders();

        return ApiError::response($code, $message, $status, [], $headers);
    }

    /**
     * An unhandled throwable. The detail belongs in the logs and Sentry, not in the response —
     * except locally, where hiding it just makes debugging slower.
     */
    private function unexpected(Throwable $e): JsonResponse
    {
        $debug = (bool) config('app.debug');

        return ApiError::response(
            ApiErrorCode::ServerError,
            $debug ? $e->getMessage() : ApiErrorCode::ServerError->message(),
            JsonResponse::HTTP_INTERNAL_SERVER_ERROR,
            $debug ? ['exception' => $e::class] : [],
        );
    }
}
