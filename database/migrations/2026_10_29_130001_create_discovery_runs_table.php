<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('discovery_runs', function (Blueprint $table): void {
            // A single lead-discovery job against one provider (Phase 7). Team-scoped via
            // BelongsToTeam; optionally tied to the ICP that shaped it. Tracks status, the query
            // params, the resulting counts and the metered cost so Reporting + Billing can read it.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The ICP that shaped the search; nullable so a run survives the ICP being deleted.
            $table->foreignId('icp_id')->nullable()->constrained('icps')->nullOnDelete();

            $table->string('source', 32);   // LeadSource value (provider)
            $table->string('status', 16);   // DiscoveryRunStatus value

            // The resolved query the provider was given (terms, location, limit) — stored so a run
            // is reproducible and auditable. Contains no secrets.
            $table->json('params')->nullable();

            // Requested cap vs. what the provider returned vs. what we actually persisted (after
            // the dedupe placeholder). Kept distinct so Reporting can show yield.
            $table->unsignedInteger('requested_limit')->default(0);
            $table->unsignedInteger('leads_found')->default(0);
            $table->unsignedInteger('leads_created')->default(0);

            // Metered cost of the run in integer micro-units (1_000_000 micros = one major unit);
            // mirrors ai_requests / business_intel_reports. Free providers record 0.
            $table->unsignedBigInteger('cost_micros')->default(0);

            // Set when the run could not complete; a short, non-sensitive reason for the UI.
            $table->string('error_message')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            // The member who started the run (nullable for system-triggered runs later).
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('discovery_runs');
    }
};
