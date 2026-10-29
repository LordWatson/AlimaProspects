<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_scores', function (Blueprint $table): void {
            // A lead's hybrid ICP-fit score (Phase 9): a deterministic pass over the ICP's weighted
            // criteria blended with an AI qualitative assessment, graded A–D. One current row per
            // (lead, ICP) — a re-score updates it in place so the user's feedback on it survives.
            // Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Scores are meaningless without the definition they were measured against, so a
            // deleted ICP takes its scores with it.
            $table->foreignId('icp_id')->constrained('icps')->cascadeOnDelete();

            // The blended 0–100 fit score and its grade, plus each half for transparency.
            // ai_score is null when the AI half was unavailable (credit exhausted, bad output, or
            // skipped because the lead is excluded) — the score then rests on the rules alone.
            $table->unsignedTinyInteger('score');
            $table->string('grade', 1);
            $table->unsignedTinyInteger('deterministic_score');
            $table->unsignedTinyInteger('ai_score')->nullable();

            // Per-criterion breakdown (list of CriterionScore) + the grounded explanation.
            $table->json('breakdown')->nullable();
            $table->text('explanation')->nullable();
            $table->json('strengths')->nullable();
            $table->json('concerns')->nullable();

            // A matched ICP exclusion is a hard disqualifier: grade D, no AI spend.
            $table->boolean('excluded')->default(false);
            $table->string('excluded_by')->nullable();

            // Provenance, so a score can always be traced to the prompt + model that produced it.
            $table->string('prompt_key', 64)->nullable();
            $table->unsignedInteger('prompt_version')->nullable();
            $table->string('model', 64)->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);

            $table->timestamp('scored_at')->nullable();
            $table->timestamps();

            // One current score per lead per ICP (the re-score target).
            $table->unique(['lead_id', 'icp_id']);
            // Grade/score browsing and sorting in the leads workspace (Phase 10).
            $table->index(['team_id', 'grade']);
            $table->index(['team_id', 'score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_scores');
    }
};
