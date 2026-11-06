<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_rejections', function (Blueprint $table): void {
            // What the classifier already said no to (S11), so a run does not pay to be told again.
            //
            // This closes the S3 trade-off (b): non-event and below-threshold items were forgotten,
            // so every run re-classified the same weekly round-ups. It holds **hashes, a verdict
            // and counts** — no headline, no URL, no name — which is what makes it cheap to keep
            // and uninteresting to a subject access request.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // The article, by the same identity `signals.dedupe_hash` uses — so a redirected item
            // whose token varies is still recognised as the thing that was rejected yesterday.
            $table->char('dedupe_hash', 40);

            // `sha1` of the **sorted set of signal type keys that were offered** when the verdict
            // was reached. The plan asked for "the same definition", and this is what that means
            // once a watch offers several at once: a team that adds a type has asked a different
            // question about the same article and is owed a fresh answer, while a team that has not
            // changed anything is not asked to pay twice.
            $table->char('definition_fingerprint', 40);

            // A `SignalRejectionVerdict` value: it was not an event, or it was one and did not
            // clear the type's thresholds. Recorded because the two mean different things when
            // somebody comes to tune a definition — the first is the prompt's rules, the second is
            // the floor.
            $table->string('verdict', 24);

            // How many runs have now been spared it, and when the last one was. `seen_count` is
            // the measure of whether this table earns its keep.
            $table->unsignedInteger('seen_count')->default(1);
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            // One row per article per offered type-set per team: the natural key of the question
            // being answered, and what makes the write an update-or-insert rather than a growing
            // log.
            $table->unique(
                ['team_id', 'dedupe_hash', 'definition_fingerprint'],
                'signal_rejections_team_hash_types_unique',
            );

            // The 30-day sweep in `signals:prune`.
            $table->index(['last_seen_at'], 'signal_rejections_last_seen_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_rejections');
    }
};
