<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes for the two kinds of query S15 and S16 added and never covered: the **allowance reads**
 * that run on every watch run, and the **nightly retention sweeps**.
 *
 * Neither was wrong, and both were invisible on SQLite with a few hundred rows.
 *
 * **1. The allowance reads, which are on the hot path.** `SignalSpendGuard` asks
 * `SignalUsageCounter` for a team's month-to-date spend before every run and on the screens that
 * show what is left, so these two run constantly:
 *
 * - `sourceQueriesBetween()` filters `signal_runs` by `team_id` + `status` + a `finished_at`
 *   range. The existing `(team_id, status)` is **replaced** by `(team_id, status, finished_at)`
 *   rather than added to: the old index is a leading prefix of the new one, so everything it
 *   served the new one still serves, and the range is no longer a filter over the whole status
 *   partition. Net zero indexes on the table for a strictly better one.
 * - `articleFetchMicrosThisMonth()` filters `signals` by `team_id` + an `article_fetched_at`
 *   range, and `signals` is the largest table in the module. Nothing led with that column.
 *
 * **2. The retention sweeps, where the cost is quadratic rather than linear.** This is the part
 * worth reading twice. `PruneSignalsCommand::sweep()` deliberately re-applies the eligibility
 * query at each chunk — read-ids-then-delete-by-id is a bug that command has already had — so the
 * loop is `... order by id limit 500` run once **per chunk**, not once per sweep. With no index on
 * the column the window is measured on, every one of those chunks is a full table scan: a backlog
 * of 50,000 eligible rows is a hundred scans of the table, nightly, and it gets worse as the table
 * grows rather than as the backlog does. An index on the time column turns each chunk into a range
 * scan.
 *
 * The sweeps are **cross-team by design** (retention is a promise to everybody, not a per-tenant
 * feature), which is why a `(team_id, …)` index cannot serve them — `team_id` leads, and the sweep
 * has no team. So the five fastest-growing swept tables get an index led by their own clock.
 *
 * **Deliberately not indexed.** `signal_source_stats` is bounded by team × source and stays small
 * enough to scan. `signal_clusters`' orphan sweep is dominated by its `whereDoesntHave`, not by
 * the `updated_at` filter, and nearly every cluster is older than the hour it tests, so an index
 * there would narrow almost nothing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->index(['team_id', 'status', 'finished_at'], 'signal_runs_team_status_finished_index');
            $table->index('finished_at', 'signal_runs_finished_index');
        });

        // Dropped only once its replacement exists, so no window has the table unindexed for the
        // query the guard runs on every run.
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'status']);
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->index(['team_id', 'article_fetched_at'], 'signals_team_fetched_index');
            $table->index(['status', 'created_at'], 'signals_status_created_index');
        });

        Schema::table('signal_previews', function (Blueprint $table): void {
            $table->index('created_at', 'signal_previews_created_index');
        });

        Schema::table('signal_playbook_runs', function (Blueprint $table): void {
            $table->index('created_at', 'signal_playbook_runs_created_index');
        });

        Schema::table('signal_definition_spend', function (Blueprint $table): void {
            $table->index('day', 'signal_definition_spend_day_index');
        });
    }

    public function down(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->index(['team_id', 'status']);
        });

        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropIndex('signal_runs_team_status_finished_index');
            $table->dropIndex('signal_runs_finished_index');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_fetched_index');
            $table->dropIndex('signals_status_created_index');
        });

        Schema::table('signal_previews', function (Blueprint $table): void {
            $table->dropIndex('signal_previews_created_index');
        });

        Schema::table('signal_playbook_runs', function (Blueprint $table): void {
            $table->dropIndex('signal_playbook_runs_created_index');
        });

        Schema::table('signal_definition_spend', function (Blueprint $table): void {
            $table->dropIndex('signal_definition_spend_day_index');
        });
    }
};
