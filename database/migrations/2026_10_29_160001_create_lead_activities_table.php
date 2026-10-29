<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_activities', function (Blueprint $table): void {
            // The lead's timeline (Phase 10): one row per thing that happened to it, wherever in the
            // platform it happened — discovered, enriched, graded, and later moved/noted/assigned.
            // Team-scoped via BelongsToTeam. Append-only: an activity is a record of the past, so
            // nothing updates or deletes a row except the lead going away.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Who did it — null for anything the platform did on its own (a queued enrichment, an
            // automatic score). Nulled rather than cascaded so the history outlives the account.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('type', 32); // LeadActivityType value

            // What the entry is about (a DiscoveryRun, a LeadScore, a note…), when there is one.
            // Polymorphic because the timeline aggregates across modules and must not depend on any
            // of them; nullable because plenty of entries are about the lead itself.
            $table->nullableMorphs('subject');

            // Structured detail the UI renders a sentence from (grade, old/new stage, counts…).
            // Deliberately NOT a stored English sentence: the workspace is EN/AR (ADR 0007), so the
            // wording is translated at render time from the type plus these values.
            $table->json('properties')->nullable();

            // When it happened, which is not always when the row was written (a queued job records
            // work that finished earlier). The timeline orders by this.
            $table->timestamp('occurred_at');
            $table->timestamps();

            // The timeline query: one lead, newest first. `id` breaks ties between entries that
            // share a timestamp so paging is stable.
            $table->index(['team_id', 'lead_id', 'occurred_at', 'id']);
            $table->index(['team_id', 'type']);

            // One entry per (lead, type, subject) — the database's own guarantee behind
            // RecordLeadActivityAction's upsert, so two concurrent retries of the same queued
            // listener can't both insert. Rows with a **null** subject are exempt (SQL treats NULLs
            // as distinct in a unique index), which is exactly the append-always semantics the
            // Action documents for subject-less entries.
            $table->unique(['lead_id', 'type', 'subject_type', 'subject_id'], 'lead_activities_subject_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_activities');
    }
};
