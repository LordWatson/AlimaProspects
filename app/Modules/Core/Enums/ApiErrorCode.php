<?php

declare(strict_types=1);

namespace App\Modules\Core\Enums;

use App\Modules\Core\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The stable, machine-readable error codes `/api/v1` returns for failures the framework raises
 * (Phase 17). Domain failures carry their own code from {@see DomainException}
 * — these are the ones no module owns: authentication, authorisation, routing, validation,
 * throttling.
 *
 * A client branches on the **code**, never on the message: the message is prose and may be
 * translated or reworded, the code is part of the contract and only changes with a new API version.
 */
enum ApiErrorCode: string
{
    case Unauthenticated = 'unauthenticated';
    case Forbidden = 'forbidden';
    case InsufficientTokenAbility = 'insufficient_token_ability';
    case NotFound = 'not_found';
    case MethodNotAllowed = 'method_not_allowed';
    case Conflict = 'conflict';
    case PayloadTooLarge = 'payload_too_large';
    case ValidationFailed = 'validation_failed';
    case Locked = 'locked';
    case TooManyRequests = 'too_many_requests';
    case UnsupportedApiVersion = 'unsupported_api_version';
    case ServerError = 'server_error';
    case ServiceUnavailable = 'service_unavailable';

    /**
     * The code for an HTTP status that reached the API without a more specific one — so an
     * unmapped status still answers with a readable code rather than a bare number.
     */
    public static function fromStatus(int $status): self
    {
        return match ($status) {
            Response::HTTP_UNAUTHORIZED => self::Unauthenticated,
            Response::HTTP_FORBIDDEN => self::Forbidden,
            Response::HTTP_NOT_FOUND => self::NotFound,
            Response::HTTP_METHOD_NOT_ALLOWED => self::MethodNotAllowed,
            Response::HTTP_CONFLICT => self::Conflict,
            Response::HTTP_REQUEST_ENTITY_TOO_LARGE => self::PayloadTooLarge,
            Response::HTTP_UNPROCESSABLE_ENTITY => self::ValidationFailed,
            Response::HTTP_LOCKED => self::Locked,
            Response::HTTP_TOO_MANY_REQUESTS => self::TooManyRequests,
            Response::HTTP_SERVICE_UNAVAILABLE => self::ServiceUnavailable,
            default => self::ServerError,
        };
    }

    /**
     * The fallback prose for a failure that arrived without a message worth showing a user.
     */
    public function message(): string
    {
        return match ($this) {
            self::Unauthenticated => __('Sign in to continue.'),
            self::Forbidden => __('You do not have permission to do that.'),
            self::InsufficientTokenAbility => __('This token is not allowed to do that.'),
            self::NotFound => __('We could not find that.'),
            self::MethodNotAllowed => __('That method is not allowed on this endpoint.'),
            self::Conflict => __('That conflicts with something that already exists.'),
            self::PayloadTooLarge => __('That request was too large.'),
            self::ValidationFailed => __('Some of those details need another look.'),
            self::Locked => __('That is locked.'),
            self::TooManyRequests => __('Too many requests — try again shortly.'),
            self::UnsupportedApiVersion => __('That API version is not supported.'),
            self::ServiceUnavailable => __('That service is unavailable right now.'),
            self::ServerError => __('Something went wrong on our side.'),
        };
    }
}
