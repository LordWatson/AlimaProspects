<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Data;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\Scoring\Casts\CriterionScoreList;
use App\Modules\Scoring\Enums\CriterionMatch;
use App\Modules\Scoring\Services\DeterministicScorer;
use Illuminate\Support\Str;
use Spatie\LaravelData\Data;

/**
 * One row of a score's breakdown: how a single ICP criterion fared against the lead, what in the
 * lead settled it, and how many points of the deterministic score it contributed. Produced by the
 * {@see DeterministicScorer} and persisted as JSON in `lead_scores.breakdown` via
 * {@see CriterionScoreList}, so the UI can always show *why* a lead scored what it did.
 *
 * `evidence` is the grounding: the lead's own value that matched (or the fact that the field was
 * empty). It is never model-written prose — it comes from the lead record.
 */
final class CriterionScore extends Data
{
    private const int MAX_EVIDENCE = 255;

    public function __construct(
        public CriterionType $type,
        public string $value,
        public int $weight,
        public CriterionMatch $match,
        public int $contribution = 0,
        public ?string $evidence = null,
    ) {}

    /**
     * The same row with its share of the deterministic score filled in (points out of 100).
     */
    public function withContribution(int $contribution): self
    {
        return new self(
            type: $this->type,
            value: $this->value,
            weight: $this->weight,
            match: $this->match,
            contribution: $contribution,
            evidence: $this->evidence,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toStorage(): array
    {
        return [
            'type' => $this->type->value,
            'value' => $this->value,
            'weight' => $this->weight,
            'match' => $this->match->value,
            'contribution' => $this->contribution,
            'evidence' => $this->evidence,
        ];
    }

    /**
     * Rebuild from the stored form, tolerating a row written by an older shape.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromStorage(array $data): self
    {
        return new self(
            type: CriterionType::tryFrom((string) ($data['type'] ?? '')) ?? CriterionType::Industry,
            value: Str::limit((string) ($data['value'] ?? ''), self::MAX_EVIDENCE, ''),
            weight: (int) ($data['weight'] ?? 0),
            match: CriterionMatch::tryFrom((string) ($data['match'] ?? '')) ?? CriterionMatch::Unknown,
            contribution: (int) ($data['contribution'] ?? 0),
            evidence: isset($data['evidence']) && is_string($data['evidence'])
                ? Str::limit($data['evidence'], self::MAX_EVIDENCE, '')
                : null,
        );
    }
}
