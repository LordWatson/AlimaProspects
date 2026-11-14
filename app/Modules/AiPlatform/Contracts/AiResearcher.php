<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Contracts;

use App\Modules\AiPlatform\Data\AiWebSearch;
use App\Modules\AiPlatform\Data\StructuredAiResponse;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\InvalidAiResponseException;
use App\Modules\AiPlatform\Exceptions\UnknownPromptException;

/**
 * {@see AiClient} for a prompt that may search the web while it answers (Signals v3 P4, ADR 0039).
 *
 * A sibling contract rather than a fourth argument on `AiClient::structured()`, because the two
 * are different promises. A structured call is a pure function of its input: it is cached, and
 * its cost is its tokens. A research call reads the live web, so it is **never cached**, it is
 * billed per search as well as per token, and its answer comes with the pages the searches
 * returned so the caller can refuse anything not found on one of them.
 *
 * Everything else is the same and is the same code: the registered, versioned prompt, the
 * untrusted-content wrapping, the team's credit allowance, schema validation with one retry, and
 * the `ai_requests` ledger row — whose cost includes the search fee.
 */
interface AiResearcher
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws UnknownPromptException
     * @throws AiCreditExceededException
     * @throws InvalidAiResponseException
     */
    public function research(string $key, array $input, AiWebSearch $search, ?int $version = null): StructuredAiResponse;
}
