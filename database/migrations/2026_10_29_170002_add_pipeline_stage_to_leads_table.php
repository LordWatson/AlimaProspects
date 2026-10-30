<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // Which column of the team's board the lead sits in (Phase 10b).
            //
            // **Nullable, and null means "wherever the default stage is".** Lead Sourcing creates
            // leads without knowing the board exists, and a team that has never opened the board
            // has no stages at all — so a lead's position is only ever recorded once someone has
            // actually decided something about it. The board query folds null into the default
            // column, which means no backfill now and none when a team adds stages later.
            //
            // `nullOnDelete`: deleting a stage must not delete the leads that were in it. The
            // delete Action moves them to another stage first; the FK is the backstop.
            $table->foreignId('pipeline_stage_id')
                ->nullable()
                ->after('status')
                ->constrained('pipeline_stages')
                ->nullOnDelete();

            $table->index(['team_id', 'pipeline_stage_id']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex(['team_id', 'pipeline_stage_id']);
            $table->dropConstrainedForeignId('pipeline_stage_id');
        });
    }
};
