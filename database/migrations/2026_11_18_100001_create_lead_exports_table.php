<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One request to take leads out as a file (PL6).
 *
 * The export has been a streamed response since Phase 10b, and for a few hundred rows that is
 * still the right shape. This table exists for the part that cannot be streamed: a file big
 * enough to be built by a worker has to be **somewhere** while the member waits, and something
 * has to know when to delete it again. So the row is three things at once — the instruction (the
 * filters and the preset), the artefact (`path`, `expires_at`) and the receipt (`row_count`,
 * `suppressed_count`).
 *
 * `filters` is the canonical `LeadFilters::toParams()` shape, which is the same JSON a saved view
 * stores: the queued job rebuilds the filters from it rather than being handed a serialised object,
 * so an export queued before a deploy still runs after one.
 *
 * `reservation` is PL4's allowance handle. The rows are reserved **when the member asks**, not when
 * the worker starts, because a refusal a member is told about immediately is worth far more than
 * one that arrives as a failed notification ten minutes later — and two large exports queued at
 * once must not both be told there is room.
 *
 * `path` is on the private disk and is **never** a route parameter: the download is addressed by
 * this row's id, scoped to the team, so one team cannot read another's file by guessing a filename.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_exports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->cascadeOnDelete();

            // The member who asked. `nullOnDelete` because the file outlives their account for as
            // long as the retention window, and the team is who it belongs to either way.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('preset', 16);                 // ExportPreset value
            $table->json('columns')->nullable();          // only a `custom` preset has one
            $table->json('filters');
            $table->string('state', 12);                  // ExportState value

            // The receipt. `suppressed_count` is the number of leads left out because somebody on
            // the do-not-contact list is at that company — shown to the member, because an export
            // that silently drops rows is one they will reconcile by hand.
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('suppressed_count')->default(0);

            $table->string('path')->nullable();
            $table->string('reservation', 36)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // The team's own history, newest first.
            $table->index(['team_id', 'created_at']);

            // The prune sweep's own query.
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_exports');
    }
};
