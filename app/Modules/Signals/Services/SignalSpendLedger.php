<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Support\SignalDefinitionBreakdown;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;

/**
 * Writes a finished run's per-type figures into `signal_definition_spend` (S15 task 2).
 *
 * One job, and the shape of it is the point: a run hands over a
 * {@see SignalDefinitionBreakdown} and this turns it into **one row per type per day**, adding to
 * whatever that day already holds. Several runs a day are normal — an hourly cadence, a manual
 * "run now" — and a row per run would make the ROI read a scan rather than an indexed aggregate.
 *
 * **This table is a running total, and that is a real departure from `usage_meters`.** The review
 * was right to push on the comment that used to be here: `incrementEach` is an increment, a row
 * carries no `run_id`, and nothing can recompute it from an underlying ledger the way
 * `usage_meters` can be recomputed from `ai_requests`. Calling it "a record, not a counter" would
 * be borrowing the credibility of a pattern it does not follow.
 *
 * What is true, and what the figure can be trusted for: the addition happens **once per run**,
 * **after** the run has finished and both halves of its cost are known, from a breakdown the run
 * accumulated as it went. So the number answers "what has this type cost this team, by day" and it
 * cannot be reconciled line by line against individual runs. The alternative — a row per
 * `(run, type)` — would be reconcilable and would turn the ROI read from an indexed aggregate over
 * days into a scan over every run a team has ever made, for a reconciliation nothing asks for. It
 * is recorded here as the deliberate trade it is, and the column to add if that ever changes is
 * `signal_run_id` with the unique moved onto it.
 *
 * The write is a raw upsert rather than a read-then-save, because two of a team's watches can
 * finish in the same second and a read-modify-write would lose one of their costs. Expressed with
 * `DB::raw` on the increment side so the database does the addition under its own unique index.
 *
 * Must be called inside the team's context.
 */
final class SignalSpendLedger
{
    /**
     * Record a run's spend, attributing each type's share to the UTC day the run finished on.
     *
     * **The day is the run's, not the signal's.** A signal carries its own `created_at` and the ROI
     * report scopes *findings* by that; this table scopes *spending*, and spending happened when
     * the search ran. The two windows agree for every run that is not straddling midnight, and
     * where they disagree the honest answer for a cost is when it was incurred.
     *
     * @param  array<string, int>  $definitionIds  `event_key` => live definition id, for attribution
     */
    public function record(int $teamId, SignalDefinitionBreakdown $breakdown, array $definitionIds = []): void
    {
        if ($breakdown->isEmpty()) {
            return;
        }

        $day = Date::now()->utc()->toDateString();
        $now = Date::now();
        $rows = [];

        foreach ($breakdown->toArray() as $eventKey => $figures) {
            if ($figures['queries'] < 1
                && $figures['search_cost_micros'] < 1
                && $figures['ai_cost_micros'] < 1) {
                // A type that was offered to the classifier and never searched costs nothing and
                // found nothing. A zero row would make the ROI card's "queries" column read as
                // though it had been tried.
                continue;
            }

            $rows[] = [
                'team_id' => $teamId,
                'event_key' => $eventKey,
                'signal_definition_id' => $definitionIds[$eventKey] ?? null,
                'day' => $day,
                'queries' => $figures['queries'],
                'results' => $figures['results'],
                'search_cost_micros' => $figures['search_cost_micros'],
                'ai_cost_micros' => $figures['ai_cost_micros'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if ($rows === []) {
            return;
        }

        DB::transaction(static function () use ($rows): void {
            foreach ($rows as $row) {
                // **Two portable statements rather than one clever upsert.** An `upsert` whose
                // update clause adds to the existing value has to name it, and the dialects
                // disagree about how (`VALUES(col)` on MySQL, `excluded.col` on SQLite) — which is
                // precisely the class of difference a green SQLite suite says nothing about
                // (`memory/sqlite-tests-mysql-prod.md`).
                //
                // `insertOrIgnore` claims the day's row under the unique index, and
                // `incrementEach` does the arithmetic in the database with bound parameters. Two
                // statements, no raw SQL, and correct when two of a team's watches finish in the
                // same second — where a read-modify-write would lose one of their costs.
                SignalDefinitionSpend::query()->insertOrIgnore([
                    'team_id' => $row['team_id'],
                    'event_key' => $row['event_key'],
                    'signal_definition_id' => $row['signal_definition_id'],
                    'day' => $row['day'],
                    'queries' => 0,
                    'results' => 0,
                    'search_cost_micros' => 0,
                    'ai_cost_micros' => 0,
                    'created_at' => $row['created_at'],
                    'updated_at' => $row['updated_at'],
                ]);

                SignalDefinitionSpend::query()
                    ->where('team_id', $row['team_id'])
                    ->where('event_key', $row['event_key'])
                    ->where('day', $row['day'])
                    ->incrementEach([
                        'queries' => $row['queries'],
                        'results' => $row['results'],
                        'search_cost_micros' => $row['search_cost_micros'],
                        'ai_cost_micros' => $row['ai_cost_micros'],
                    ], [
                        // The key is the identity; this is only the live attribution, and a type
                        // cloned, deleted and re-created inside a day should point at the one that
                        // exists now.
                        'signal_definition_id' => $row['signal_definition_id'],
                        'updated_at' => $row['updated_at'],
                    ]);
            }
        });
    }
}
