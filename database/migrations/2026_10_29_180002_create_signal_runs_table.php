<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_runs', function (Blueprint $table): void {
            // One execution of a signal_watch (ADR 0011). Records how many queries ran, how many
            // news results came back, how many signals were kept, and the metered cost (Serper + AI)
            // so Reporting + Billing can read it — mirroring discovery_runs. Team-scoped.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('signal_watch_id')->constrained('signal_watches')->cascadeOnDelete();

            $table->string('status', 16);   // SignalRunStatus value

            $table->unsignedInteger('queries_count')->default(0);
            $table->unsignedInteger('results_count')->default(0);
            $table->unsignedInteger('signals_created')->default(0);

            // Metered cost of the run in integer micro-units (1_000_000 micros = one major unit);
            // mirrors discovery_runs / ai_requests.
            $table->unsignedBigInteger('cost_micros')->default(0);

            // Set when the run could not complete; a short, user-safe reason for the UI.
            $table->string('error')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['team_id', 'signal_watch_id']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_runs');
    }
};
