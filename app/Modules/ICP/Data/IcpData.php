<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Http\Requests\SaveIcpRequest;
use Spatie\LaravelData\Data;

/**
 * The validated shape of an ICP write (create or update), assembled by the controller from a
 * {@see SaveIcpRequest} and passed into the write Actions. Criteria
 * are a positional list; exclusions carry a null weight.
 */
final class IcpData extends Data
{
    /**
     * @param  list<CriterionData>  $criteria
     */
    public function __construct(
        public string $name,
        public ?string $description,
        public bool $isActive,
        public array $criteria,
    ) {}

    /**
     * Build from already-validated request input.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        /** @var list<array<string, mixed>> $rawCriteria */
        $rawCriteria = is_array($validated['criteria'] ?? null) ? array_values($validated['criteria']) : [];

        $criteria = [];
        foreach ($rawCriteria as $row) {
            $type = CriterionType::from((string) $row['type']);
            $weight = isset($row['weight']) && $row['weight'] !== ''
                ? (int) $row['weight']
                : null;

            $criteria[] = new CriterionData(
                type: $type,
                value: trim((string) $row['value']),
                // Exclusions are never weighted; signals default to a neutral 50 when left blank.
                weight: $type->isExclusion() ? null : ($weight ?? 50),
            );
        }

        return new self(
            name: trim((string) $validated['name']),
            description: isset($validated['description']) && $validated['description'] !== ''
                ? trim((string) $validated['description'])
                : null,
            isActive: (bool) ($validated['is_active'] ?? false),
            criteria: $criteria,
        );
    }
}
