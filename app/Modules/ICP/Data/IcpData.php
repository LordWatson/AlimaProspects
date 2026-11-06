<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Http\Requests\SaveIcpRequest;
use Illuminate\Validation\Rule;
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
     * The one definition of what an ICP write may contain — shared by {@see SaveIcpRequest} (a
     * member filling in the form) and {@see DraftIcp::fromAi()} (the composer re-validating what
     * the AI drafted). A prompt describes a constraint; it does not enforce one, so the draft is
     * run through the save path's own rules rather than trusted.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['boolean'],

            'criteria' => ['present', 'array', 'max:50'],
            'criteria.*.type' => ['required', Rule::enum(CriterionType::class)],
            'criteria.*.value' => ['required', 'string', 'max:255'],
            'criteria.*.weight' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

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
