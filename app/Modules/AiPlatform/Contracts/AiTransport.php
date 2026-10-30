<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Contracts;

use App\Modules\AiPlatform\Data\AiPromptRequest;
use App\Modules\AiPlatform\Data\AiTransportResult;
use App\Modules\AiPlatform\Exceptions\AiPlatformException;

/**
 * The low-level boundary to the AI provider SDK. It knows nothing about prompts, tenancy, cost or
 * caching — it just sends one fully-resolved request and returns the raw result + token usage. The
 * default implementation wraps laravel/ai; tests bind a fake so no network call is ever made.
 */
interface AiTransport
{
    /**
     * @throws AiPlatformException on a provider/transport failure.
     */
    public function generate(AiPromptRequest $request): AiTransportResult;
}
