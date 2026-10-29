<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('icp_criteria', function (Blueprint $table): void {
            $table->id();
            // team_id is denormalised onto every tenant table (CLAUDE.md tenancy rule) so the
            // BelongsToTeam global scope applies even on direct criterion queries.
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('icp_id')->constrained()->cascadeOnDelete();

            $table->string('type', 32);
            $table->string('value');
            // Relative importance 1–100 for positive signals; null for exclusions (disqualifiers).
            $table->unsignedTinyInteger('weight')->nullable();
            $table->unsignedInteger('position')->default(0);

            $table->timestamps();

            $table->index(['icp_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('icp_criteria');
    }
};
