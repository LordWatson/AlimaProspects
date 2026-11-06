<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_watchlists', function (Blueprint $table): void {
            // The accounts a team wants watched by name (S10). Until now a watch asked "what
            // happened in Dubai?" — a cold question with a stranger at the end of it. A watchlist
            // asks "what happened to the forty companies I am already working?", which comes with a
            // known contact, an existing relationship and a reason to call today.
            $table->id();

            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            $table->string('name', 120);

            // `manual` | `pipeline` | `segment` (`WatchlistSourceKind`). The app branches on it —
            // a pipeline list is rebuilt nightly and a manual one is never touched by anything but
            // a member — so it is an enum rather than a row (the ADR 0018 §1 test).
            $table->string('source_kind', 16);

            $table->boolean('is_active')->default(true);

            // How a synced list decides what belongs in it: today the pipeline stages and the
            // owners to mirror, both optional (null = "all of them"). JSON rather than columns
            // because the answer differs per `source_kind` and a segment list will want something
            // else entirely — two half-filled column sets is the shape this avoids.
            $table->json('sync_settings')->nullable();

            $table->unsignedSmallInteger('entries_count')->default(0);

            $table->timestamp('synced_at')->nullable();

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();

            // One name per team, so "Pipeline" cannot exist twice and a member can tell their lists
            // apart. Named explicitly rather than left to Laravel: the generated name for this pair
            // on this table is inside MySQL's 64 characters, and naming it means never checking
            // again (the S7 pivot found that limit the hard way).
            $table->unique(['team_id', 'name'], 'signal_watchlists_team_name_unique');
            $table->index(['team_id', 'is_active'], 'signal_watchlists_team_active_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_watchlists');
    }
};
