<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Services;

use App\Modules\AiPlatform\Exceptions\UnknownPromptException;
use App\Modules\AiPlatform\Models\AiPromptTemplate;

/**
 * Resolves a prompt template by key (+ optional version) from the `ai_prompt_templates` registry.
 * When no version is given, the active version with the highest number wins, so adding a new version
 * promotes it without editing the old one. It reads from the database on each call (one indexed
 * lookup, negligible beside an LLM call) so a long-running queue worker always sees the current
 * active version without a restart — no per-process memo to go stale.
 */
final class PromptRegistry
{
    /**
     * @throws UnknownPromptException
     */
    public function resolve(string $key, ?int $version = null): AiPromptTemplate
    {
        $query = AiPromptTemplate::query()->where('key', $key);

        if ($version !== null) {
            $query->where('version', $version);
        } else {
            $query->where('is_active', true)->orderByDesc('version');
        }

        $template = $query->first();

        if (! $template instanceof AiPromptTemplate) {
            throw UnknownPromptException::for($key, $version);
        }

        return $template;
    }
}
