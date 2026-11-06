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
            // Where an entity-scoped run got to in its watchlist (S10).
            //
            // **Why a cursor exists at all.** A pipeline watchlist is capped at 250 accounts and a
            // run is capped at `signals.max_queries_per_run` (40) — which is not a mismatch to be
            // fixed by raising the ceiling, because every query is money. So a run searches a
            // **window** of the list and the next run continues from where this one stopped: a
            // daily watch over 250 accounts covers all of them about every six days, for the price
            // of forty searches a day.
            //
            // The alternative was trimming to the first forty entries, which is what the topical
            // trim does — and there it is right, because places are few and a member chose them in
            // order. Here it would mean the eighty-ninth account in a mirrored pipeline was never
            // searched at all, silently, forever.
            $table->unsignedInteger('entity_cursor')->default(0)->after('signal_watchlist_id');
        });
    }

    public function down(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropColumn('entity_cursor');
        });
    }
};
