<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A run records how many searches it **wanted**, beside how many it ran (S8, ADR 0019 §4).
     *
     * `queries_count` is what was executed and `queries_requested` is what the plan asked for, so
     * the difference is a trim. Without the second number the ceiling
     * (`signals.max_queries_per_run`) is invisible: a team adds places until coverage silently
     * stops growing and nothing on screen says why. S8 makes that reachable in a second dimension —
     * templates **x** locations — which is exactly when a silent trim becomes a lie about what was
     * searched.
     *
     * Nullable, so a run from before this column reads as "nobody recorded it" rather than as a run
     * that wanted nothing. The resource and the watch card both treat null as "no trim to report".
     */
    public function up(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->unsignedInteger('queries_requested')->nullable()->after('queries_count');
        });
    }

    public function down(): void
    {
        Schema::table('signal_runs', function (Blueprint $table): void {
            $table->dropColumn('queries_requested');
        });
    }
};
