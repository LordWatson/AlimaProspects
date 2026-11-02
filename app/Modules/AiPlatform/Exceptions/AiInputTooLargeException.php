<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Exceptions;

use App\Modules\AiPlatform\Services\PromptInterpolator;

/**
 * A rendered prompt is larger than this installation allows (Phase 20, ADR 0017).
 *
 * The hard half of the input bound. The soft half is in {@see PromptInterpolator}, which truncates
 * an oversized **untrusted** block — a scraped page, a lead's own copy — because content from the
 * internet arriving too big is a normal Tuesday and the right answer is to use the first twenty
 * thousand characters of it.
 *
 * This one fires when the whole assembled prompt is too big anyway: a hundred leads in one list, a
 * pasted spreadsheet, a template whose placeholders each turned out to be a page. That is not a
 * condition to paper over, for three reasons that all point the same way — a provider request near
 * the context limit is slow and expensive, the cost is charged to the team's allowance whether the
 * answer is useful or not, and an enormous prompt is the shape a prompt-injection attempt takes
 * when somebody is trying to push the system instructions out of the model's attention.
 *
 * **413**, not 422: the request is well-formed, it is too large. A client can act on that, and the
 * error code says which bound was hit.
 */
final class AiInputTooLargeException extends AiPlatformException
{
    protected int $status = 413; // Payload Too Large

    protected string $errorCode = 'ai.input_too_large';

    public static function forPrompt(string $promptKey, int $chars, int $limit): self
    {
        $exception = new self('That request is too large to send for analysis. Please shorten it and try again.');

        // The numbers go in `details` rather than in the message: the message is read by a person
        // who cannot act on a character count, and the details are read by a client (and land in
        // the log line) where the count is the only thing worth having.
        $exception->details = [
            'prompt_key' => $promptKey,
            'chars' => $chars,
            'limit' => $limit,
        ];

        return $exception;
    }
}
