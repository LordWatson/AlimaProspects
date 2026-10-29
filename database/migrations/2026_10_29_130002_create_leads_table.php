<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table): void {
            // A raw, company-level lead as discovered from an external provider (Phase 7), before
            // Enrichment (Phase 8) and Scoring (Phase 9). Team-scoped via BelongsToTeam. Lead
            // Sourcing never writes personal contact data — company facts only (ADR 0008 /
            // compliance preference for business/role data).
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // The run that discovered it; nullable so a lead outlives its run being pruned.
            $table->foreignId('discovery_run_id')->nullable()->constrained('discovery_runs')->nullOnDelete();

            $table->string('source', 32);   // LeadSource value — which provider this came from
            $table->string('status', 24)->default('discovered'); // LeadStatus value

            // --- Company facts (all nullable; providers vary in what they return) ---
            $table->string('company_name');
            $table->string('company_number')->nullable();      // registry number (Companies House)
            $table->string('company_status')->nullable();      // e.g. active / dissolved
            $table->string('company_type')->nullable();        // e.g. ltd / plc
            $table->date('incorporated_on')->nullable();
            $table->string('website')->nullable();

            // Registered / primary address, flattened for filtering plus the full structured form.
            $table->string('locality')->nullable();
            $table->string('region')->nullable();
            $table->string('postal_code')->nullable();
            $table->string('country', 2)->nullable();          // ISO-3166 alpha-2 where known
            $table->json('address')->nullable();
            // Standard Industrial Classification codes (Companies House), as a list of strings.
            $table->json('sic_codes')->nullable();

            // --- Source attribution (ADR 0008: every field is attributed) ---
            // The canonical public record this lead was read from, and when. Per-field provenance
            // (SourcedValue) arrives with Enrichment; at discovery the whole record shares one source.
            $table->string('source_url')->nullable();
            $table->timestamp('collected_at')->nullable();
            // The untouched provider payload, for traceability and re-mapping without re-fetching.
            $table->json('raw')->nullable();

            // Dedupe placeholder (full de-duplication is Phase 8): a normalised identity key —
            // company number, else domain, else slugged name. Unique per team so the same company
            // discovered twice updates in place rather than duplicating.
            $table->string('dedupe_key', 191);

            $table->timestamps();

            $table->unique(['team_id', 'dedupe_key']);
            $table->index(['team_id', 'status']);
            $table->index(['team_id', 'discovery_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
