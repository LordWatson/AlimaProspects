<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The record of what we asked a vendor, and what it cost (PL3).
 *
 * Two tables, because they answer two questions a single one would blur. A **run** is what a member
 * asked for — "find decision-makers at this company" — and there is one per request. An **attempt**
 * is one call to one vendor, and a run makes several: three providers tried for an email, each
 * recorded, two of them misses.
 *
 * `enrichment_attempts` is the money table, and it is deliberately append-only. Every paid call
 * lands here with its cost whether it found anything or not, because the questions that matter
 * commercially are all about the misses: which vendor is being paid to say "no", what a hit
 * actually costs once the failures are counted, and whether a team's reveals are worth their plan.
 * PL4's margin guard reads it; the admin provider panel reads it; nothing updates it.
 *
 * Three things about the shape:
 *
 * - **`contact_id` is nullable and `lead_id` is not.** A people-find has no contact yet — finding
 *   one is the point — so the lead is the only thing every attempt has. Both are `nullOnDelete`
 *   for the contact and `cascadeOnDelete` for the lead: a deleted contact must not take the record
 *   of what it cost with it, but a deleted lead takes its whole history.
 * - **Cost is `cost_micros`, one column.** Not the plan's millicredits-and-pence pair: every
 *   monetary column in this schema is micros (ADR 0026 §3), and two units in one ledger is a
 *   billing bug waiting to happen.
 * - **`outcome` distinguishes three kinds of nothing.** A miss, an error, and a skip — and a skip
 *   says *why* it was skipped. A provider we never called because its breaker was open has cost
 *   nothing and tells us nothing about the vendor's hit rate, and folding it in with a miss would
 *   make an outage look like a vendor that is bad at its job.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrichment_runs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            // Null for a run the system started (a discovery hand-off, a scheduled refresh) rather
            // than a member. Nulled rather than cascaded when somebody leaves: the spend happened.
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            $table->string('scope', 16);   // EnrichmentScope value
            $table->string('state', 16);   // EnrichmentRunState value

            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            // What happened, in figures a screen can read without re-aggregating the attempts:
            // providers tried, contacts created, values found, total cost. A **record** over the
            // attempts rather than a second source of truth — if the two disagree, the attempts
            // are right (ADR 0012's rule for a derived figure).
            $table->json('summary')->nullable();
            // Why a run refused or failed, by code rather than by message: the admin panel reads a
            // failed run as the provider being unwell, and a team at its plan ceiling would
            // otherwise raise the platform's failure rate every time somebody clicked Reveal.
            // Exactly the lesson `signal_runs.error_code` records (ADR 0023/S16).
            $table->string('error_code', 64)->nullable();

            $table->timestamps();

            $table->index(['team_id', 'lead_id']);
            $table->index(['team_id', 'state']);
            // The idempotency read: "is there already a run of this scope in flight for this lead".
            $table->index(['lead_id', 'scope', 'state']);
        });

        Schema::create('enrichment_attempts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enrichment_run_id')->nullable()->constrained('enrichment_runs')->nullOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained('lead_contacts')->nullOnDelete();

            $table->string('capability', 16);  // ProviderCapability value
            $table->string('provider', 32);    // the provider's own key
            $table->string('outcome', 24);     // AttemptOutcome value

            // What the vendor said, in its own words, for the one failure mode that matters: our
            // mapping being wrong. Short — a status, not a payload.
            $table->string('status_returned', 64)->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->unsignedBigInteger('cost_micros')->default(0);
            // Our code, not the vendor's: `enrichment.provider_unavailable` and the rest.
            $table->string('error_code', 64)->nullable();

            // Append-only, so there is no `updated_at` to mean anything.
            $table->timestamp('created_at')->nullable();

            // The provider panel's queries: hit rate, latency and cost per provider over a window.
            $table->index(['provider', 'created_at']);
            $table->index(['team_id', 'created_at']);
            // "What did this run cost", and "what has this contact cost us so far".
            $table->index(['enrichment_run_id']);
            $table->index(['contact_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('enrichment_attempts');
        Schema::dropIfExists('enrichment_runs');
    }
};
