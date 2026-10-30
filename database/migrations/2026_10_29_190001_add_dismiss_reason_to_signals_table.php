<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            // Why a member dismissed the signal (SignalDismissReason value), or null — the reason is
            // optional, so a member can clear a card without justifying it. Kept for tuning the
            // queries and the relevance floor (ADR 0011, S4); a restore clears it.
            $table->string('dismiss_reason', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropColumn('dismiss_reason');
        });
    }
};
