<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // --- Enrichment & Data Quality (Phase 8) ---
            // Pipeline state + freshness for the enrichment job, kept separate from the
            // sourcing/scoring `status` so data-quality work is tracked independently.
            $table->string('enrichment_status', 24)->default('pending')->after('status');
            $table->timestamp('enriched_at')->nullable()->after('collected_at'); // data-freshness stamp
            $table->unsignedBigInteger('enrichment_cost_micros')->default(0)->after('enriched_at');

            // Facts derived by AI extraction from the lead's public web text (never asserted as hard
            // truth — provenance lives in `field_sources`).
            $table->string('industry')->nullable()->after('company_type');
            $table->string('employee_range', 64)->nullable()->after('industry');
            $table->json('technologies')->nullable()->after('employee_range');

            // Per-field provenance map (field name => SourcedValue: source / source_url /
            // collected_at / method), so every enriched field can be traced (ADR 0008).
            $table->json('field_sources')->nullable()->after('raw');

            // Compliance: flagged true when any contact looks like a personal (non-role) identity, so
            // downstream outreach/compliance can prefer business/role data (docs/compliance.md).
            $table->boolean('has_personal_data')->default(false)->after('field_sources');

            $table->index(['team_id', 'enrichment_status']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'enrichment_status']);
            $table->dropColumn([
                'enrichment_status',
                'enriched_at',
                'enrichment_cost_micros',
                'industry',
                'employee_range',
                'technologies',
                'field_sources',
                'has_personal_data',
            ]);
        });
    }
};
