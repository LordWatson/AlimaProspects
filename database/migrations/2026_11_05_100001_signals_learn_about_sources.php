<?php

declare(strict_types=1);

use App\Modules\Signals\Enums\SignalSourceKind;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * S13a — a definition stops meaning "a Serper `/news` query" (ADR 0023).
 *
 * Four columns and one backfill. The backfill is the whole compatibility story: every definition
 * that exists is running on the news and nothing else, so writing `["news"]` onto each one makes
 * that explicit rather than implied, and every S7–S12 definition keeps behaving exactly as it did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_definitions', function (Blueprint $table): void {
            // Which sources this type runs on (S13). A list, because the same type is worth asking
            // of more than one place: an acquisition is reported by the press *and* filed at
            // Companies House, and the two have different latencies and different certainties.
            //
            // Backfilled to `["news"]` below. `SignalDefinition::sourceKinds()` also treats a null
            // or empty column as `["news"]`, so a row written by an older code path cannot produce
            // a type that searches **nowhere** — "searched nothing and completed successfully" is
            // the most confusing failure this feature could have, and S8 already learned it once
            // with locations.
            $table->json('source_keys')->nullable()->after('query_templates_i18n');

            // Per-source settings, keyed by source: a SIC-code list for Companies House, a feed URL
            // for RSS, a webhook's secret id. Nullable and empty for both of S13a's sources, which
            // need nothing beyond the templates the type already has.
            //
            // One column rather than one per source, because the alternative is a column added to
            // every tenant's table each time a source ships — and because a provider is handed only
            // its own slice (`SignalSourceQuery::$config`), so nothing can read another source's
            // configuration by accident.
            $table->json('source_config')->nullable()->after('source_keys');
        });

        Schema::table('signal_runs', function (Blueprint $table): void {
            // What each source cost and found in this run (S13, task 8): per kind, its `queries`,
            // `results`, `rejected` and `cost_micros`.
            //
            // `signal_runs.cost_micros` stays the single total, so nothing that reads it changes —
            // this is the breakdown beneath it. Stored rather than recomputed, for the reason S12
            // established one level down: a figure the UI explains has to be the figure the decision
            // was made on, not one re-derived later from today's prices.
            $table->json('source_breakdown')->nullable()->after('cost_micros');
        });

        Schema::table('signals', function (Blueprint $table): void {
            // Which source found this signal. **Not nullable and defaulted**, because the question
            // has an answer for every row that has ever existed: everything before S13 came from
            // the news, and a null would make the card and the feed filter cope with an absence
            // that is not real.
            $table->string('source_kind', 32)
                ->default(SignalSourceKind::News->value)
                ->after('source_name');

            // The query that found it (S13), which closes the gap S12 recorded as its second
            // deviation: the explanation panel could name the definition, its version, the watch
            // and the whole score breakdown, but not which of the type's templates actually
            // matched — because no row stored it.
            //
            // Nullable: a signal from a source that does not search by keyword (a filing, a feed
            // item, a pushed event) has no template behind it, and inventing one would be a lie on
            // a panel whose entire job is to be trusted.
            $table->string('matched_template')->nullable()->after('source_kind');
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The feed's source filter, inside the status tab like every other feed index.
            $table->index(['team_id', 'source_kind', 'status'], 'signals_team_source_status_index');
        });

        // The backfill. Every existing type runs on the news, which is what it was already doing.
        // Written as a literal rather than through the model so no global scope, cast or accessor
        // can change what lands — a migration has to mean the same thing in five years.
        //
        // Both tenant rows and platform presets (`team_id is null`), deliberately: a preset a team
        // clones must arrive with its sources set, or the clone would be the one definition that
        // searches nowhere.
        DB::table('signal_definitions')
            ->whereNull('source_keys')
            ->update(['source_keys' => json_encode([SignalSourceKind::News->value])]);
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_source_status_index');
            $table->dropColumn(['source_kind', 'matched_template']);
        });

        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropColumn('source_breakdown');
        });

        Schema::table('signal_definitions', function (Blueprint $table): void {
            $table->dropColumn(['source_keys', 'source_config']);
        });
    }
};
