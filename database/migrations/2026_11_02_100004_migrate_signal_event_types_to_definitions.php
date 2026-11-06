<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Turn v1's three `SignalEventType` cases into rows, and repoint every existing watch and
     * signal at the team's own copy of them (ADR 0018).
     *
     * **The day this ships, nothing changes for an existing team.** The presets below carry the
     * same templates (`config/signals.php`), the same descriptions (the enum's `description()`),
     * the same ICP keyword map (`SignalQueryBuilder::KEYWORDS`) and null thresholds, so the global
     * `signals.min_confidence` / `min_relevance` still apply. S7 changes where a type lives, not
     * what it finds.
     *
     * Three things about how this is written:
     *
     * - **The presets are frozen inline rather than read from `SignalPresetCatalogue`.** A
     *   migration is a snapshot. S9 grows that catalogue into six vertical packs, and a migration
     *   that read it would then clone thirty definitions into every team on a fresh install — past
     *   `max_definitions_per_team` and nothing like what this migration meant. The catalogue stays
     *   the living source of truth for new installs (`SignalPresetSeeder`); this copy is dead data
     *   by design (ADR 0018, "Consequences").
     * - **Raw `DB` throughout, no models.** A migration that runs through Eloquent runs through
     *   whatever those models look like three phases from now, including a global scope and a
     *   `creating` hook that would hand a preset the team that happened to be active.
     * - **Idempotent and reversible.** It touches every team's live watches, so a half-applied run
     *   must be re-runnable rather than cleaned up by hand.
     *
     * Run it against a MySQL copy before production. The new unique index on
     * `(scope_id, key)` and the JSON columns are exactly the class of thing SQLite waves through
     * (`memory/sqlite-tests-mysql-prod.md`).
     */
    public function up(): void
    {
        $now = now();

        $presetIds = [];

        foreach ($this->presets() as $preset) {
            $existing = DB::table('signal_definitions')
                ->whereNull('team_id')
                ->where('scope_id', 0)
                ->where('key', $preset['key'])
                ->first('id');

            if ($existing !== null) {
                $presetIds[$preset['key']] = (int) $existing->id;

                continue;
            }

            // No `scope_id`: it is a stored generated column (`coalesce(team_id, 0)`), so the
            // database fills it and writing it would be writing to a generated column.
            $presetIds[$preset['key']] = (int) DB::table('signal_definitions')->insertGetId([
                ...$this->row($preset),
                'team_id' => null,
                'preset_key' => null,
                'preset_version' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Only teams that actually use Signals. A team with neither a watch nor a signal gets
        // nothing here and clones from the library when they set their first watch up — seeding a
        // library into every team that has never opened the feature would just be rows nobody asked
        // for.
        $teamIds = DB::table('signal_watches')->distinct()->pluck('team_id')
            ->merge(DB::table('signals')->distinct()->pluck('team_id'))
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        foreach ($teamIds as $teamId) {
            /** @var array<string, int> $definitionIds key => the team's own definition id */
            $definitionIds = [];

            foreach ($this->presets() as $preset) {
                $existing = DB::table('signal_definitions')
                    ->where('scope_id', $teamId)
                    ->where('key', $preset['key'])
                    ->first('id');

                if ($existing !== null) {
                    $definitionIds[$preset['key']] = (int) $existing->id;

                    continue;
                }

                $definitionIds[$preset['key']] = (int) DB::table('signal_definitions')->insertGetId([
                    ...$this->row($preset),
                    'team_id' => $teamId,
                    // Provenance: this row is a clone of a platform preset, which is how S15 can
                    // offer the diff when the preset moves on (ADR 0018 §3).
                    'preset_key' => $preset['key'],
                    'preset_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $this->repointWatches($teamId, $definitionIds, $now);
            $this->repointSignals($teamId, $definitionIds);
        }
    }

    public function down(): void
    {
        // `signal_watches.event_types` is still intact — the migration after this one is what drops
        // it, and its own `down()` has already put it back by the time this runs. So there is
        // nothing to restore here, only what this migration added to remove.
        DB::table('signals')->update(['signal_definition_id' => null]);
        DB::table('signal_watch_definition')->delete();

        // Scoped to **the rows this migration created**: the platform presets, and the clones that
        // carry their provenance. A team may since have written their own type that slugged to one
        // of these keys, and deleting by key alone would take it with them - losing data on a
        // rollback, which is the one thing a rollback must not do.
        foreach ($this->presets() as $preset) {
            DB::table('signal_definitions')
                ->where('key', $preset['key'])
                ->where(function ($query) use ($preset): void {
                    $query->whereNull('team_id')->orWhere('preset_key', $preset['key']);
                })
                ->delete();
        }
    }

    /**
     * Attach each watch to the definitions its `event_types` named.
     *
     * A stored value that is not one of the three (there should be none — the column only ever held
     * enum values) is skipped rather than guessed at: the watch simply stops watching for something
     * it could never have found anyway.
     *
     * @param  array<string, int>  $definitionIds
     */
    private function repointWatches(int $teamId, array $definitionIds, DateTimeInterface $now): void
    {
        DB::table('signal_watches')
            ->where('team_id', $teamId)
            ->orderBy('id')
            ->select(['id', 'event_types'])
            ->chunk(200, function (iterable $watches) use ($teamId, $definitionIds, $now): void {
                $rows = [];

                foreach ($watches as $watch) {
                    /** @var list<string> $keys */
                    $keys = json_decode((string) $watch->event_types, true) ?: [];

                    foreach ($keys as $key) {
                        if (! is_string($key) || ! isset($definitionIds[$key])) {
                            continue;
                        }

                        $rows[] = [
                            'team_id' => $teamId,
                            'signal_watch_id' => (int) $watch->id,
                            'signal_definition_id' => $definitionIds[$key],
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($rows !== []) {
                    // `insertOrIgnore` against the unique (watch, definition) pair, so a re-run
                    // adds nothing.
                    DB::table('signal_watch_definition')->insertOrIgnore($rows);
                }
            });
    }

    /**
     * Point each signal at the definition whose key it already carries. One statement per key, so a
     * team with a hundred thousand signals is three updates rather than a hundred thousand.
     *
     * @param  array<string, int>  $definitionIds
     */
    private function repointSignals(int $teamId, array $definitionIds): void
    {
        foreach ($definitionIds as $key => $definitionId) {
            DB::table('signals')
                ->where('team_id', $teamId)
                ->where('event_key', $key)
                ->whereNull('signal_definition_id')
                ->update(['signal_definition_id' => $definitionId]);
        }
    }

    /**
     * The columns common to a preset and to every clone of it.
     *
     * @param  array<string, mixed>  $preset
     * @return array<string, mixed>
     */
    private function row(array $preset): array
    {
        return [
            'key' => $preset['key'],
            'name' => $preset['name'],
            'description' => $preset['description'],
            'category' => $preset['category'],
            'icon' => $preset['icon'],
            'is_active' => true,
            'query_templates' => json_encode($preset['query_templates'], JSON_UNESCAPED_SLASHES),
            'match_keywords' => json_encode($preset['match_keywords'], JSON_UNESCAPED_SLASHES),
            'classification_rules' => json_encode([
                'includes' => $preset['includes'],
                'excludes' => $preset['excludes'],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            // Null on both: v1 applied one global pair of floors and this migration must not change
            // what any watch finds.
            'thresholds' => json_encode(['min_confidence' => null, 'min_relevance' => null]),
            'suggested_approach_hint' => null,
            'markets' => json_encode(['uae'], JSON_UNESCAPED_SLASHES),
            'version' => 1,
            'created_by' => null,
        ];
    }

    /**
     * v1's three event types, frozen. See the class docblock for why these are not read from
     * `SignalPresetCatalogue`.
     *
     * @return list<array<string, mixed>>
     */
    private function presets(): array
    {
        return [
            [
                'key' => 'acquisition',
                'name' => 'Acquisition',
                'description' => 'A company is sold or a founder exits — a classic liquidity event.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'query_templates' => [
                    '{location} company acquisition',
                    'UAE acquisition deal',
                    'UAE startup acquired',
                ],
                'match_keywords' => ['acqui', 'sold', 'exit', 'merger', 'm&a', 'buyout', 'takeover', 'stake sale'],
                'includes' => [
                    'a company being bought, sold or merged',
                    'a founder or shareholder selling a stake or exiting',
                    'a named buyer acquiring a named business',
                ],
                'excludes' => [
                    'market-wide M&A totals or deal-volume reports',
                    'rumours, talks, "exploring options" and anything not yet done',
                    'weekly or monthly deal round-ups covering several companies',
                ],
            ],
            [
                'key' => 'funding_round',
                'name' => 'Funding round',
                'description' => 'A business raises a funding round, putting fresh capital in founders’ hands.',
                'category' => 'growth',
                'icon' => 'trending-up',
                'query_templates' => [
                    '{location} startup raises funding',
                    'UAE Series B funding',
                    '{location} startup Series A funding round',
                ],
                'match_keywords' => ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
                'includes' => [
                    'a named company closing a named round (pre-seed through late stage)',
                    'a founder or company receiving investment from a named investor',
                ],
                'excludes' => [
                    'a fund closing its own fund (that is the investor, not the prospect)',
                    'funding trackers, listicles and "this week in funding" pages',
                    'rounds in other regions that merely mention this location',
                ],
            ],
            [
                'key' => 'relocation',
                'name' => 'Relocation',
                'description' => 'A founder, executive or family office relocates to the market.',
                'category' => 'movement',
                'icon' => 'plane',
                'query_templates' => [
                    '{location} founder relocates',
                    'family office {location} opens',
                ],
                'match_keywords' => ['relocat', 'moving', 'moves to', 'move to', 'family office', 'headquarter', 'opens office'],
                'includes' => [
                    'a named person moving themselves or their business to this location',
                    'a family office or private office opening here',
                    'a business moving its headquarters here',
                ],
                'excludes' => [
                    'general "why people are moving to X" commentary with no named subject',
                    'relocation-service and visa-advice marketing pages',
                    'a company opening a branch office with no principal moving',
                ],
            ],
        ];
    }
};
