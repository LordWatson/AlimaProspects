<?php

declare(strict_types=1);

namespace App\Modules\Enrichment\Data;

use App\Modules\Enrichment\Casts\SourcedValueMap;
use Illuminate\Support\Carbon;
use Spatie\LaravelData\Data;

/**
 * The provenance of a single enriched field (ADR 0008: "every field stores source, source_url,
 * collected_at, method"). It records WHERE a value came from, not the value itself — the value lives
 * in its own typed column on the lead, while a map of these ({@see SourcedValueMap}) is stored
 * alongside in `leads.field_sources`, keyed by field name. So a reviewer can always answer "where
 * did this industry/website come from, and when?".
 */
final class SourcedValue extends Data
{
    public const string METHOD_PROVIDER = 'provider';

    public const string METHOD_AI_EXTRACT = 'ai_extract';

    public const string METHOD_MERGE = 'merge';

    public function __construct(
        public string $source,
        public string $method,
        public ?string $sourceUrl = null,
        public ?Carbon $collectedAt = null,
    ) {}

    /**
     * Build the provenance for a value the AI extracted from a page at `$sourceUrl` (now).
     */
    public static function fromAiExtract(?string $sourceUrl): self
    {
        return new self(
            source: self::METHOD_AI_EXTRACT,
            method: self::METHOD_AI_EXTRACT,
            sourceUrl: $sourceUrl,
            collectedAt: Carbon::now(),
        );
    }

    /**
     * The JSON-storable form held inside `leads.field_sources`.
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'source' => $this->source,
            'method' => $this->method,
            'source_url' => $this->sourceUrl,
            'collected_at' => $this->collectedAt?->toIso8601String(),
        ];
    }

    /**
     * Rebuild from the stored associative form.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStorage(array $data): self
    {
        $collectedAt = $data['collected_at'] ?? null;

        return new self(
            source: (string) ($data['source'] ?? ''),
            method: (string) ($data['method'] ?? ''),
            sourceUrl: isset($data['source_url']) ? (string) $data['source_url'] : null,
            collectedAt: is_string($collectedAt) && $collectedAt !== '' ? Carbon::parse($collectedAt) : null,
        );
    }
}
