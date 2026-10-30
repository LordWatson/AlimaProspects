<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('score_feedback', function (Blueprint $table): void {
            // A member's verdict on a lead score (Phase 9): "looks right" / "looks wrong", with an
            // optional note. The MVP only captures it; tuning weights and prompts from it is
            // post-MVP. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_score_id')->constrained('lead_scores')->cascadeOnDelete();
            // Keep the feedback when the member leaves — the signal outlives the account.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('rating', 8);
            $table->string('comment', 500)->nullable();

            // The score as it stood when the verdict was given. A re-score updates the score row in
            // place, so without this snapshot the feedback would silently come to describe numbers
            // the member never saw.
            $table->unsignedTinyInteger('scored_value');
            $table->string('scored_grade', 1);

            $table->timestamps();

            // One current verdict per member per score (re-voting replaces it).
            $table->unique(['lead_score_id', 'user_id']);
            $table->index(['team_id', 'rating']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('score_feedback');
    }
};
