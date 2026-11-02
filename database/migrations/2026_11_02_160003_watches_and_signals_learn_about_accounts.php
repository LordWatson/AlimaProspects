<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            // A watch is **either** topical — these signal types, in these places — **or**
            // entity-scoped: these signal types, about these named accounts. One nullable FK rather
            // than a `kind` column plus two half-filled column sets, because the presence of the
            // list is the whole distinction and a `kind` that could disagree with it would be a
            // second source of truth.
            //
            // `nullOnDelete`: deleting a watchlist turns its watch back into a topical one rather
            // than deleting the watch, which would take its runs and the signals those found with
            // it. A watch that suddenly searches a place instead of an account is visible on the
            // card; a watch that vanished is not.
            $table->foreignId('signal_watchlist_id')
                ->nullable()
                ->after('icp_id')
                ->constrained('signal_watchlists')
                ->nullOnDelete();
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The lead this event is **about** — distinct from `lead_id`, which means "was
            // converted into". The difference is the point of the phase: a matched signal belongs
            // on a lead's page the morning it is published, without anybody converting anything
            // and without a second row for a company the team already has.
            //
            // Also `nullOnDelete`, and for the same reason as `lead_id`: Lead Sourcing never
            // depends on Signals, in either direction.
            $table->foreignId('matched_lead_id')
                ->nullable()
                ->after('lead_id')
                ->constrained('leads')
                ->nullOnDelete();

            // How sure the matcher was, as a `SignalMatchConfidence` value. Stored because a
            // member is shown it — "err toward no match, show the confidence" is the risk note
            // this phase was handed — and because a later change to the matching rules must not
            // silently re-label what an earlier one decided.
            $table->string('match_confidence', 16)->nullable()->after('matched_lead_id');
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The feed's "My accounts" tab: this team's matched signals, newest first.
            $table->index(
                ['team_id', 'matched_lead_id', 'published_at'],
                'signals_team_matched_published_index',
            );
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_matched_published_index');
            $table->dropConstrainedForeignId('matched_lead_id');
            $table->dropColumn('match_confidence');
        });

        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('signal_watchlist_id');
        });
    }
};
