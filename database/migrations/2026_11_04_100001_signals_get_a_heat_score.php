<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('signals', function (Blueprint $table): void {
            // How much this event is worth to **this** team, 0–100 (S12). Composite and
            // deterministic: `SignalScorer` has no AI in it, which is what lets the card explain
            // itself and lets a weight change be argued about with evidence.
            //
            // **Nullable, and that is load-bearing.** A signal written before this phase has no
            // score until `signals:rescore` gives it one, and a null sorts differently from a
            // zero — "we have not scored this" is not "this is worthless". The feed's heat sort
            // puts nulls last rather than pretending they are cold.
            $table->unsignedTinyInteger('heat')->nullable()->after('relevance');

            // Every component's own value and contribution, not just the total. The plan's own
            // risk note is the reason: *a scorer nobody understands is worse than date order.*
            // Stored rather than recomputed on render, so the card explains the score it was
            // ranked by even after the weights are retuned.
            $table->json('score_breakdown')->nullable()->after('heat');

            // When the full article was read for this signal (S12), or null for snippet-only —
            // which is every signal below the fetch threshold and every signal at all while
            // `signals.article_fetch.enabled` is off. A flag and a timestamp: the article **body
            // is never stored**, because retention and DSAR both get harder the moment we keep
            // prose.
            $table->timestamp('article_fetched_at')->nullable()->after('score_breakdown');
        });

        Schema::table('signals', function (Blueprint $table): void {
            // The feed's new default order. `status` before `heat` because the tab is always
            // applied and the sort is within it.
            $table->index(['team_id', 'status', 'heat'], 'signals_team_status_heat_index');
        });

        Schema::table('signal_definitions', function (Blueprint $table): void {
            // A type's own scoring weights, overriding `config('signals.scoring.weights')` for
            // the signals it classifies — nullable, which means "use the defaults" and is what
            // every type carries until somebody tunes one.
            //
            // On the definition rather than on the watch, because a weight is a statement about
            // **this kind of event**: deal size matters for an acquisition and means nothing for a
            // relocation, and a team running the same type from three watches wants one answer.
            // It is also what makes an accepted tuning suggestion an ordinary definition edit.
            $table->json('score_weights')->nullable()->after('thresholds');
        });
    }

    public function down(): void
    {
        Schema::table('signal_definitions', function (Blueprint $table): void {
            $table->dropColumn('score_weights');
        });

        Schema::table('signals', function (Blueprint $table): void {
            $table->dropIndex('signals_team_status_heat_index');
            $table->dropColumn(['heat', 'score_breakdown', 'article_fetched_at']);
        });
    }
};
