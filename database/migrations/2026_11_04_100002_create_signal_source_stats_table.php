<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_source_stats', function (Blueprint $table): void {
            // What this team's own behaviour says about an outlet, per signal type (S12).
            //
            // This is the v1 backlog's "learning from dismiss reasons", made concrete and bounded:
            // a tally of what the team did with what each source brought them. It feeds two
            // things and nothing else — the scorer's **source authority** component, and the
            // evidence behind a tuning suggestion. Nothing here retunes anything on its own.
            //
            // **Per `(source, definition)` rather than per source**, because an outlet is not good
            // or bad in general: Gulf Business is excellent on funding rounds and says nothing
            // useful about planning permission, and a single authority number per outlet would
            // average those two into a figure that is wrong about both.
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();

            // The outlet as `SignalItemNormaliser::sourceKey()` renders it — a slug, so "Gulf
            // News" and "gulf news" are one outlet. The display name is kept beside it because
            // the suggestion panel has to name the source in the team's own reading of it rather
            // than as a slug.
            $table->string('source_key', 120);
            $table->string('source_name', 255)->nullable();

            // Nullable and `nullOnDelete`, like every other definition reference (ADR 0018 §4): a
            // team deleting a signal type must not destroy the record of how it performed, which
            // is exactly what S15's retirement suggestion will want to read. `event_key` is the
            // copy that survives it.
            $table->foreignId('signal_definition_id')
                ->nullable()
                ->constrained('signal_definitions')
                ->nullOnDelete();
            $table->string('event_key', 32);

            // The four outcomes, counted. `kept_count` is "this source produced a signal that
            // cleared the thresholds"; the other three are what the team then did with it.
            // `not_an_event_count` is a subset of `dismissed_count` and is kept separately
            // because it is the only one that says the **classifier** was wrong rather than that
            // the team was not interested — `SignalDismissReason::indicatesMisclassification()`
            // is what decides, and the advisor reads this column rather than the raw dismissals.
            $table->unsignedInteger('kept_count')->default(0);
            $table->unsignedInteger('dismissed_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('not_an_event_count')->default(0);

            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // The natural key of the question being asked, which is what makes every write an
            // update-or-insert rather than a growing log.
            $table->unique(
                ['team_id', 'source_key', 'event_key'],
                'signal_source_stats_team_source_event_unique',
            );

            // The advisor's read: this team's worst-behaving sources first.
            $table->index(['team_id', 'dismissed_count'], 'signal_source_stats_team_dismissed_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_source_stats');
    }
};
