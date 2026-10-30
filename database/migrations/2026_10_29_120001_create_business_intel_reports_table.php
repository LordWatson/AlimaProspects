<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('business_intel_reports', function (Blueprint $table): void {
            // A versioned AI background check on the signed-up business (not on prospects).
            // Team-scoped via BelongsToTeam; many rows per team (one per re-run), the highest
            // version being the latest. The structured AI output lives in `findings`; the prompt
            // key/version/model that produced it are stored for traceability.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Monotonic per team — bumped on every re-run so history is preserved.
            $table->unsignedInteger('version')->default(1);
            $table->string('status', 16); // BackgroundCheckStatus value

            // The business as it was when the check ran (so a report is reproducible/diff-able even
            // after the profile changes). No personal data — company-level facts only.
            $table->string('business_name')->nullable();
            $table->string('website')->nullable();

            // Validated structured output from `business_intel.background_check` (summary, offering,
            // positioning, own_industry, customer_industries, target_customers,
            // decision_maker_titles, competitors, confidence — see BackgroundCheckFindings; rows
            // written under prompt v1 instead carry a single `suggested_industries` list).
            $table->json('findings')->nullable();

            // Provenance of the AI run (trace any output back to the exact prompt + model).
            $table->string('prompt_key')->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model')->nullable();
            // Cost in integer micro-units (1_000_000 micros = one major unit); mirrors ai_requests.
            $table->unsignedBigInteger('cost_micros')->default(0);

            // Set when the run could not complete; a short, non-sensitive reason for the UI.
            $table->string('error_message')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            $table->unique(['team_id', 'version']);
            $table->index(['team_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('business_intel_reports');
    }
};
