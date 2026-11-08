<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The dated, sourced reasons a lead is worth contacting now (PL1).
 *
 * A lead already carries *where it came from* on its own row (`source`, `source_url`,
 * `collected_at`, `field_sources`). What it could not carry is a **history**: several reasons, from
 * several places, each with the date the thing happened and the date we found out. That is what a
 * row here is — one fact, one source, one pair of dates — and `leads.confidence` is a record over
 * the set of them, never a figure the app increments.
 *
 * Two columns are easy to confuse and must not be: `observed_at` is when the event **happened**
 * and `captured_at` is when **we read it**. Decay is measured from the first, because a funding
 * round announced in March is March's news however long it took us to notice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_evidence', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_id')->constrained('leads')->cascadeOnDelete();

            // `EvidenceKind` — what sort of fact this is. Branched on (it picks the half-life, and
            // only a signal-kind item contributes to `timing_score`), so it is an enum. The
            // `source` beside it is **not**: nothing branches on which provider said it, the
            // weight is a config lookup, and ADR 0018 §1's test cuts that way.
            $table->string('kind', 24);

            // The stable storage key of whatever produced it — a `LeadSource` value, a
            // `SignalSourceKind` value, or a key a future provider brings. Unknown keys are legal
            // and score `evidence.reliability.default`.
            $table->string('source', 32);
            // What a human should see it attributed to ("Companies House", "Reuters"). Stored
            // rather than derived, because an outlet's name is the article's, not the index's.
            $table->string('source_label')->nullable();

            // The signal this came from, when it came from one. Nulled rather than cascaded: the
            // retention sweep takes signals long before a lead, and losing the article must not
            // lose the team's record that the event happened.
            $table->foreignId('signal_id')->nullable()->constrained('signals')->nullOnDelete();

            // Short and human: "New director appointed". One or two sentences underneath it.
            $table->string('title');
            $table->text('summary')->nullable();

            // Widened to 2048 like every other source URL in this schema — a news redirector's
            // URL is routinely longer than 255 characters (see the `widen_source_url_columns`
            // migration, which learned this the hard way).
            $table->string('source_url', 2048)->nullable();

            $table->timestamp('observed_at')->nullable();   // when the event happened
            $table->timestamp('captured_at');               // when we found it

            // The source's weight at the moment of capture, 0–100. Stored, never read back
            // through config: an explanation is of the decision that was taken, not of the one
            // today's weights would take (ADR 0022).
            $table->unsignedTinyInteger('reliability');

            // The provider's own payload, for audit and for re-mapping without re-fetching. Never
            // rendered: it is raw, and on a signal-derived row it can name a person.
            $table->json('payload')->nullable();

            // Set when any member flags this item `wrong`. A flagged item contributes **nothing**
            // to confidence from that moment, which is what makes "flagging lowers confidence
            // immediately" true rather than aspirational. A denormalised flag rather than a join,
            // because the confidence calculation runs on every evidence write.
            $table->boolean('is_disputed')->default(false);

            $table->timestamps();

            // The dedupe identity: the same fact arriving twice from the same place updates in
            // place rather than counting twice. `sha1` of the source URL, else of a payload
            // digest, computed by `RecordLeadEvidenceAction` — a plain column rather than a
            // generated one, because SQLite and MySQL do not agree about `sha1()`.
            $table->char('dedupe_hash', 40);
            $table->unique(['lead_id', 'source', 'dedupe_hash'], 'lead_evidence_identity_unique');

            $table->index(['team_id', 'lead_id']);
            // The confidence read and the timeline render, in one indexed pass.
            $table->index(['lead_id', 'observed_at']);
            // The nightly source-quality aggregate.
            $table->index(['source', 'captured_at']);
        });

        Schema::create('lead_evidence_feedback', function (Blueprint $table): void {
            // One member's verdict on one item of evidence. The shape deliberately follows
            // `score_feedback`: a team learns the same way about a reason as it does about a score,
            // and a second vocabulary for "this is wrong" would mean two reports to read.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lead_evidence_id')->constrained('lead_evidence')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->string('verdict', 16);          // EvidenceVerdict value
            $table->string('note', 500)->nullable();

            $table->timestamps();

            // One verdict per member per item — changing your mind replaces it rather than voting
            // twice. `user_id` is nullable (a member can leave), and MySQL treats nulls in a unique
            // index as distinct, so a departed member's verdicts stay put without colliding.
            $table->unique(['lead_evidence_id', 'user_id']);
            $table->index(['team_id', 'verdict']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_evidence_feedback');
        Schema::dropIfExists('lead_evidence');
    }
};
