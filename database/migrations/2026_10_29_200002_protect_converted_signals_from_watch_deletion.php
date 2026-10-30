<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stop a watch deletion destroying the origin of a converted lead.
     *
     * `signals.signal_watch_id` and `signal_run_id` were `cascadeOnDelete`, so removing a watch
     * removed every signal under it — **including Converted ones**, which are the provenance of
     * real leads. The lead row survived (its own FK is one-way), but the lead silently lost its
     * origin panel, and both `compliance.md` §5a and `signals:prune` promise the opposite: a
     * converted signal lives as long as its lead does.
     *
     * Both FKs become `nullOnDelete` and both columns nullable, so the **database itself** protects
     * provenance however a watch comes to be deleted. `DeleteSignalWatchAction` still removes the
     * watch's un-converted signals explicitly, so nothing is left orphaned that nobody wanted —
     * a converted signal simply loses the "found by" attribution it no longer has a row for.
     */
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropForeign(['signal_watch_id']);
            $table->dropForeign(['signal_run_id']);
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->unsignedBigInteger('signal_watch_id')->nullable()->change();
            $table->unsignedBigInteger('signal_run_id')->nullable()->change();
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->foreign('signal_watch_id')->references('id')->on('signal_watches')->nullOnDelete();
            $table->foreign('signal_run_id')->references('id')->on('signal_runs')->nullOnDelete();
        });
    }

    public function down(): void
    {
        // Reverting means converted signals can be destroyed by a watch deletion again, so any
        // already-detached row has to go first — there is nothing valid to point it at.
        Schema::table('signals', function (Blueprint $table): void {
            $table->dropForeign(['signal_watch_id']);
            $table->dropForeign(['signal_run_id']);
        });

        DB::table('signals')
            ->whereNull('signal_watch_id')
            ->orWhereNull('signal_run_id')
            ->delete();

        Schema::table('signals', function (Blueprint $table): void {
            $table->unsignedBigInteger('signal_watch_id')->nullable(false)->change();
            $table->unsignedBigInteger('signal_run_id')->nullable(false)->change();
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->foreign('signal_watch_id')->references('id')->on('signal_watches')->cascadeOnDelete();
            $table->foreign('signal_run_id')->references('id')->on('signal_runs')->cascadeOnDelete();
        });
    }
};
