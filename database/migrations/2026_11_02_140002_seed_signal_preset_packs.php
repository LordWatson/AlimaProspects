<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Seed the S9 vertical preset packs — luxury real estate, recruitment, B2B software, wealth
     * management, construction and M&A — as platform rows.
     *
     * **A migration and not only the seeder, for the reason S8 found the hard way.** `DatabaseSeeder`
     * runs on a fresh install and nowhere else: an existing installation would never see these, and
     * the test database is built by migrations alone, so a library feature would have shipped with
     * an empty library everywhere that mattered.
     *
     * **Frozen, like every preset migration before it** (ADR 0018, "Consequences"). The rows below
     * were generated from `SignalPresetCatalogue` on 2026-11-02 and are a snapshot: a migration that
     * read the catalogue would seed whatever the catalogue has grown into by the time somebody runs
     * it, which is not what this migration was reviewed to do. Every template here was run against
     * live Serper `/news` first — see the spike table in `docs/modules/signals.md`.
     *
     * Idempotent on `key` within the platform scope, so a re-run after a partial failure inserts
     * only what is missing. None of these is a starter, so no team's one-click first watch changes.
     */
    public function up(): void
    {
        $now = Carbon::now();

        foreach ($this->presets() as $preset) {
            $exists = DB::table('signal_definitions')
                ->whereNull('team_id')
                ->where('key', $preset['key'])
                ->exists();

            if ($exists) {
                continue;
            }

            DB::table('signal_definitions')->insert([
                ...$preset,
                'team_id' => null,
                'query_templates' => json_encode($preset['query_templates'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'match_keywords' => json_encode($preset['match_keywords'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'classification_rules' => json_encode($preset['classification_rules'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                'thresholds' => json_encode($preset['thresholds'], JSON_UNESCAPED_SLASHES),
                'markets' => $preset['markets'] === null
                    ? null
                    : json_encode($preset['markets'], JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // **Platform rows only.** This migration inserted `team_id IS NULL` presets and nothing
        // else, so that is all a rollback may remove — a clone of one of them is a team's own tuned
        // copy, and deleting it would take the attribution on every signal it classified with it.
        // The same correction S8's review made to its UK migration.
        DB::table('signal_definitions')
            ->whereNull('team_id')
            ->whereIn('key', array_map(static fn (array $preset): string => (string) $preset['key'], $this->presets()))
            ->delete();
    }

    /**
     * The 21 packs' presets, frozen. See the class docblock for why these are not read from the
     * catalogue.
     *
     * @return list<array<string, mixed>>
     */
    private function presets(): array
    {
        return [
            [
                'key' => 'luxury_family_office',
                'name' => 'Family office opens',
                'description' => 'A wealthy family sets up or expands an investment office — new money with a local address.',
                'category' => 'liquidity',
                'icon' => 'landmark',
                'is_active' => true,
                'query_templates' => [
                    'family office launches {location}',
                    'new family office {location}',
                    '{location} single family office established',
                ],
                'match_keywords' => [
                    'family office',
                    'private office',
                    'wealth office',
                    'single family',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named family or individual setting up an investment office',
                        'an existing family office opening in this market or hiring its first team',
                    ],
                    'excludes' => [
                        'commentary about family offices as a trend, with no named family',
                        'a professional-services firm opening an office, which is not a family office',
                        'conferences, awards and league tables about family offices',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'luxury_senior_relocation',
                'name' => 'Senior arrival',
                'description' => 'A senior executive moves to this market — the household move behind the job move.',
                'category' => 'movement',
                'icon' => 'plane',
                'is_active' => true,
                'query_templates' => [
                    '{location} executive relocates',
                    '{location} appoints regional head',
                    'senior banker moves {location}',
                ],
                'match_keywords' => [
                    'relocat',
                    'regional head',
                    'moves to',
                    'appointed to lead',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named senior person moving to this market for a role',
                        'a company appointing a regional head who is relocating',
                    ],
                    'excludes' => [
                        'an internal promotion with no move',
                        '"people moves" round-ups covering several companies',
                        'relocation-service marketing and visa guides',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'rec_hiring_surge',
                'name' => 'Hiring surge',
                'description' => 'A business starts hiring in volume — the moment before they need help filling roles.',
                'category' => 'hiring',
                'icon' => 'users',
                'is_active' => true,
                'query_templates' => [
                    '{location} business expands team',
                    '{location} company hiring spree',
                    '{location} company recruitment drive',
                ],
                'match_keywords' => [
                    'hiring',
                    'recruit',
                    'headcount',
                    'expands team',
                    'jobs created',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company hiring a number of people, or opening a hiring round',
                        'a team or practice being built out, including a lift-out from a rival',
                    ],
                    'excludes' => [
                        'national or sector employment statistics',
                        'policy and Budget commentary about jobs',
                        'job adverts and careers pages',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'rec_new_office',
                'name' => 'New office or market',
                'description' => 'A company opens somewhere new — a whole team to staff from nothing.',
                'category' => 'growth',
                'icon' => 'building-2',
                'is_active' => true,
                'query_templates' => [
                    '{location} company opens office',
                    '{location} business new headquarters',
                    '{location} firm enters market',
                ],
                'match_keywords' => [
                    'opens office',
                    'new office',
                    'market entry',
                    'expansion into',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company opening an office, site or first presence in a market',
                        'an entry into a new country or city by a named business',
                    ],
                    'excludes' => [
                        'office-space listings and letting adverts',
                        'commentary about a city as a business destination',
                        'a company merely attending an event in a market',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'rec_senior_departure',
                'name' => 'Senior departure',
                'description' => 'A leader leaves — a vacancy at the top, and often a search already running.',
                'category' => 'movement',
                'icon' => 'users',
                'is_active' => true,
                'query_templates' => [
                    '{location} chief executive steps down',
                    '{location} company director resigns',
                    '{location} finance chief departs',
                ],
                'match_keywords' => [
                    'steps down',
                    'resign',
                    'departs',
                    'exits role',
                    'leaves company',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named senior person leaving a named company',
                        'a board or C-suite departure, announced or confirmed',
                    ],
                    'excludes' => [
                        '"people moves" round-ups covering several companies',
                        'a retirement announced years in advance with a successor already in place',
                        'speculation that somebody is "under pressure"',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'rec_restructure',
                'name' => 'Restructure or redundancies',
                'description' => 'A business cuts or reshapes its workforce — outplacement, contractors, and a rebuild after.',
                'category' => 'distress',
                'icon' => 'triangle-alert',
                'is_active' => true,
                'query_templates' => [
                    '{location} business announces redundancies',
                    '{location} company restructuring jobs',
                    '{location} firm cuts jobs',
                ],
                'match_keywords' => [
                    'redundanc',
                    'restructur',
                    'job cuts',
                    'layoff',
                    'downsiz',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company cutting roles or announcing a restructure',
                        'a consultation opening on redundancies at a named employer',
                    ],
                    'excludes' => [
                        'job-cut trackers covering a whole sector or year',
                        'national unemployment figures and Budget commentary',
                        'return-to-office and working-pattern opinion pieces',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'saas_new_leader',
                'name' => 'New leader in a target function',
                'description' => 'A new head of a function arrives — the one buyer with a mandate to change tools.',
                'category' => 'movement',
                'icon' => 'users',
                'is_active' => true,
                'query_templates' => [
                    '{location} appoints chief technology officer',
                    '{location} company names head of operations',
                    '{location} company appoints vice president',
                ],
                'match_keywords' => [
                    'appoints',
                    'names head',
                    'new cto',
                    'new coo',
                    'joins as',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company appointing a named person to lead a function',
                        'a newly created leadership role at a named business',
                    ],
                    'excludes' => [
                        '"this week’s people moves" round-ups',
                        'non-executive and advisory board appointments',
                        'promotions announced inside a wider reshuffle with no named function',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'saas_expansion',
                'name' => 'Expansion into a new region',
                'description' => 'A company starts selling somewhere new — new systems, new compliance, new urgency.',
                'category' => 'growth',
                'icon' => 'trending-up',
                'is_active' => true,
                'query_templates' => [
                    '{location} business launches new market',
                    '{location} firm opens second office',
                    '{location} company international expansion',
                ],
                'match_keywords' => [
                    'expansion',
                    'new market',
                    'launches in',
                    'international',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company launching in a new country or region',
                        'a second site, a local entity or a first international office',
                    ],
                    'excludes' => [
                        'export and trade policy commentary',
                        'investment-promotion pieces about a region in general',
                        'a product launch that is not a geographic expansion',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'saas_outage',
                'name' => 'Outage or service failure',
                'description' => 'Something broke publicly — the week a replacement gets evaluated.',
                'category' => 'distress',
                'icon' => 'triangle-alert',
                'is_active' => true,
                'query_templates' => [
                    '{location} company service outage',
                    '{location} firm website down',
                    '{location} business system failure customers',
                ],
                'match_keywords' => [
                    'outage',
                    'downtime',
                    'service failure',
                    'systems down',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company whose service or systems failed, affecting customers',
                        'an incident the company has acknowledged',
                    ],
                    'excludes' => [
                        'status-page scrapes and "is X down" aggregator pages',
                        'planned maintenance notices',
                        'commentary about reliability in general',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'saas_security_incident',
                'name' => 'Security or compliance incident',
                'description' => 'A breach or a regulatory finding — budget appears the same month.',
                'category' => 'regulatory',
                'icon' => 'triangle-alert',
                'is_active' => true,
                'query_templates' => [
                    '{location} data breach customers',
                    '{location} firm hit cyber attack',
                    '{location} company hacked',
                ],
                'match_keywords' => [
                    'breach',
                    'cyber',
                    'hack',
                    'ransomware',
                    'ico fine',
                    'penalty',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named organisation confirming a breach, attack or data incident',
                        'a regulator fining or formally finding against a named company',
                    ],
                    'excludes' => [
                        'vendor marketing and threat-report round-ups',
                        'advice pieces about how to avoid breaches',
                        'national cyber-strategy and policy coverage',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'wealth_company_sale',
                'name' => 'Business sale by its owner',
                'description' => 'An owner sells up — the single clearest liquidity event there is.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'is_active' => true,
                'query_templates' => [
                    '{location} founder sells business',
                    '{location} owner exits company',
                    '{location} entrepreneur sells stake',
                ],
                'match_keywords' => [
                    'sells business',
                    'founder exit',
                    'sold his',
                    'sold her',
                    'stake sale',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named founder or owner selling all or part of their business',
                        'a management buyout or sale to a named acquirer',
                    ],
                    'excludes' => [
                        'a fund or corporate selling a holding (no individual is liquid)',
                        'deal round-ups and M&A volume reports',
                        'rumours and "exploring a sale" stories',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'wealth_ipo',
                'name' => 'Listing or IPO',
                'description' => 'A company goes public — paper wealth becomes real on a known date.',
                'category' => 'liquidity',
                'icon' => 'trending-up',
                'is_active' => true,
                'query_templates' => [
                    '{location} company lists shares',
                    '{location} business announces IPO',
                ],
                'match_keywords' => [
                    'ipo',
                    'lists',
                    'listing',
                    'flotation',
                    'goes public',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company listing, floating or announcing an IPO',
                        'a direct listing or a move between markets by a named business',
                    ],
                    'excludes' => [
                        'market-wide IPO pipeline and volume commentary',
                        'index movements and daily market reports',
                        'analyst speculation about a possible future listing',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'wealth_secondary',
                'name' => 'Shareholder sells down',
                'description' => 'An existing shareholder takes money off the table without the company changing hands.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'is_active' => true,
                'query_templates' => [
                    '{location} shareholder sells shares',
                    '{location} company secondary sale',
                    '{location} investor exits stake',
                ],
                'match_keywords' => [
                    'secondary',
                    'sells shares',
                    'sells down',
                    'stake sold',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named shareholder selling part of their holding',
                        'a secondary transaction in a named private company',
                    ],
                    'excludes' => [
                        'routine director share dealings of nominal size',
                        'index-fund and institutional rebalancing',
                        'market commentary about secondaries as an asset class',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'const_planning_granted',
                'name' => 'Planning permission granted',
                'description' => 'A scheme is approved — the start of a procurement cycle with a date on it.',
                'category' => 'regulatory',
                'icon' => 'file-text',
                'is_active' => true,
                'query_templates' => [
                    '{location} planning approved homes',
                    '{location} council grants permission',
                    '{location} development approved scheme',
                ],
                'match_keywords' => [
                    'planning',
                    'permission',
                    'approved',
                    'consent granted',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named scheme receiving permission or consent',
                        'a committee approving a named development',
                    ],
                    'excludes' => [
                        'applications submitted but not yet decided',
                        'planning-policy and reform commentary',
                        'refusals and appeals, unless the appeal was allowed',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'const_project_award',
                'name' => 'Project or contract awarded',
                'description' => 'Somebody won the work — and now needs everyone below them in the chain.',
                'category' => 'growth',
                'icon' => 'handshake',
                'is_active' => true,
                'query_templates' => [
                    '{location} construction contract awarded',
                    '{location} firm appointed build',
                    '{location} contractor wins contract',
                ],
                'match_keywords' => [
                    'contract awarded',
                    'wins contract',
                    'appointed contractor',
                    'tender',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named contractor winning or being appointed to a named project',
                        'a framework or package award to a named firm',
                    ],
                    'excludes' => [
                        'tender notices and opportunities not yet awarded',
                        'order-book and sector-outlook reporting',
                        'award ceremonies and industry prizes',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'const_new_hq',
                'name' => 'New headquarters or move',
                'description' => 'A company takes space — a fit-out brief, usually already written.',
                'category' => 'movement',
                'icon' => 'building-2',
                'is_active' => true,
                'query_templates' => [
                    '{location} company new headquarters building',
                    '{location} firm takes space',
                    '{location} company relocates headquarters',
                ],
                'match_keywords' => [
                    'headquarters',
                    'takes space',
                    'relocat',
                    'new premises',
                    'sq ft',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company signing for, taking or moving into space',
                        'a named business announcing a new headquarters',
                    ],
                    'excludes' => [
                        'agency listings and available-space adverts',
                        'office-market vacancy and rent reports',
                        'opinion pieces about where companies should be based',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'const_hotel_launch',
                'name' => 'Hotel or tower launch',
                'description' => 'A hospitality or tower project reaches the public stage — interiors, FF&E, and a deadline.',
                'category' => 'growth',
                'icon' => 'building-2',
                'is_active' => true,
                'query_templates' => [
                    '{location} hotel opens',
                    '{location} tower launches',
                    '{location} resort breaks ground',
                ],
                'match_keywords' => [
                    'hotel',
                    'tower',
                    'resort',
                    'breaks ground',
                    'opens doors',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named hotel, tower or resort opening, launching or starting on site',
                        'an operator announcing a named property in this market',
                    ],
                    'excludes' => [
                        'travel reviews, "best hotels" listicles and guest-experience pieces',
                        'occupancy and tourism statistics',
                        'refurbishment rumours with no named project',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'ma_shareholding_change',
                'name' => 'Shareholding change',
                'description' => 'Who owns a company changed — the first public sign of a deal or a dispute.',
                'category' => 'liquidity',
                'icon' => 'file-text',
                'is_active' => true,
                'query_templates' => [
                    '{location} company new shareholder',
                    '{location} business stake acquired',
                    '{location} firm ownership changes',
                ],
                'match_keywords' => [
                    'shareholder',
                    'stake',
                    'ownership',
                    'psc',
                    'controlling interest',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named party taking or increasing a stake in a named company',
                        'a change of control or majority shareholder',
                    ],
                    'excludes' => [
                        'routine exchange notifications of nominal holdings',
                        'activist-investor commentary with no change of ownership',
                        'governance and stewardship opinion pieces',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'ma_cfo_change',
                'name' => 'Finance chief or auditor change',
                'description' => 'A new CFO or a new auditor — often the quiet start of a transaction or a problem.',
                'category' => 'movement',
                'icon' => 'file-text',
                'is_active' => true,
                'query_templates' => [
                    '{location} business new chief financial officer',
                    '{location} appoints finance director',
                    '{location} firm changes auditor',
                ],
                'match_keywords' => [
                    'cfo',
                    'finance director',
                    'auditor',
                    'financial officer',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company appointing a finance chief or changing auditor',
                        'a finance leadership change at a named business',
                    ],
                    'excludes' => [
                        '"people moves" round-ups covering several companies',
                        'accountancy-firm marketing and league tables',
                        'audit-reform and regulation commentary',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'ma_insolvency',
                'name' => 'Administration or insolvency',
                'description' => 'A business fails — assets, a buyer, and a short window to act.',
                'category' => 'distress',
                'icon' => 'triangle-alert',
                'is_active' => true,
                'query_templates' => [
                    '{location} business liquidation',
                    '{location} firm insolvency appointed',
                    '{location} business goes into administration',
                ],
                'match_keywords' => [
                    'administration',
                    'insolvenc',
                    'liquidation',
                    'receiver',
                    'wound up',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named company entering administration, liquidation or receivership',
                        'administrators or a liquidator being appointed to a named business',
                    ],
                    'excludes' => [
                        'insolvency statistics and sector failure-rate reports',
                        'insolvency-practitioner marketing and advice pages',
                        '"on the brink" speculation with no appointment',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
            [
                'key' => 'ma_divestment',
                'name' => 'Carve-out or divestment',
                'description' => 'A group sells a division — a transaction with a seller who has already decided.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'is_active' => true,
                'query_templates' => [
                    '{location} company sells division',
                    '{location} group carve out sale',
                    '{location} business divests unit',
                ],
                'match_keywords' => [
                    'divest',
                    'carve out',
                    'sells division',
                    'disposal',
                    'sells unit',
                ],
                'classification_rules' => [
                    'includes' => [
                        'a named group selling a named subsidiary, division or brand',
                        'a carve-out or disposal agreed with a named buyer',
                    ],
                    'excludes' => [
                        'portfolio-review and strategy commentary with no sale',
                        'deal round-ups and league tables',
                        '"considering options for" stories',
                    ],
                ],
                'thresholds' => [
                    'min_confidence' => null,
                    'min_relevance' => null,
                ],
                'suggested_approach_hint' => null,
                'markets' => null,
                'version' => 1,
            ],
        ];
    }
};
