<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

/**
 * No prompt template matched the requested key (and version). A programming error — the caller
 * referenced a prompt that was never seeded into the registry.
 */
final class UnknownPromptException extends AiPlatformException
{
    protected int $status = 500;

    protected string $errorCode = 'ai.unknown_prompt';

    public static function for(string $key, ?int $version = null): self
    {
        $suffix = $version !== null ? " (version {$version})" : '';

        return new self("No AI prompt template is registered for key [{$key}]{$suffix}.");
    }
}
