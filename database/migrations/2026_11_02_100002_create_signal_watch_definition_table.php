<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Which definitions a watch watches for (ADR 0018 §5).
     *
     * Replaces `signal_watches.event_types` (a JSON list of enum values). **A pivot rather than a
     * `signal_definition_ids` JSON column**, which is a deliberate deviation from the S7 plan, for
     * one decisive reason: the delete path has to answer "which watches use this definition?", and
     * over a JSON column that is `JSON_CONTAINS` on MySQL and `json_each` on SQLite — two different
     * queries, only one of which this suite would ever run. `memory/sqlite-tests-mysql-prod.md`
     * exists because that class of difference has already bitten here. Over a pivot it is a join,
     * identical on both.
     *
     * Two things fall out of it for free: `$watch->definitions` is a real relation, so the resource,
     * the run and the screen eager-load rather than hand-rolling a `whereIn`; and the FK means the
     * **database** stops a watch pointing at a definition that no longer exists.
     */
    public function up(): void
    {
        Schema::create('signal_watch_definition', function (Blueprint $table): void {
            $table->id();

            // Redundant with both parents, and kept anyway - but **defence in depth, not a
            // database guarantee**: these are three single-column foreign keys, so nothing here
            // stops a row claiming team 1 while pairing team 2's watch with team 3's definition.
            // What actually prevents that is `SaveSignalWatchAction` (the only writer; it resolves
            // every id through a team-scoped query and stamps the acting team) and
            // `RunSignalWatchAction` (which re-checks `team_id` on both sides before running). This
            // column is what makes a bad row *findable*, and what a composite FK would hang off if
            // one is ever wanted.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->foreignId('signal_watch_id')->constrained('signal_watches')->cascadeOnDelete();
            $table->foreignId('signal_definition_id')->constrained('signal_definitions')->cascadeOnDelete();

            $table->timestamps();

            // **Both indexes are named explicitly**, because Laravel's generated names are
            // `signal_watch_definition_signal_watch_id_signal_definition_id_unique` — 67
            // characters, past MySQL's 64-character identifier limit, and error 1059 rather than a
            // slow query. SQLite has no such limit and accepted it without comment, which is the
            // first item in `memory/sqlite-tests-mysql-prod.md` and was found here by running this
            // against the local MySQL.
            //
            // A watch watches for a type once.
            $table->unique(['signal_watch_id', 'signal_definition_id'], 'signal_watch_def_unique');
            // "Which watches use this definition?" — the delete refusal's query.
            $table->index(['team_id', 'signal_definition_id'], 'signal_watch_def_team_def_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_watch_definition');
    }
};
