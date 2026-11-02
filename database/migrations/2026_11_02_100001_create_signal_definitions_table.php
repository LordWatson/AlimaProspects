<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('signal_definitions', function (Blueprint $table): void {
            // What a signal type *is* (ADR 0018, S7). Replaces the three-case `SignalEventType`
            // enum: the templates were already data, and so is everything else a type carries —
            // its name, its explainer, its ICP keyword map, its thresholds and the rules the AI
            // classifies by. Nothing in the app branches on *which* type a signal is, which is the
            // test ADR 0018 §1 applies.
            $table->id();

            // Nullable **on purpose**: a row with no team is a platform preset, readable by every
            // team and writable only by the platform. `Signals\Services\PlatformPresetReader` is
            // the only place in the module that removes the team scope to read them (ADR 0018 §2);
            // an `orWhereNull` inside the global scope is explicitly not acceptable.
            $table->foreignId('team_id')->nullable()->constrained()->cascadeOnDelete();

            // Portable uniqueness (ADR 0018 §6). `unique(team_id, key)` would not stop two presets
            // sharing a key, because MySQL treats NULLs in a unique index as distinct — and the
            // SQLite test suite would never have said so.
            //
            // **A generated column, not one the application maintains.** It was a model `creating`
            // hook first, and that was wrong twice over: `Event::fake()` suppresses Eloquent events,
            // so the test suite silently wrote every row into scope 0; and a bulk `insert()` fires
            // no model events at all. A column carrying a uniqueness invariant cannot depend on
            // anything a caller might not run. Here the database computes it, on both engines, and
            // nothing can get it wrong. It is therefore **read-only to the application** — never in
            // an insert, never fillable.
            //
            // **VIRTUAL, not STORED, and that is a MySQL requirement rather than a preference.**
            // MySQL refuses `ON DELETE CASCADE` on the base column of a *stored* generated column
            // (error 1215, "Cannot add foreign key constraint"), and `team_id` has to cascade like
            // every other tenant table's does. A virtual column has no such restriction, can still
            // carry a unique index on both MySQL and SQLite, and costs a trivial recomputation on
            // read. SQLite accepted the stored version happily — this is precisely the class of
            // difference `memory/sqlite-tests-mysql-prod.md` exists for, and it was found by running
            // this migration against the local MySQL rather than by reading about it.
            $table->unsignedBigInteger('scope_id')->virtualAs('coalesce(team_id, 0)');

            // The stable storage key, copied onto every signal as `event_key` so the signal
            // outlives the definition. Slugged from the name on create and never changed after.
            //
            // **32, to match `signals.event_key` exactly.** That column is v1's `event_type`
            // renamed, and renaming is all the next migration does to it: widening a column means a
            // table rebuild on SQLite and a width SQLite ignores anyway, so the two would only ever
            // have agreed on one engine. A key is a slug, not a label — the 120-character `name` is
            // what anybody reads.
            $table->string('key', 32);

            $table->string('name', 120);
            // The one-line explainer that was SignalEventType::description().
            $table->string('description', 255);

            $table->string('category', 32);   // SignalCategory value — how the library groups it
            $table->string('icon', 32);       // SignalIcon value — an allowlist, not a free string

            $table->boolean('is_active')->default(true);

            // list<string>: plain-keyword Serper templates with `{location}` substituted at
            // query-build time. Plain keywords rather than exact phrases per the S0 spike, which
            // found 7 of 8 quoted templates returned zero results on /news.
            $table->json('query_templates');

            // list<string>: lower-case fragments that map an ICP buying-signal criterion onto this
            // type (substring match, first definition wins — see SignalQueryBuilder).
            $table->json('match_keywords')->nullable();

            // {includes: list<string>, excludes: list<string>} — prose handed to the AI so it can
            // tell this type's events from the round-ups and trackers that look like them. The
            // `excludes` half is the one that does the work.
            $table->json('classification_rules')->nullable();

            // {min_confidence: int|null, min_relevance: int|null}. Null falls back to
            // signals.min_confidence / signals.min_relevance, so v1's behaviour is unchanged.
            $table->json('thresholds')->nullable();

            // A nudge for the suggested next step. It cannot widen the rule: the approach line is
            // still forced to LinkedIn-or-referral in ExtractedSignal::approach() (ADR 0011).
            $table->string('suggested_approach_hint', 255)->nullable();

            // list<Market>, or null for "any market". A preset whose templates only make sense in
            // one market says so, and the library filters on it.
            $table->json('markets')->nullable();

            // Provenance of a clone (ADR 0018 §3): a team *copies* a preset rather than pointing at
            // it, so a platform edit never silently changes what a team is paying to run. These two
            // are how S15 can offer the diff when a preset moves on.
            $table->string('preset_key', 32)->nullable();
            $table->unsignedInteger('preset_version')->nullable();

            // Bumped on every save, so a signal can record which version of a definition classified
            // it once S12 wants to explain itself.
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            // Soft-deleted rather than removed: a definition is referenced by every signal it
            // produced, and by the audit trail of the watches that used it.
            $table->softDeletes();

            // One key per scope — one `acquisition` per team, one `acquisition` preset.
            $table->unique(['scope_id', 'key']);
            // The library and the watch editor both list a team's active definitions.
            $table->index(['team_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('signal_definitions');
    }
};
