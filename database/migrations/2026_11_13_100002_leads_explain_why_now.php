<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a lead says about itself when the list is read in the morning (PL1).
 *
 * **There is no `fit_score` here on purpose.** Fit lives on `lead_scores.score`, measured against
 * an ICP, and copying it onto the lead would make a second score that drifts from the one the
 * grades, the board and the exports are built on. `timing_score` and `confidence` are the two
 * questions `lead_scores` does not answer — *is now the moment* and *how much do we trust what we
 * know* — which is why they sit here, each a record over `lead_evidence` rather than a counter.
 *
 * All three figures are **nullable, and null is not zero**: a lead the queue has not reached is
 * unjudged, and an unjudged component is left out of both sides of an average rather than scored
 * zero (ADR 0022, and `leads.readiness` one migration earlier).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table): void {
            // The strongest decayed signal-kind evidence: how much of a reason there is to act
            // *now*, as opposed to whether the company is a good fit at all.
            $table->unsignedTinyInteger('timing_score')->nullable()->after('readiness_at');

            // How much the evidence behind this lead is worth, 0–100. A record over
            // `lead_evidence`, recomputed from it, never incremented.
            $table->unsignedTinyInteger('confidence')->nullable()->after('timing_score');
            $table->timestamp('confidence_at')->nullable()->after('confidence');

            // Computed alongside `confidence` against the team's threshold, and stored so the list
            // query can hide a low-confidence lead in one indexed pass rather than recomputing a
            // threshold per row. **False while `confidence` is null** — "not judged yet" must not
            // hide a lead, or a fresh discovery run looks like it found nothing.
            $table->boolean('suppressed_low_confidence')->default(false)->after('confidence_at');

            // The one or two sentences at the top of the lead, and the cache key that stops us
            // paying for them twice. The hash is over the evidence the summary was generated
            // from — same evidence, same sentence, no call.
            $table->text('why_now_summary')->nullable()->after('suppressed_low_confidence');
            $table->timestamp('why_now_generated_at')->nullable()->after('why_now_summary');
            $table->char('why_now_evidence_hash', 40)->nullable()->after('why_now_generated_at');

            // The list's two new orderings. Nulls sort last explicitly in the query (MySQL and
            // SQLite disagree), so the index is only here to make the hide-the-weak filter and the
            // sort cheap on a big team.
            $table->index(['team_id', 'suppressed_low_confidence'], 'leads_team_low_confidence_index');
            $table->index(['team_id', 'confidence'], 'leads_team_confidence_index');
            $table->index(['team_id', 'timing_score'], 'leads_team_timing_index');
        });

        Schema::table('teams', function (Blueprint $table): void {
            // The team's own line for "too thin to show me", 30–90. **Null means the config
            // default** (`evidence.default_min_confidence`) rather than "no threshold", so moving
            // the default moves every team that never expressed a preference — which is what a
            // default is for.
            $table->unsignedTinyInteger('min_lead_confidence')->nullable()->after('market');
        });
    }

    public function down(): void
    {
        Schema::table('teams', function (Blueprint $table): void {
            $table->dropColumn('min_lead_confidence');
        });

        Schema::table('leads', function (Blueprint $table): void {
            $table->dropIndex('leads_team_low_confidence_index');
            $table->dropIndex('leads_team_confidence_index');
            $table->dropIndex('leads_team_timing_index');
            $table->dropColumn([
                'timing_score',
                'confidence',
                'confidence_at',
                'suppressed_low_confidence',
                'why_now_summary',
                'why_now_generated_at',
                'why_now_evidence_hash',
            ]);
        });
    }
};
