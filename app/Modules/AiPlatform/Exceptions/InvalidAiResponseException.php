<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

/**
 * The model's response failed JSON-schema validation after every allowed retry. The caller never
 * receives unvalidated data — a malformed response is an error, not a silent partial result.
 */
final class InvalidAiResponseException extends AiPlatformException
{
    protected int $status = 502;

    protected string $errorCode = 'ai.invalid_response';

    /**
     * The schema-validation errors from the final attempt (non-sensitive; safe to log).
     *
     * @var list<string>
     */
    public array $validationErrors = [];

    /**
     * @param  list<string>  $errors
     */
    public static function afterRetries(string $key, int $attempts, array $errors): self
    {
        $e = new self("The AI response for [{$key}] did not match its schema after {$attempts} attempt(s).");
        $e->validationErrors = $errors;

        return $e;
    }
}
