<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('outreach_messages', function (Blueprint $table): void {
            // One drafted message for one lead (Phase 11): what the AI wrote, what the member
            // edited it to, and — for email — whether it was actually sent. LinkedIn text and call
            // scripts never leave this table; they are copied out and the contact attempt is
            // logged. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Which person at the company it is addressed to, when the team picked one. Nulled on
            // delete rather than cascaded: a sent message is a record of what was sent, and it has
            // to survive the contact being re-derived by a later enrichment pass.
            $table->foreignId('lead_contact_id')->nullable()->constrained('lead_contacts')->nullOnDelete();
            $table->foreignId('outreach_template_id')->nullable()->constrained('outreach_templates')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 16);                      // OutreachChannel value
            $table->string('tone', 16);                         // OutreachTone value
            $table->string('locale', 8)->default('en');         // SupportedLocale value
            $table->string('status', 16)->default('draft');     // OutreachMessageStatus value

            $table->string('subject', 180)->nullable();
            $table->text('body');
            // Every variant the model returned, so the member can switch between them after the
            // fact without spending another call. `body` above is the chosen one.
            $table->json('variants')->nullable();

            // Where it actually went. Kept on the row rather than read back off the contact,
            // because the contact's email may be corrected later and this is the audit of what was
            // used at the time. Personal data, so it is purged by the Compliance retention sweep
            // along with the lead.
            $table->string('sent_to', 254)->nullable();
            // Resend's id for the message, for a later deliverability look-up.
            $table->string('provider_message_id', 120)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();

            // AI provenance, mirroring lead_scores: which prompt wrote it and what it cost.
            $table->string('prompt_key', 64)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->timestamps();

            // The lead's outreach history, newest first.
            $table->index(['team_id', 'lead_id', 'created_at']);
            // Reporting (Phase 13): how much went out, on which channel, over a window.
            $table->index(['team_id', 'status', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_messages');
    }
};
