<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Team suspension (Phase 19a).
 *
 * The abuse and non-payment lever. A suspended team's members can still sign in and read their own
 * data; what stops is **sourcing, sending and spending** — the three things that cost us money or
 * reach a third party. Locking people out of data they own is both the wrong lever (it punishes the
 * colleague who did nothing) and a data-protection problem (they cannot exercise their own rights
 * through a door we have bolted).
 *
 * A nullable timestamp rather than a boolean, because "since when" is the first question anybody
 * asks about a suspension, and `suspension_reason` because the second is "why" — and neither
 * belongs only in an audit entry somebody has to go and find.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->timestamp('suspended_at')->nullable()->index();
            $table->string('suspension_reason', 500)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropIndex(['suspended_at']);
            $table->dropColumn(['suspended_at', 'suspension_reason']);
        });
    }
};
