<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalSourceStat;
use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Keeps `signal_source_stats` — what this team's own behaviour says about each outlet (S12).
 *
 * The only writer of that table. Every increment goes through here so that the four counters
 * cannot drift into disagreeing about the same event: `not_an_event_count` is a **subset** of
 * `dismissed_count`, and two call sites maintaining that relationship is one call site too many.
 *
 * Three rules it enforces:
 *
 * 1. **Counted per card, not per article.** A member only ever actions the cluster's primary
 *    (S11), so a "keep" is one representative and a dismissal is one representative. Counting
 *    keeps per article while counting dismissals per card would make every well-covered outlet
 *    look as though the team loved it.
 * 2. **It can be undone.** A restore decrements, because a mis-click that taught the product an
 *    outlet was worthless and was corrected on screen a second later must not leave the lesson
 *    behind — the advisor would eventually propose excluding a source on dismissals the team had
 *    already taken back. Counters floor at zero rather than going negative.
 * 3. **It is additive, never recomputed.** Deriving these from the `signals` table would be the
 *    tidier shape — and would be wrong, because retention deletes the rows: an outlet's record
 *    would quietly reset every ninety days. A tally is the right structure for a fact about
 *    history that outlives the history.
 *
 * Writes are update-then-insert, like {@see SignalRejectionLedger}: the counters increment rather
 * than being overwritten, and a race between two members loses safely.
 */
final class SignalSourceLedger
{
    public function __construct(private readonly SignalItemNormaliser $normaliser) {}

    /**
     * This outlet produced a signal that cleared the thresholds.
     *
     * @param  list<Signal>  $signals
     */
    public function recordKept(array $signals): void
    {
        foreach ($signals as $signal) {
            $this->bump($signal, ['kept_count'], up: true);
        }
    }

    /**
     * The team cleared a signal from this outlet off the feed.
     *
     * `not_an_event_count` moves only for the reason that
     * {@see SignalDismissReason::indicatesMisclassification()} recognises — the one that says the
     * *classifier* was wrong rather than that the team was not interested. That distinction is the
     * whole reason the column exists, and it is what separates a "stop showing me this outlet"
     * proposal from a "raise this type's floor" one.
     */
    public function recordDismissed(Signal $signal, ?SignalDismissReason $reason): void
    {
        $this->bump($signal, $this->dismissalColumns($reason), up: true);
    }

    /** The team took a dismissal back. */
    public function recordRestored(Signal $signal, ?SignalDismissReason $reason): void
    {
        $this->bump($signal, $this->dismissalColumns($reason), up: false);
    }

    /**
     * The team turned a signal from this outlet into a lead — the only outcome that says the
     * signal was worth money, which is why {@see SignalSourceStat::authority()} counts it double.
     */
    public function recordConverted(Signal $signal): void
    {
        $this->bump($signal, ['converted_count'], up: true);
    }

    /**
     * The counters a dismissal moves: always the dismissal itself, and the misclassification
     * tally only when the reason says the classifier was at fault.
     *
     * One method for both directions, so a restore can never take back a different set of
     * counters from the one the dismissal added — which is the way a subset relationship between
     * two tallies actually breaks.
     *
     * @return list<string>
     */
    private function dismissalColumns(?SignalDismissReason $reason): array
    {
        return $reason?->indicatesMisclassification() === true
            ? ['dismissed_count', 'not_an_event_count']
            : ['dismissed_count'];
    }

    /**
     * Move these counters by one, up or down, on this signal's `(source, event type)` row.
     *
     * Scoped explicitly by `team_id` on every statement: this runs from a listener, which has no
     * request behind it when the triggering Action was a queued run.
     *
     * @param  list<string>  $columns
     */
    private function bump(Signal $signal, array $columns, bool $up): void
    {
        $sourceKey = $this->normaliser->sourceKey($signal->source_name);

        // No outlet, nothing to learn about one. The provider omitting `source` is common enough
        // that this is an ordinary path rather than an error.
        if ($sourceKey === '' || $columns === []) {
            return;
        }

        $teamId = (int) $signal->team_id;
        $now = now();

        $updates = ['last_seen_at' => $now, 'updated_at' => $now];

        foreach ($columns as $column) {
            $updates[$column] = $up ? $this->plusOne($column) : $this->minusOne($column);
        }

        $affected = SignalSourceStat::query()
            ->where('team_id', $teamId)
            ->where('source_key', $sourceKey)
            ->where('event_key', $signal->event_key)
            ->update($updates);

        if ($affected > 0) {
            return;
        }

        // First time this team has seen this outlet for this type. A decrement against a row that
        // does not exist is nothing to record — there is no dismissal to take back — so a
        // downward bump inserts the row with zeroes rather than inventing a negative history.
        $insert = [
            'team_id' => $teamId,
            'source_key' => $sourceKey,
            'source_name' => $signal->source_name,
            'signal_definition_id' => $signal->signal_definition_id,
            'event_key' => $signal->event_key,
            'kept_count' => 0,
            'dismissed_count' => 0,
            'converted_count' => 0,
            'not_an_event_count' => 0,
            'last_seen_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        if ($up) {
            foreach ($columns as $column) {
                $insert[$column] = 1;
            }
        }

        SignalSourceStat::query()->insertOrIgnore($insert);
    }

    /**
     * `column + 1`, as a **literal** expression.
     *
     * A `match` over the four column names rather than string interpolation, and that is not
     * ceremony: `DB::raw()` takes a literal string for a reason, and a helper that interpolates
     * its argument is the shape every raw-SQL injection has — even when today's only caller passes
     * a constant. The analyser enforces it, and it is right to.
     */
    private function plusOne(string $column): Expression
    {
        return match ($column) {
            'kept_count' => DB::raw('kept_count + 1'),
            'dismissed_count' => DB::raw('dismissed_count + 1'),
            'converted_count' => DB::raw('converted_count + 1'),
            'not_an_event_count' => DB::raw('not_an_event_count + 1'),
            default => throw new InvalidArgumentException("[{$column}] is not a source statistic."),
        };
    }

    /**
     * `column - 1`, flooring at zero, as a literal expression.
     *
     * The floor is in **SQL** rather than in PHP because the read-then-write PHP would need is
     * exactly the race one statement avoids. A counter that went negative would make a rate
     * greater than one and an authority above a hundred.
     *
     * Only the two counters a restore can take back are listed. A conversion and a keep are not
     * undoable events — there is no "unconvert" — so asking to decrement either is a programming
     * error rather than a case to handle.
     */
    private function minusOne(string $column): Expression
    {
        return match ($column) {
            'dismissed_count' => DB::raw('case when dismissed_count >= 1 then dismissed_count - 1 else 0 end'),
            'not_an_event_count' => DB::raw('case when not_an_event_count >= 1 then not_an_event_count - 1 else 0 end'),
            default => throw new InvalidArgumentException("[{$column}] cannot be taken back."),
        };
    }
}
