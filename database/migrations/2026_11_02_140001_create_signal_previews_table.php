<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_previews', function (Blueprint $table): void {
            // The ledger behind "show me what this finds" (S9). A preview searches Serper and runs
            // an AI pass, so it spends real money on a button press — and **a cost with no row is
            // a cost nobody can cap**. This table is what makes the per-team daily preview budget
            // enforceable at all, and what lets the spend appear in cost reporting beside runs
            // rather than hiding inside the AI usage log with no subject.
            //
            // It stores **no results**. A preview deliberately persists nothing into `signals`:
            // what it found is shown once and thrown away, because a definition that has not been
            // saved has no business putting rows in a team's feed. This is an accounting record,
            // not a cache.
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // Who pressed it. Nullable because a member can leave and their spend still happened —
            // the same reasoning as every other actor column in the app.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // The draft this previewed, as a hash rather than a copy. Two reasons: a draft is the
            // member's own words and this table does not need to keep them, and a hash is enough
            // to answer the only question anybody asks of it — "did they preview the same thing
            // four times in a row", which is a product question about the builder, not a legal one
            // about the text.
            $table->char('draft_hash', 40);

            // What it asked for and what it cost. `queries_count` is after the preview's own
            // ceiling, so this is money actually spent; `cost_micros` carries Serper and the AI
            // together, because the budget a team is held to is the total.
            $table->unsignedSmallInteger('queries_count');
            $table->unsignedSmallInteger('results_count');
            $table->unsignedSmallInteger('kept_count');
            $table->unsignedBigInteger('cost_micros');

            $table->timestamps();

            // The budget query: this team, today. Named explicitly rather than left to Laravel,
            // for the reason S7's pivot found the hard way — the generated name for a two-column
            // index on this table name is comfortably inside MySQL's 64 characters, but naming it
            // means never having to check again.
            $table->index(['team_id', 'created_at'], 'signal_previews_team_created_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_previews');
    }
};
