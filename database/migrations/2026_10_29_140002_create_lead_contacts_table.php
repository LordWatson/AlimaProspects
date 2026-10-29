<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_contacts', function (Blueprint $table): void {
            // A contact point derived for a lead during Enrichment (Phase 8) — in the MVP, from AI
            // extraction of the lead's public web text (no paid provider, ADR 0008). Team-scoped via
            // BelongsToTeam. The platform prefers business/role contacts; personal-looking ones are
            // flagged for compliance (docs/compliance.md) rather than silently used.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            $table->string('name')->nullable();   // person or team name, when stated
            $table->string('title')->nullable();  // job title / role, when stated
            $table->string('email')->nullable();
            $table->string('phone', 64)->nullable();

            // Free-tier email check result (syntax + disposable + MX/DNS); confidence is a soft 0–100
            // signal, never a deliverability guarantee.
            $table->string('email_verification_status', 24)->nullable();
            $table->unsignedTinyInteger('email_confidence')->nullable();

            // Compliance classification: a role/business address (info@, sales@) vs a personal one.
            $table->boolean('is_role_based')->default(false);
            $table->boolean('is_personal')->default(false);

            // Source attribution (ADR 0008: every field is attributed).
            $table->string('source', 32);              // LeadSource value or 'ai_extract'
            $table->string('source_url')->nullable();
            $table->string('method', 32)->nullable();  // how it was obtained, e.g. 'ai_extract'
            $table->timestamp('collected_at')->nullable();

            $table->timestamps();

            // At most one contact per (lead, email). A null email is allowed (phone-only contact);
            // MySQL and SQLite both treat NULLs as distinct, so phone-only rows never collide.
            $table->unique(['lead_id', 'email']);
            $table->index(['team_id', 'lead_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_contacts');
    }
};
