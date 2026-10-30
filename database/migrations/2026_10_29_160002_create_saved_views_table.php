<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('saved_views', function (Blueprint $table): void {
            // A named set of leads-list filters a member keeps (Phase 10) — "UK grade A", "Needs
            // enriching". Owned by the member who saved it, inside their team; `is_shared` offers
            // it to the rest of the team read-only. Team-scoped via BelongsToTeam.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            // A view is personal, so it goes when the account does.
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->string('name', 80);
            // The filter set, stored as the canonical LeadFilters array. Validated on the way in
            // and re-validated on the way out, so a stale key from an older release is ignored
            // rather than breaking the list.
            $table->json('filters');
            $table->boolean('is_shared')->default(false);

            $table->timestamps();

            // One name per member **per team** — saving again under the same name replaces the
            // filters. The team belongs in the key because a user can be in several (ADR 0006), and
            // their "Grade A in the UK" in one team is a different view from the one in another.
            $table->unique(['team_id', 'user_id', 'name']);
            $table->index(['team_id', 'is_shared']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_views');
    }
};
