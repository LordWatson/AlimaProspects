<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A signal points at the definition that classified it, and keeps its key either way
     * (ADR 0018 §4).
     *
     * Two columns, and the second one is the interesting one:
     *
     * - `signal_definition_id` — nullable, `nullOnDelete`. The live attribution.
     * - `event_key` — v1's `event_type`, **renamed in place** rather than added beside and
     *   backfilled. A rename needs no backfill and leaves no dead column, and `event_type` would
     *   have become a misleading name the moment the enum went. It is renamed only; the width stays
     *   32, which is why `signal_definitions.key` is 32 too — widening means a table rebuild on
     *   SQLite and a width MySQL alone enforces, so the two would have agreed on one engine only.
     *
     * The key is denormalised on purpose. A definition can be deleted; a signal from it is history
     * and must still render, group and report — the same shape, for the same reason, as
     * `signals.lead_id`. The S6 migration (`protect_converted_signals_from_watch_deletion`) learned
     * this when a cascade destroyed the provenance of leads teams were still working.
     *
     * No new index. The feed's `(team_id, status, published_at)` already covers it, and the event
     * key narrows a result set that index has already found; Reporting groups by the key inside the
     * same `team_id` + `status` prefix. An index added on a guess is an index nobody can justify
     * later.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->foreignId('signal_definition_id')
                ->nullable()
                ->after('signal_run_id')
                ->constrained('signal_definitions')
                ->nullOnDelete();
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->renameColumn('event_type', 'event_key');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->renameColumn('event_key', 'event_type');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signal_definition_id');
        });
    }
};
