<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_definition_spend', function (Blueprint $table): void {
            // What each signal type cost, per day (S15 task 2).
            //
            // **Why this table has to exist.** Up to S14 a run's cost was recorded on the run
            // (`signal_runs.cost_micros`, broken down per *source* since S13) — and a run covers
            // every type on the watch at once. So the one question a team most needs answered about
            // a type they are paying to run, "is it worth it?", had no number behind it at all. A
            // type with 400 signals, 3 kept and 0 converted over 60 days is the product telling the
            // truth about itself, and it cannot tell it without this.
            //
            // **A row is a running total per day, which is not the `usage_meters` pattern** — and
            // the difference is worth stating plainly rather than borrowing that pattern's name
            // (review). There is no `signal_run_id`, so a row cannot be recomputed from an
            // underlying ledger the way `usage_meters` can be recomputed from `ai_requests`, and a
            // day's figure cannot be reconciled against the individual runs that made it.
            //
            // What *is* true: a finished run computes its own figures from a breakdown it
            // accumulated as it went, and adds them **once**, after both halves of its cost are
            // known. So the figures are at the prices in force when each run made them, and a
            // later change to `signals.sources.*.cost_micros_per_request` cannot rewrite history.
            // The alternative — a row per `(run, type)` — would be reconcilable and would turn the
            // ROI read from an indexed aggregate over days into a scan over every run a team has
            // ever made. If that trade is ever revisited, the column to add is `signal_run_id`
            // with the unique index moved onto it.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // **Keyed by `event_key`, with the definition id beside it** — the same arrangement
            // `signals` itself uses and for the same reason (ADR 0018 §4). The record of what a
            // type cost is the most useful thing to keep about one a team has since deleted, so
            // the key is the identity and the foreign key is only the live attribution.
            $table->string('event_key', 64);
            $table->foreignId('signal_definition_id')
                ->nullable()
                ->constrained('signal_definitions')
                ->nullOnDelete();

            // The day in **UTC**, which is the only way a figure summed across a window can be
            // compared with one summed across another (`DateRange::constrain()`'s rule, one level
            // up). A team reads it in their own timezone; nothing stores it in one.
            $table->date('day');

            // Queries this type was searched with, and the results they returned. The results are
            // what the AI share below is allocated by, so they are stored rather than derived.
            $table->unsignedInteger('queries')->default(0);
            $table->unsignedInteger('results')->default(0);

            // **Two cost columns, because they are two different kinds of figure.**
            //
            // `search_cost_micros` is *measured*: a query belongs to exactly one type, so its cost
            // is attributable without judgement.
            //
            // `ai_cost_micros` is *allocated*. One classification call is offered every type on the
            // watch at once — that sharing is where S11's clustering saving comes from — so no
            // honest measurement exists, and this is the run's AI cost apportioned by each type's
            // share of the results it classified. Kept in its own column precisely so the
            // difference is visible: a screen that showed one total would be presenting an
            // allocation as a measurement.
            $table->unsignedBigInteger('search_cost_micros')->default(0);
            $table->unsignedBigInteger('ai_cost_micros')->default(0);

            $table->timestamps();

            // One row per type per day, which is what makes the write an upsert and the read an
            // indexed aggregate rather than a json walk.
            $table->unique(['team_id', 'event_key', 'day'], 'signal_definition_spend_day_unique');
            // The ROI read: this team's spend over a window.
            $table->index(['team_id', 'day'], 'signal_definition_spend_team_day_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_definition_spend');
    }
};
