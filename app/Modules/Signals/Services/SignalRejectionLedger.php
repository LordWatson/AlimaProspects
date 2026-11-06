<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Enums\SignalRejectionVerdict;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRejection;
use Illuminate\Support\Facades\DB;

/**
 * What this team's classifier has already said no to (S11), so a run does not pay to be told again.
 *
 * This is the S3 trade-off (b), closed. Non-event and below-threshold items were forgotten, so
 * every run re-classified the same weekly round-ups — the "Dubai's ten biggest deals of 2026" piece
 * cost an AI batch every single day for as long as it stayed inside the recency window.
 *
 * **Keyed by the article *and* the question that was asked about it.** A rejection is
 * `(team, dedupe hash, definition fingerprint)`, where the fingerprint hashes the **set of signal
 * type keys offered** at the time. That is what "rejected for the same definition" has to mean once
 * a watch offers six types in one batch: a team that adds a type has asked a different question
 * about the same article and is owed a fresh answer, while a team that has changed nothing is not
 * asked to pay twice. The cost is that *editing* a type's wording without changing its key does not
 * invalidate the memory — a limitation recorded in the module doc, and bounded to 30 days by the
 * retention window rather than left to be noticed.
 *
 * **Counts only.** A row holds two hashes, a verdict and a tally; no headline, no URL, no outlet,
 * no name. That is what makes it cheap to retain and uninteresting to a subject access request,
 * and it is the same rule the `signals` log channel follows.
 *
 * Every query is scoped twice — the `BelongsToTeam` global scope and an explicit `team_id` — because
 * the run that calls this has no request behind it, and the writes are bulk statements the trait's
 * auto-assign never sees.
 */
final class SignalRejectionLedger
{
    /**
     * The fingerprint of a set of offered types: `sha1` of their keys, sorted and joined.
     *
     * Sorted, because the order a run happens to resolve its definitions in is not part of the
     * question being asked.
     *
     * @param  list<SignalDefinition>  $definitions
     */
    public function fingerprintFor(array $definitions): string
    {
        $keys = array_map(static fn (SignalDefinition $definition): string => $definition->key, $definitions);

        sort($keys);

        return sha1(implode('|', array_values(array_unique($keys))));
    }

    /**
     * Which of these hashes have already been rejected under this exact set of types, inside the
     * window.
     *
     * Returned as a set keyed by hash, so the caller filters with an `isset` rather than an
     * `in_array` over a few hundred items.
     *
     * @param  list<string>  $hashes
     * @return array<string, true>
     */
    public function rejected(int $teamId, string $fingerprint, array $hashes): array
    {
        if ($hashes === [] || ! $this->enabled()) {
            return [];
        }

        $since = now()->subDays($this->window());

        /** @var list<string> $found */
        $found = SignalRejection::query()
            ->where('team_id', $teamId)
            ->where('definition_fingerprint', $fingerprint)
            ->whereIn('dedupe_hash', array_values(array_unique($hashes)))
            ->where('last_seen_at', '>=', $since)
            ->pluck('dedupe_hash')
            ->all();

        return array_fill_keys($found, true);
    }

    /**
     * Remember a verdict about these articles.
     *
     * **Update-then-insert, not an upsert**, for two reasons: `seen_count` has to increment rather
     * than be overwritten (it is the measure of whether this table earns its keep), and the pattern
     * is driver-agnostic — `insertOrIgnore` after a zero-row update loses the race safely, which is
     * the only thing two concurrent runs of two watches over the same news need from it.
     *
     * @param  list<string>  $hashes
     */
    public function remember(int $teamId, string $fingerprint, array $hashes, SignalRejectionVerdict $verdict): int
    {
        if ($hashes === [] || ! $this->enabled()) {
            return 0;
        }

        $now = now();
        $remembered = 0;

        foreach (array_values(array_unique($hashes)) as $hash) {
            $updated = SignalRejection::query()
                ->where('team_id', $teamId)
                ->where('definition_fingerprint', $fingerprint)
                ->where('dedupe_hash', $hash)
                ->update([
                    'verdict' => $verdict->value,
                    'seen_count' => DB::raw('seen_count + 1'),
                    'last_seen_at' => $now,
                    'updated_at' => $now,
                ]);

            if ($updated === 0) {
                SignalRejection::query()->insertOrIgnore([
                    'team_id' => $teamId,
                    'dedupe_hash' => $hash,
                    'definition_fingerprint' => $fingerprint,
                    'verdict' => $verdict->value,
                    'seen_count' => 1,
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $remembered++;
        }

        return $remembered;
    }

    /**
     * Whether the memory is switched on at all. Off means every run re-classifies everything, which
     * is v1's behaviour and the way back from a false negative nobody can explain.
     */
    public function enabled(): bool
    {
        return (bool) config('signals.rejections.enabled', true);
    }

    /**
     * How many days a rejection is honoured for — and, since it is the same number, how long the
     * row is kept. One window, so `signals:prune` cannot delete a row the run still trusts or keep
     * one it has stopped trusting.
     */
    public function window(): int
    {
        return max(1, (int) config('signals.rejections.retention_days', 30));
    }
}
