<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pipeline_stages', function (Blueprint $table): void {
            // A column on the team's kanban board (Phase 10b). Team-customisable: every team gets a
            // sensible default set the first time it opens the board, and can rename, reorder, add
            // to or remove them from there. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 60);
            // A stable key for the stage, so a renamed stage keeps its identity in a saved view, an
            // export or a report. Unique per team.
            $table->string('slug', 60);

            // Left-to-right order on the board (mirrored under RTL). Not unique: reordering writes
            // the whole set, and a unique index would make an intermediate state impossible.
            $table->unsignedSmallInteger('position');

            // Where a lead lands when nothing has been decided about it yet. Exactly one per team,
            // enforced in the Action rather than the schema (a partial unique index isn't portable
            // between SQLite and MySQL).
            $table->boolean('is_default')->default(false);

            // Whether reaching this stage ends the lead's journey, and how. Null for the stages in
            // between. Reporting (Phase 13) reads this to work out conversion without having to
            // guess from stage names.
            $table->string('outcome', 8)->nullable(); // StageOutcome value

            $table->timestamps();

            $table->unique(['team_id', 'slug']);
            $table->index(['team_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pipeline_stages');
    }
};
