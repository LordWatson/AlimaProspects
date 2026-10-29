<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Data;

use App\Modules\AiPlatform\Data\StructuredAiResponse;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * The validated shape of a `scoring.qualitative_fit` result, hydrated from the AI Platform's
 * {@see StructuredAiResponse}. Mirrors the prompt's JSON schema. The model's own letter grade is
 * deliberately **not** carried: the stored grade is derived from the blended score so one scale
 * governs the whole product. Its numeric score, rationale, strengths and concerns are.
 *
 * Every value is trimmed, clamped and capped, so a runaway or hostile response can't bloat storage
 * or a later prompt.
 */
final class QualitativeFit extends Data
{
    private const int MAX_RATIONALE = 1_500;

    private const int MAX_ITEM = 240;

    private const int MAX_ITEMS = 6;

    /**
     * @param  list<string>  $strengths
     * @param  list<string>  $concerns
     */
    public function __construct(
        public int $score,
        public string $rationale,
        public array $strengths = [],
        public array $concerns = [],
    ) {}

    /**
     * Build from the AI response's raw validated data array.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromAi(array $data): self
    {
        return new self(
            score: max(0, min(100, (int) ($data['score'] ?? 0))),
            rationale: Str::limit(trim((string) ($data['rationale'] ?? '')), self::MAX_RATIONALE, ''),
            strengths: self::strings($data['strengths'] ?? []),
            concerns: self::strings($data['concerns'] ?? []),
        );
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (! is_string($item)) {
                continue;
            }

            $trimmed = trim($item);
            if ($trimmed !== '' && ! in_array($trimmed, $out, strict: true)) {
                $out[] = Str::limit($trimmed, self::MAX_ITEM, '');
            }

            if (count($out) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $out;
    }
}
