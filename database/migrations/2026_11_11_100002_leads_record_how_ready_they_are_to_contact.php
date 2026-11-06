<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead quality Q4: a lead has two numbers, and they answer two questions.
 *
 * `lead_scores.score` answers **should we want them** and is deliberately blind to missing data — a
 * thin lead must not be marked down for what we do not know yet (`scoring-matching.md`, rule 2).
 * The cost of that correctness is that an **A-grade lead can have no website, no contact and no
 * routable email**, and nothing on the list distinguishes it from one you could phone this
 * afternoon.
 *
 * `readiness` answers **can we actually reach them**: 0–100, deterministic, derived from fields we
 * already hold, with no AI and no network. It is a **record over the lead's own columns**, not a
 * counter — recomputed whenever the lead changes, never incremented (ADR 0012's rule).
 *
 * Nullable, because "not yet computed" and "nothing to reach them by" are different states and a
 * zero would read as the second. `readiness_at` says when the figure was taken, so a stale one is
 * recognisable rather than merely wrong. Indexed with `team_id` for the list's ordering.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->unsignedTinyInteger('readiness')->nullable()->after('enriched_at');
            $table->timestamp('readiness_at')->nullable()->after('readiness');

            $table->index(['team_id', 'readiness'], 'leads_team_readiness_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_team_readiness_index');
            $table->dropColumn(['readiness', 'readiness_at']);
        });
    }
};
