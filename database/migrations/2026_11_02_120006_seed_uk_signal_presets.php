<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Put the UK preset pack in the library (S8, ADR 0019).
     *
     * **Why a migration and not just the seeder.** S7 put the three UAE presets in a migration so
     * that every database has a library the moment it is migrated, and left `SignalPresetSeeder`
     * as the living source for new installs. S8 adds a second market, and the gap that opens if the
     * pack only arrives via the seeder is not theoretical: `teams.market` defaults to `uk`, so a
     * UK team on a box where the seeder has not run has an **empty library** and a one-click first
     * watch that fails with nothing to clone. That was found by this phase's own tests.
     *
     * Frozen inline, for the same reason S7's was (ADR 0018, "Consequences"): a migration is a
     * snapshot, and one that read `SignalPresetCatalogue` would clone whatever the catalogue grows
     * into — S9 takes it to six vertical packs. `SignalPresetSeeder` stays the living source and
     * re-running it is how a corrected template reaches an existing install.
     *
     * **Platform presets only.** Unlike S7's migration this clones nothing into any team: a team
     * takes a preset from the library when they want it, and retro-fitting six UK types onto every
     * existing team — all of whom are UAE — would be rows nobody asked for.
     *
     * The templates are the ones the S8 spike produced against live Serper `/news`; two of the
     * originals returned zero results and were replaced, which is recorded in
     * `docs/modules/signals.md`.
     */
    public function up(): void
    {
        $now = now();

        foreach ($this->presets() as $preset) {
            $exists = DB::table('signal_definitions')
                ->whereNull('team_id')
                ->where('scope_id', 0)
                ->where('key', $preset['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('signal_definitions')->insert([
                'team_id' => null,
                'key' => $preset['key'],
                'name' => $preset['name'],
                'description' => $preset['description'],
                'category' => $preset['category'],
                'icon' => $preset['icon'],
                'is_active' => true,
                'query_templates' => json_encode($preset['query_templates'], JSON_UNESCAPED_SLASHES),
                'query_templates_i18n' => null,
                'match_keywords' => json_encode($preset['match_keywords'], JSON_UNESCAPED_SLASHES),
                'classification_rules' => json_encode([
                    'includes' => $preset['includes'],
                    'excludes' => $preset['excludes'],
                ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'thresholds' => json_encode(['min_confidence' => null, 'min_relevance' => null]),
                'suggested_approach_hint' => null,
                'markets' => json_encode(['uk'], JSON_UNESCAPED_SLASHES),
                'preset_key' => null,
                'preset_version' => null,
                'version' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // **Platform rows only.** This migration inserted `team_id IS NULL` presets and nothing
        // else, so that is all a rollback may remove. An earlier version also deleted rows whose
        // `preset_key` matched, which reads like tidying up and is in fact deleting a team's own
        // tuned clone — the one thing a rollback must not do, and one that would take the signals
        // pointing at it with it. A clone outliving the preset it came from is fine: `preset_key`
        // is provenance, not a reference.
        DB::table('signal_definitions')
            ->whereNull('team_id')
            ->whereIn('key', array_map(static fn (array $preset): string => (string) $preset['key'], $this->presets()))
            ->delete();
    }

    /**
     * The UK pack, frozen. See the class docblock for why these are not read from the catalogue.
     *
     * @return list<array<string, mixed>>
     */
    private function presets(): array
    {
        return [
            [
                'key' => 'uk_acquisition',
                'name' => 'Acquisition',
                'description' => 'A company is sold or a founder exits — a classic liquidity event.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'query_templates' => [
                    '{location} company acquired',
                    'UK business sold private equity',
                    '{location} founder sells stake',
                ],
                'match_keywords' => ['acqui', 'sold', 'exit', 'merger', 'm&a', 'buyout', 'takeover', 'stake sale'],
                'includes' => [
                    'a named company being bought, sold or merged',
                    'a founder or shareholder selling a stake or exiting',
                ],
                'excludes' => [
                    'market-wide M&A totals, league tables and deal-volume reports',
                    'rumours, talks, "exploring options" and anything not yet done',
                    'weekly or monthly deal round-ups covering several companies',
                ],
            ],
            [
                'key' => 'uk_funding_round',
                'name' => 'Funding round',
                'description' => 'A business raises a funding round, putting fresh capital in founders’ hands.',
                'category' => 'growth',
                'icon' => 'trending-up',
                'query_templates' => [
                    '{location} startup raises funding',
                    'UK startup Series A funding round',
                    '{location} company secures investment',
                ],
                'match_keywords' => ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
                'includes' => [
                    'a named company closing a named round (pre-seed through late stage)',
                    'a founder or company receiving investment from a named investor',
                ],
                'excludes' => [
                    'a fund closing its own fund (that is the investor, not the prospect)',
                    'funding trackers, listicles and "this week in funding" pages',
                    'rounds in other regions that merely mention the UK',
                ],
            ],
            [
                'key' => 'uk_psc_change',
                'name' => 'Ownership change',
                'description' => 'Who controls a UK company changed — the registry’s version of “the owner just sold”.',
                'category' => 'regulatory',
                'icon' => 'file-text',
                'query_templates' => [
                    '{location} company new majority shareholder',
                    '{location} business changes hands owner',
                ],
                'match_keywords' => ['psc', 'significant control', 'shareholding', 'ownership change', 'majority stake'],
                'includes' => [
                    'a named company whose controlling shareholder or parent has changed',
                    'a filing recording a change in people with significant control',
                ],
                'excludes' => [
                    'routine officer appointments with no change of control',
                    'summaries of filing volumes or registry statistics',
                ],
            ],
            [
                'key' => 'uk_insolvency',
                'name' => 'Administration or insolvency',
                'description' => 'A business is in trouble — which is its own kind of opening, and a short clock.',
                'category' => 'distress',
                'icon' => 'triangle-alert',
                'query_templates' => [
                    '{location} business goes into administration',
                    'UK business insolvency administrators appointed',
                ],
                'match_keywords' => ['administration', 'insolven', 'liquidation', 'receivership', 'winding up'],
                'includes' => [
                    'a named company entering administration, liquidation or receivership',
                    'administrators or a liquidator being appointed to a named business',
                ],
                'excludes' => [
                    'insolvency statistics, monthly totals and sector commentary',
                    'advice and marketing pages from insolvency practitioners',
                ],
            ],
            [
                'key' => 'uk_planning_granted',
                'name' => 'Planning permission granted',
                'description' => 'A development was approved, so somebody is about to spend on building it.',
                'category' => 'regulatory',
                'icon' => 'building-2',
                'query_templates' => [
                    '{location} planning permission granted development',
                    '{location} council approves scheme',
                ],
                'match_keywords' => ['planning permission', 'planning approved', 'development approved', 'consent granted'],
                'includes' => [
                    'a named scheme or developer receiving approval from a named authority',
                ],
                'excludes' => [
                    'applications submitted, refused, appealed or still under consideration',
                    'policy and local-plan consultations',
                ],
            ],
            [
                'key' => 'uk_senior_appointment',
                'name' => 'Senior appointment',
                'description' => 'A new decision-maker arrives, with a budget and something to prove.',
                'category' => 'hiring',
                'icon' => 'users',
                'query_templates' => [
                    '{location} appoints chief executive',
                    '{location} appoints managing director',
                ],
                'match_keywords' => ['appoint', 'joins as', 'new chief', 'hires as', 'steps up to'],
                'includes' => [
                    'a named person appointed to a named company in a senior role',
                ],
                'excludes' => [
                    'interim and non-executive appointments with no spending authority',
                    '"people moves" round-ups covering several companies',
                    'recruitment-agency and executive-search marketing',
                ],
            ],
        ];
    }
};
