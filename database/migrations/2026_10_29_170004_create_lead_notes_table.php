<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_notes', function (Blueprint $table): void {
            // What a member wrote about a lead (Phase 10b). Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Nulled rather than cascaded: a note is the team's record of a conversation and must
            // outlive the account of whoever wrote it.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            // Newest-first for one lead; `id` breaks ties so paging is stable.
            $table->index(['team_id', 'lead_id', 'created_at', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_notes');
    }
};
