<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_tags', function (Blueprint $table): void {
            // A team's own label for leads (Phase 10b) — "warm", "trade show", "needs a call".
            // Free-form and created on first use, so the team never has to set tags up before
            // tagging. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 40);
            // The normalised form the uniqueness is judged on, so "Warm", "warm" and " warm " are
            // one tag rather than three.
            $table->string('slug', 40);

            $table->timestamps();

            $table->unique(['team_id', 'slug']);
        });

        Schema::create('lead_lead_tag', function (Blueprint $table): void {
            // Which leads carry which tags. Both sides cascade: a deleted lead or tag takes its
            // links with it. No team_id — both ends are already team-scoped, and the tag can only
            // ever be reached through a team-scoped query of one side or the other.
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('lead_tag_id')->constrained('lead_tags')->cascadeOnDelete();

            $table->primary(['lead_id', 'lead_tag_id']);
            // Filtering the leads list by tag reads from the tag side.
            $table->index('lead_tag_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_lead_tag');
        Schema::dropIfExists('lead_tags');
    }
};
