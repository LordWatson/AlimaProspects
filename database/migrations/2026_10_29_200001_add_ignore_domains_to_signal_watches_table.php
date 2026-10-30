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
            // Per-team source domains to drop from this watch's results, merged with the global
            // `signals.ignore_domains` list in SerperNewsClient (ADR 0011, S6 — deferred here from
            // S1 per the plan). The case it exists for: a client's own competitors publish news
            // that keeps surfacing as "events", and only that team knows who those are.
            // Null rather than an empty array by default, so "never configured" is distinguishable
            // from "deliberately empty".
            $table->json('ignore_domains')->nullable()->after('event_types');
        });
    }

    public function down(): void
    {
        Schema::table('signal_watches', function (Blueprint $table): void {
            $table->dropColumn('ignore_domains');
        });
    }
};
