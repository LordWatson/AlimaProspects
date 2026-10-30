<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lia_records', function (Blueprint $table): void {
            // A team's recorded Legitimate Interest Assessment (Phase 15, docs/compliance.md §2).
            // Team-scoped via BelongsToTeam. This is the lawful basis on which every lead the team
            // sources is allowed to exist, so discovery is gated on an **active, unexpired** one
            // (`LegitimateInterestGate`) rather than merely logging its absence.
            //
            // **One per (team, market).** A team trading in both the UK and the UAE is working
            // under two different regimes — UK GDPR legitimate interest against UAE PDPL's
            // prior-consent posture (ADR 0007) — and one assessment cannot honestly cover both.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->string('market', 8); // Market value — which regime this assessment is for

            $table->string('status', 16)->default('draft'); // LiaStatus value

            // The three tests, in the ICO's own order. Free text, because an assessment is an
            // argument and not a set of checkboxes: a form that can be completed without writing
            // anything is a form that records nothing.
            $table->text('purpose');     // Purpose test — why we are processing, and whose interest
            $table->text('necessity');   // Necessity test — why this is the way to achieve it
            $table->text('balancing');   // Balancing test — the subject's interests against ours

            // The data minimisation and objection-handling safeguards the team relies on.
            $table->text('safeguards')->nullable();

            // The Signals section (docs/compliance.md §5a): event-based prospecting from published
            // news about a named individual. Nullable because a team that runs no watches has
            // nothing to assess, and a required field they cannot answer teaches them to write
            // filler into a legal record.
            $table->text('signals_scope')->nullable();

            // Who signed it off, and when. `completed_at` null means a draft — saved, not yet
            // affirmed — which does **not** open the discovery gate.
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();

            // When it must be looked at again. An assessment is a judgement about a balance that
            // moves (the market, the sources, what the team sends), so it expires rather than
            // standing for ever. Past this date the gate closes and the screen says why.
            $table->timestamp('review_due_at')->nullable();

            // Bumped on each affirmation, so an audit entry can say which version was in force
            // when a discovery run happened.
            $table->unsignedInteger('version')->default(1);

            // Set when a team withdraws the basis — they have stopped relying on legitimate
            // interest. Kept rather than deleted: the record that a basis *was* relied on, and
            // until when, is the accountability (§3.8).
            $table->timestamp('withdrawn_at')->nullable();

            $table->timestamps();

            $table->unique(['team_id', 'market']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lia_records');
    }
};
