<?php

declare(strict_types=1);

namespace App\Modules\Scoring\Casts;

use App\Modules\Scoring\Data\CriterionScore;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for `lead_scores.breakdown`: an ordered list of {@see CriterionScore} rows on the
 * model, stored as a JSON array. Keeps the breakdown typed in PHP while it persists as plain JSON,
 * so nothing writes an untyped criterion blob by hand.
 *
 * @implements CastsAttributes<list<CriterionScore>, list<CriterionScore>>
 */
final class CriterionScoreList implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     * @return list<CriterionScore>
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($value, true);

        if (! is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $entry) {
            if (is_array($entry)) {
                $rows[] = CriterionScore::fromStorage($entry);
            }
        }

        return $rows;
    }

    /**
     * @param  list<CriterionScore>|null  $value
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === []) {
            return null;
        }

        $storable = array_map(static fn (CriterionScore $row): array => $row->toStorage(), $value);

        // Criterion values and evidence can carry scraped bytes, so substitute malformed UTF-8
        // rather than let json_encode fail and corrupt the column.
        $json = json_encode($storable, JSON_INVALID_UTF8_SUBSTITUTE);

        return $json === false ? null : $json;
    }
}
