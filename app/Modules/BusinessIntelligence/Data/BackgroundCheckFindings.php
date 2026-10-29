<?php

declare(strict_types=1);

namespace App\Modules\BusinessIntelligence\Data;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated shape of a `business_intel.background_check` result, hydrated from the AI Platform's
 * {@see StructuredAiResponse}. Mirrors the prompt's JSON schema
 * (summary/offering/positioning required; the lists default to empty; confidence is 0–1). Kept as a
 * DTO so the job, the stored report and the review UI share one typed contract.
 */
final class BackgroundCheckFindings extends Data
{
    /** Clamp the free-text fields so a runaway model response can't bloat storage or later prompts. */
    private const int MAX_TEXT = 2_000;

    /** Clamp each list item, and cap how many items a list may contribute. */
    private const int MAX_ITEM = 160;

    private const int MAX_ITEMS = 12;

    /**
     * @param  list<string>  $targetCustomers
     * @param  list<string>  $competitors
     * @param  list<string>  $suggestedIndustries
     */
    public function __construct(
        public string $summary,
        public string $offering,
        public string $positioning,
        public array $targetCustomers = [],
        public array $competitors = [],
        public array $suggestedIndustries = [],
        public ?float $confidence = null,
    ) {}

    /**
     * Build from the AI response's raw validated data array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAi(array $data): self
    {
        return new self(
            summary: self::text($data['summary'] ?? ''),
            offering: self::text($data['offering'] ?? ''),
            positioning: self::text($data['positioning'] ?? ''),
            targetCustomers: self::strings($data['target_customers'] ?? []),
            competitors: self::strings($data['competitors'] ?? []),
            suggestedIndustries: self::strings($data['suggested_industries'] ?? []),
            confidence: isset($data['confidence']) ? (float) $data['confidence'] : null,
        );
    }

    /**
     * The persisted/storable associative form (snake_case keys matching the prompt schema).
     *
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'summary' => $this->summary,
            'offering' => $this->offering,
            'positioning' => $this->positioning,
            'target_customers' => $this->targetCustomers,
            'competitors' => $this->competitors,
            'suggested_industries' => $this->suggestedIndustries,
            'confidence' => $this->confidence,
        ];
    }

    /**
     * Coerce a loosely-typed value into a clean, bounded list of non-empty strings (each clamped,
     * the list capped) so a runaway response can't bloat storage, the profile or later prompts.
     *
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $strings = [];
        foreach ($value as $item) {
            if (is_string($item) || is_int($item) || is_float($item)) {
                $trimmed = trim((string) $item);
                if ($trimmed !== '') {
                    $strings[] = Str::limit($trimmed, self::MAX_ITEM, '');
                }
            }

            if (count($strings) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $strings;
    }

    /**
     * Trim and clamp a free-text field.
     */
    private static function text(mixed $value): string
    {
        return Str::limit(trim((string) $value), self::MAX_TEXT, '');
    }
}
