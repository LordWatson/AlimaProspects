<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contact_attempts', function (Blueprint $table): void {
            // One recorded approach to a lead (Phase 11), whoever made it and however it went out:
            // an email Alima sent, a LinkedIn message the member copied and pasted, a call they
            // made. The log is the point — "have we spoken to this company, and what happened" is
            // the question the workspace exists to answer. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('lead_contact_id')->nullable()->constrained('lead_contacts')->nullOnDelete();
            // The draft it came from, when there was one. A call logged straight off the lead
            // screen has none, which is why this is nullable rather than the other way around.
            $table->foreignId('outreach_message_id')->nullable()->constrained('outreach_messages')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('channel', 16);                      // OutreachChannel value
            $table->string('outcome', 24)->default('pending');  // ContactOutcome value

            // When the approach happened, which is not when the row was written: a member logs
            // yesterday's call today.
            $table->timestamp('occurred_at');
            // The member's own note. Free text, so it may contain personal data — never logged,
            // and purged with the lead.
            $table->text('notes')->nullable();

            $table->timestamps();

            // The lead's contact history, newest first.
            $table->index(['team_id', 'lead_id', 'occurred_at']);
            // Reporting (Phase 13): outcomes by channel over a window.
            $table->index(['team_id', 'channel', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contact_attempts');
    }
};
