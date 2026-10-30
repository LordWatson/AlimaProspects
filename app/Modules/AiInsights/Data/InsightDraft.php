<?php

declare(strict_types=1);

namespace App\Modules\AiInsights\Data;

use App\Modules\AiInsights\Actions\StoreInsightsAction;
use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Models\Insight;
use DateTimeInterface;
use Spatie\LaravelData\Data;

/**
 * One insight a generator wants to store, before it is one (Phase 14).
 *
 * Every generator — the two SQL ones and the AI one — returns these, so {@see StoreInsightsAction}
 * is the only code that writes an {@see Insight} and the only place the idempotency rule lives.
 *
 * `headline` and `body` are null for a deterministic insight on purpose: see
 * {@see InsightType::isWrittenByAi()}.
 */
final class InsightDraft extends Data
{
    /**
     * @param  array<string, mixed>  $payload  Ids, counts and company names. Never a contact detail.
     * @param  string  $dedupeKey  `<type>:<team-local date>[:n]`, unique per team. Two runs on the
     *                             same day produce the same key, which is what makes the generator
     *                             safe to re-run and a dismissal safe from being undone.
     */
    public function __construct(
        public InsightType $type,
        public InsightSeverity $severity,
        public array $payload,
        public string $dedupeKey,
        public ?string $headline = null,
        public ?string $body = null,
        public ?DateTimeInterface $periodStart = null,
        public ?DateTimeInterface $periodEnd = null,
        public ?string $promptKey = null,
        public ?int $promptVersion = null,
        public ?string $model = null,
        public int $costMicros = 0,
    ) {}

    /**
     * The attributes {@see StoreInsightsAction} writes. Kept here so the mapping from draft to row
     * is next to the draft rather than inside a loop.
     *
     * @return array<string, mixed>
     */
    public function attributes(DateTimeInterface $generatedAt): array
    {
        return [
            'type' => $this->type,
            'severity' => $this->severity,
            'headline' => $this->headline,
            'body' => $this->body,
            'payload' => $this->payload,
            'period_start' => $this->periodStart,
            'period_end' => $this->periodEnd,
            'prompt_key' => $this->promptKey,
            'prompt_version' => $this->promptVersion,
            'model' => $this->model,
            'cost_micros' => $this->costMicros,
            'generated_at' => $generatedAt,
        ];
    }
}
