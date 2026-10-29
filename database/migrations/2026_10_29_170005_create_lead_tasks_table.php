<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_tasks', function (Blueprint $table): void {
            // Something a member has to do about a lead, with an optional due date and reminder
            // (Phase 10b). Notifications (Phase 12) will sweep `remind_at` and tell the assignee.
            // Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Both nulled rather than cascaded: an open task must not vanish because the person who
            // created it — or the person it was given to — left the team.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->string('title', 160);
            $table->string('status', 16)->default('open'); // LeadTaskStatus value

            // Stored UTC and displayed in the team's timezone (CLAUDE.md §2).
            $table->timestamp('due_at')->nullable();
            // When to nudge the assignee. Separate from `due_at` because "remind me the day before"
            // is the common case; null means no reminder.
            $table->timestamp('remind_at')->nullable();
            // Set when the reminder actually went out, so a sweeper can't send it twice.
            $table->timestamp('reminded_at')->nullable();

            $table->timestamp('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // "My open tasks", and the lead's own task list.
            $table->index(['team_id', 'assigned_to', 'status']);
            $table->index(['team_id', 'lead_id', 'status']);
            // The Phase 12 reminder sweep: open tasks whose reminder is due and hasn't been sent.
            $table->index(['status', 'remind_at', 'reminded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_tasks');
    }
};
