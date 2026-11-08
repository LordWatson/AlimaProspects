<?php

declare(strict_types=1);

namespace App\Modules\ICP\Data;

/**
 * How one of a profile's criteria has actually fared across the team's graded leads: the counts of
 * each outcome the deterministic scorer recorded for it. It is the thing that answers "why are my
 * leads landing C–D" at the level a team can act on — a criterion that is `notMatched` or `weak` on
 * most leads is the one to loosen or re-word. Part of an {@see IcpCalibration}.
 */
final class CriterionCalibration
{
    public function __construct(
        public readonly string $type,
        public readonly string $typeLabel,
        public readonly string $value,
        public readonly ?int $weight,
        public readonly int $matched,
        public readonly int $notMatched,
        public readonly int $weak,
        public readonly int $unknown,
    ) {}

    public function total(): int
    {
        return $this->matched + $this->notMatched + $this->weak + $this->unknown;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'type_label' => $this->typeLabel,
            'value' => $this->value,
            'weight' => $this->weight,
            'matched' => $this->matched,
            'not_matched' => $this->notMatched,
            'weak' => $this->weak,
            'unknown' => $this->unknown,
            'total' => $this->total(),
        ];
    }
}
