<?php

declare(strict_types=1);

namespace App\Modules\AiPlatform\Events;

use App\Modules\AiPlatform\Enums\AiRequestStatus;
use App\Modules\AiPlatform\Enums\AiTier;
use App\Modules\AiPlatform\Services\AiUsageLogger;

/**
 * One AI call has been written to the `ai_requests` ledger (Phase 20).
 *
 * Raised by {@see AiUsageLogger} once the row exists. It carries **scalars, not the model** — and
 * that is the point rather than an optimisation: a listener handed an Eloquent model is a listener
 * that can lazily load a relation, change an attribute or hold the row open, and the only thing
 * anybody should do with a finished ledger entry is count it.
 *
 * It exists so that observability can watch AI spend without AI Platform knowing what Pulse is
 * (`Observability\AiUsageRecorder`). The alternative — calling `Pulse::record()` from the usage
 * logger — would put a metrics dashboard in the middle of the billing ledger's write path, and
 * would mean the ledger could not be written at all on an installation without Pulse.
 *
 * Nothing on it is personal data: ids, a prompt key, token counts, cost and a latency. The prompt
 * content is deliberately absent, exactly as it is absent from the ledger row itself.
 */
final readonly class AiRequestRecorded
{
    public function __construct(
        public ?int $teamId,
        public string $promptKey,
        public int $promptVersion,
        public AiTier $tier,
        public string $model,
        public int $inputTokens,
        public int $outputTokens,
        public int $costMicros,
        public int $latencyMs,
        public bool $cacheHit,
        public AiRequestStatus $status,
        public ?string $errorCode,
    ) {}
}
