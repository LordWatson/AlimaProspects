<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_assignments', function (Blueprint $table): void {
            // Who is working a lead (Phase 10b). A table rather than a column on `leads`, because a
            // lead can be shared — a researcher and a closer on the same company is normal — and
            // because it records who handed it over and when. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Cascade: the assignment *is* the relationship, so it goes with the account. Who the
            // lead used to belong to stays on the timeline.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Nulled: the handover record outlives whoever made it.
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamp('assigned_at');
            $table->timestamps();

            // One assignment per member per lead — assigning again is a no-op, not a second row.
            $table->unique(['lead_id', 'user_id']);
            // "Everything assigned to me", and the leads-list assignee filter.
            $table->index(['team_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_assignments');
    }
};
