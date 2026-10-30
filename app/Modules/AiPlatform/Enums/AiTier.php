<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Enums;

use App\Modules\AiPlatform\Contracts\AiClient;

/**
 * The capability tier a prompt targets. Resolved to a concrete provider + model by the
 * {@see AiClient}, never hard-coded to a model id, so the
 * underlying model can change without touching callers (ADR 0005).
 *
 * - {@see self::Reasoning} → the smart tier (Claude Sonnet): scoring, background check, drafting.
 * - {@see self::Cheap} → the fast/cheap tier (Claude Haiku): extraction and classification.
 */
enum AiTier: string
{
    case Reasoning = 'reasoning';
    case Cheap = 'cheap';

    public function label(): string
    {
        return match ($this) {
            self::Reasoning => 'Reasoning',
            self::Cheap => 'Cheap',
        };
    }

    /**
     * The `ai-platform.tiers.*` config key this tier reads its provider + model from.
     */
    public function configKey(): string
    {
        return $this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $tier): string => $tier->value, self::cases());
    }
}
