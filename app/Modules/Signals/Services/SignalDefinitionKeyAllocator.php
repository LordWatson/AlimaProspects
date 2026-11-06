<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Data\SignalDefinitionData;
use App\Modules\Signals\Models\SignalDefinition;

/**
 * Hands out a storage key no definition of a team already holds (S7, ADR 0018).
 *
 * A service rather than a method on either Action, because **both** need it and getting it wrong in
 * one of them is a 500. The subtlety is that `unique(scope_id, key)` knows nothing about
 * `deleted_at`: a soft-deleted type still occupies its key, so the candidate set has to include
 * trashed rows even though they do not count against the team's ceiling. The first version of the
 * clone path missed exactly that, and a team who deleted a type and then re-added it from the
 * library hit a unique violation.
 *
 * The other collision is quieter: a team can write their own type called "Acquisition", which slugs
 * to `acquisition` — the same key the library's preset wants. Both have to be able to exist.
 */
final class SignalDefinitionKeyAllocator
{
    /**
     * A free key for this team, preferring `$base` and suffixing if it is taken.
     *
     * The suffix is appended **inside** the column's width rather than past it, which is why this is
     * a loop and not a `str_pad`.
     *
     * Must be called inside the team's context or with the team's id, and inside the transaction
     * that will use the result: the count is a read, not a lock, so two simultaneous creates could
     * in principle both see the same key free. The unique index is the backstop, and losing that
     * race is a retried request rather than a corrupt row.
     */
    public function allocate(int $teamId, string $base): string
    {
        $base = SignalDefinitionData::keyFor($base);

        /** @var list<string> $taken */
        $taken = SignalDefinition::query()
            // Trashed rows included: they still hold their key against the unique index.
            ->withTrashed()
            // `scope_id` rather than `team_id`, because that is the column the index is on — and it
            // is a generated column that always agrees with `team_id`.
            ->where('scope_id', $teamId)
            ->where('key', 'like', $base.'%')
            ->pluck('key')
            ->all();

        if (! in_array($base, $taken, strict: true)) {
            return $base;
        }

        for ($suffix = 2; $suffix <= 99; $suffix++) {
            $tail = '_'.$suffix;
            $candidate = mb_substr($base, 0, SignalDefinitionData::MAX_KEY - mb_strlen($tail)).$tail;

            if (! in_array($candidate, $taken, strict: true)) {
                return $candidate;
            }
        }

        // Ninety-nine types sharing a slug is not a case worth a nicer key than a unique one.
        return mb_substr($base, 0, SignalDefinitionData::MAX_KEY - 9).'_'.substr((string) now()->getTimestampMs(), -8);
    }

    /**
     * The team's existing definition for a preset, **including a soft-deleted one**, or null.
     *
     * Keyed on `preset_key` rather than `key`, because that is what identifies a clone: a team may
     * have renamed theirs, and a different type of theirs may have taken the preset's key.
     */
    public function existingClone(int $teamId, string $presetKey): ?SignalDefinition
    {
        return SignalDefinition::query()
            ->withTrashed()
            ->where('scope_id', $teamId)
            ->where('preset_key', $presetKey)
            ->first();
    }
}
