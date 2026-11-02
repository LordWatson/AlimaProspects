<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Core\Enums\Market;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;

/**
 * The platform signal-definition presets, declared in code (ADR 0018 §2).
 *
 * This is the **living** source of truth: `SignalPresetSeeder` writes it, the admin registry reads
 * its labels, and S9 grows it into vertical packs. The S7 data migration deliberately does **not**
 * read it — a migration is a snapshot, and a migration that read a catalogue which later grew would
 * clone thirty definitions into every existing team on a fresh install. Its copy of these three is
 * frozen inline, by design (ADR 0018, "Consequences").
 *
 * The first three are v1's `SignalEventType` cases, carried over **unchanged**: the same templates
 * (from `config/signals.php`, loosened to plain keywords by the S0 spike — exact phrases and
 * `-based` returned zero results on `/news`), the same descriptions (from the enum), the same ICP
 * keyword map (from `SignalQueryBuilder::KEYWORDS`), and null thresholds so the global
 * `signals.min_confidence` / `signals.min_relevance` still apply. S7 changed where a type lives,
 * not what it finds.
 *
 * S8 adds a **UK pack**, spiked against live Serper `/news` the way S0 spiked the UAE one — the
 * finding that exact phrases and `-based` return nothing is a Serper fact rather than a UAE fact,
 * so the same discipline applies and the result is recorded in `docs/modules/signals.md`. Two of
 * the six are the UK's own published analogues rather than news events a journalist would file:
 * a **PSC change** (people with significant control) is the closest thing Companies House
 * publishes to "the owner just sold", and **insolvency** is a filing before it is a story. Both
 * are keyword searches here and become registry reads in S13, which is the phase that can do
 * them properly.
 *
 * `markets` is what keeps the two packs apart on screen: a UK team is offered the UK pack, and
 * `handleStartersForMarket()` clones only the **starters** for their market, so a first watch is
 * three types rather than nine.
 *
 * **S9 fills the library out into vertical packs** — luxury real estate, recruitment, B2B software,
 * wealth management, construction and M&A — because "write your own signal type" is a blank page,
 * and a library of nine general types does not tell a recruiter what a good one looks like. Three
 * things about that growth are deliberate:
 *
 * - Every one of the 66 candidate templates was run against live Serper `/news` before it shipped,
 *   the same discipline S0 established. **Eight returned zero** and were replaced; one whole preset
 *   (a sports or entertainment contract, which the plan asked for) was **dropped**, because the news
 *   for it is transfer rumour rather than signed deals; three ship marked {@see SignalPreset::$isThin}.
 *   The table is in `docs/modules/signals.md`. A preset never run against the provider is a guess
 *   with a label on it.
 * - **None of the new ones is a starter.** The one-click first watch has to stay small — a team
 *   landing on thirty types would be past `max_definitions_per_team` and paying for a search per
 *   template per type on every run.
 * - The pack lives on the preset and **not** in the database (see {@see SignalPresetPack}).
 */
final class SignalPresetCatalogue
{
    public const string ACQUISITION = 'acquisition';

    public const string FUNDING_ROUND = 'funding_round';

    public const string RELOCATION = 'relocation';

    // --- UK pack (S8) ---------------------------------------------------------------------------
    // Keyed `uk_*` because a key is unique per scope and the UAE pack already owns `acquisition`
    // and `funding_round`. A team working both markets can hold both, and the stored `event_key`
    // still says which one found a signal — which is what the cross-tenant admin filter and S15's
    // platform reporting group by.

    public const string UK_ACQUISITION = 'uk_acquisition';

    public const string UK_FUNDING_ROUND = 'uk_funding_round';

    public const string UK_PSC_CHANGE = 'uk_psc_change';

    public const string UK_INSOLVENCY = 'uk_insolvency';

    public const string UK_PLANNING_GRANTED = 'uk_planning_granted';

    public const string UK_SENIOR_APPOINTMENT = 'uk_senior_appointment';

    // --- The vertical packs (S9) ---------------------------------------------------------------
    // Keyed by pack prefix for the same reason the UK pack is: a key is unique per scope, and
    // "acquisition" is already taken by the pack that got there first. The prefix also makes a
    // stored `event_key` legible a year later — `wealth_company_sale` says which library row found
    // a signal, which is what the admin filter and platform reporting group by.
    //
    // Every template below was run against live Serper `/news` before it shipped (the S0
    // discipline). The table is in `docs/modules/signals.md`; eight of the first 66 returned **zero**
    // and were replaced, one preset was dropped outright for returning nothing but transfer gossip,
    // and three are shipped marked thin.

    public const string LUXURY_FAMILY_OFFICE = 'luxury_family_office';

    public const string LUXURY_SENIOR_RELOCATION = 'luxury_senior_relocation';

    public const string REC_HIRING_SURGE = 'rec_hiring_surge';

    public const string REC_NEW_OFFICE = 'rec_new_office';

    public const string REC_SENIOR_DEPARTURE = 'rec_senior_departure';

    public const string REC_RESTRUCTURE = 'rec_restructure';

    public const string SAAS_NEW_LEADER = 'saas_new_leader';

    public const string SAAS_EXPANSION = 'saas_expansion';

    public const string SAAS_OUTAGE = 'saas_outage';

    public const string SAAS_SECURITY_INCIDENT = 'saas_security_incident';

    public const string WEALTH_COMPANY_SALE = 'wealth_company_sale';

    public const string WEALTH_IPO = 'wealth_ipo';

    public const string WEALTH_SECONDARY = 'wealth_secondary';

    public const string CONST_PLANNING_GRANTED = 'const_planning_granted';

    public const string CONST_PROJECT_AWARD = 'const_project_award';

    public const string CONST_NEW_HQ = 'const_new_hq';

    public const string CONST_HOTEL_LAUNCH = 'const_hotel_launch';

    public const string MA_SHAREHOLDING_CHANGE = 'ma_shareholding_change';

    public const string MA_CFO_CHANGE = 'ma_cfo_change';

    public const string MA_INSOLVENCY = 'ma_insolvency';

    public const string MA_DIVESTMENT = 'ma_divestment';

    /**
     * Every preset, in the order a team's clones are created — which is also the order
     * `SignalQueryBuilder` resolves an ambiguous ICP buying signal in (first match wins, as v1's
     * enum order did; see `docs/modules/signals.md`).
     *
     * @return list<SignalPreset>
     */
    public static function all(): array
    {
        return [
            new SignalPreset(
                key: self::ACQUISITION,
                name: 'Acquisition',
                description: 'A company is sold or a founder exits — a classic liquidity event.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} company acquisition',
                    'UAE acquisition deal',
                    'UAE startup acquired',
                ],
                matchKeywords: ['acqui', 'sold', 'exit', 'merger', 'm&a', 'buyout', 'takeover', 'stake sale'],
                includes: [
                    'a company being bought, sold or merged',
                    'a founder or shareholder selling a stake or exiting',
                    'a named buyer acquiring a named business',
                ],
                excludes: [
                    'market-wide M&A totals or deal-volume reports',
                    'rumours, talks, "exploring options" and anything not yet done',
                    'weekly or monthly deal round-ups covering several companies',
                ],
                markets: [Market::Uae],
                pack: SignalPresetPack::LuxuryRealEstate,
            ),
            new SignalPreset(
                key: self::FUNDING_ROUND,
                name: 'Funding round',
                description: 'A business raises a funding round, putting fresh capital in founders’ hands.',
                category: SignalCategory::Growth,
                icon: SignalIcon::TrendingUp,
                queryTemplates: [
                    '{location} startup raises funding',
                    'UAE Series B funding',
                    '{location} startup Series A funding round',
                ],
                matchKeywords: ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
                includes: [
                    'a named company closing a named round (pre-seed through late stage)',
                    'a founder or company receiving investment from a named investor',
                ],
                excludes: [
                    'a fund closing its own fund (that is the investor, not the prospect)',
                    'funding trackers, listicles and "this week in funding" pages',
                    'rounds in other regions that merely mention this location',
                ],
                markets: [Market::Uae],
                pack: SignalPresetPack::LuxuryRealEstate,
            ),
            // --- UK ---------------------------------------------------------------------------
            new SignalPreset(
                key: self::UK_ACQUISITION,
                name: 'Acquisition',
                description: 'A company is sold or a founder exits — a classic liquidity event.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} company acquired',
                    'UK business sold private equity',
                    '{location} founder sells stake',
                ],
                matchKeywords: ['acqui', 'sold', 'exit', 'merger', 'm&a', 'buyout', 'takeover', 'stake sale'],
                includes: [
                    'a named company being bought, sold or merged',
                    'a founder or shareholder selling a stake or exiting',
                ],
                excludes: [
                    'market-wide M&A totals, league tables and deal-volume reports',
                    'rumours, talks, "exploring options" and anything not yet done',
                    'weekly or monthly deal round-ups covering several companies',
                ],
                markets: [Market::Uk],
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::UK_FUNDING_ROUND,
                name: 'Funding round',
                description: 'A business raises a funding round, putting fresh capital in founders’ hands.',
                category: SignalCategory::Growth,
                icon: SignalIcon::TrendingUp,
                queryTemplates: [
                    '{location} startup raises funding',
                    'UK startup Series A funding round',
                    // Replaces 'UK scaleup raises million', which returned 8 results and almost no
                    // genuine events - weekly round-ups and a law-society digest (S8 spike).
                    '{location} company secures investment',
                ],
                matchKeywords: ['fund', 'rais', 'series ', 'seed', 'investment', 'venture'],
                includes: [
                    'a named company closing a named round (pre-seed through late stage)',
                    'a founder or company receiving investment from a named investor',
                ],
                excludes: [
                    'a fund closing its own fund (that is the investor, not the prospect)',
                    'funding trackers, listicles and "this week in funding" pages',
                    'rounds in other regions that merely mention the UK',
                ],
                markets: [Market::Uk],
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::UK_PSC_CHANGE,
                name: 'Ownership change',
                description: 'Who controls a UK company changed — the registry’s version of “the owner just sold”.',
                category: SignalCategory::Regulatory,
                icon: SignalIcon::FileText,
                queryTemplates: [
                    // 'UK company change of ownership Companies House' returned 10 results and not
                    // one genuine event - sector studies, Budget summaries, registry statistics
                    // (S8 spike). Dropped rather than kept at a low relevance floor.
                    '{location} company new majority shareholder',
                    '{location} business changes hands owner',
                ],
                matchKeywords: ['psc', 'significant control', 'shareholding', 'ownership change', 'majority stake'],
                includes: [
                    'a named company whose controlling shareholder or parent has changed',
                    'a filing recording a change in people with significant control',
                ],
                excludes: [
                    'routine officer appointments with no change of control',
                    'summaries of filing volumes or registry statistics',
                ],
                markets: [Market::Uk],
                // Not a starter: a keyword search over news is a poor proxy for a registry read,
                // and S13's Companies House source is what makes this type worth having. Offered in
                // the library so a team can try it; not in the one-click first watch, where a type
                // that finds little would be the first thing they saw.
                isStarter: false,
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::UK_INSOLVENCY,
                name: 'Administration or insolvency',
                description: 'A business is in trouble — which is its own kind of opening, and a short clock.',
                category: SignalCategory::Distress,
                icon: SignalIcon::TriangleAlert,
                queryTemplates: [
                    // '{location} company enters administration' returned **0** - the same failure
                    // mode the S0 spike found, where a tight phrase plus qdr:w matches no headline.
                    // The plainer form returns 10 (S8 spike).
                    '{location} business goes into administration',
                    'UK business insolvency administrators appointed',
                ],
                matchKeywords: ['administration', 'insolven', 'liquidation', 'receivership', 'winding up'],
                includes: [
                    'a named company entering administration, liquidation or receivership',
                    'administrators or a liquidator being appointed to a named business',
                ],
                excludes: [
                    'insolvency statistics, monthly totals and sector commentary',
                    'advice and marketing pages from insolvency practitioners',
                ],
                markets: [Market::Uk],
                isStarter: false,
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::UK_PLANNING_GRANTED,
                name: 'Planning permission granted',
                description: 'A development was approved, so somebody is about to spend on building it.',
                category: SignalCategory::Regulatory,
                icon: SignalIcon::Building,
                queryTemplates: [
                    '{location} planning permission granted development',
                    '{location} council approves scheme',
                ],
                matchKeywords: ['planning permission', 'planning approved', 'development approved', 'consent granted'],
                includes: [
                    'a named scheme or developer receiving approval from a named authority',
                ],
                excludes: [
                    'applications submitted, refused, appealed or still under consideration',
                    'policy and local-plan consultations',
                ],
                markets: [Market::Uk],
                isStarter: false,
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::UK_SENIOR_APPOINTMENT,
                name: 'Senior appointment',
                description: 'A new decision-maker arrives, with a budget and something to prove.',
                category: SignalCategory::Hiring,
                icon: SignalIcon::Users,
                queryTemplates: [
                    '{location} appoints chief executive',
                    // 'UK company appoints new managing director' returned **0**; dropping the two
                    // filler words takes it to 10 (S8 spike).
                    '{location} appoints managing director',
                ],
                matchKeywords: ['appoint', 'joins as', 'new chief', 'hires as', 'steps up to'],
                includes: [
                    'a named person appointed to a named company in a senior role',
                ],
                excludes: [
                    'interim and non-executive appointments with no spending authority',
                    '"people moves" round-ups covering several companies',
                    'recruitment-agency and executive-search marketing',
                ],
                markets: [Market::Uk],
                isStarter: false,
                pack: SignalPresetPack::General,
            ),
            new SignalPreset(
                key: self::RELOCATION,
                name: 'Relocation',
                description: 'A founder, executive or family office relocates to the market.',
                category: SignalCategory::Movement,
                icon: SignalIcon::Plane,
                queryTemplates: [
                    '{location} founder relocates',
                    'family office {location} opens',
                ],
                matchKeywords: ['relocat', 'moving', 'moves to', 'move to', 'family office', 'headquarter', 'opens office'],
                includes: [
                    'a named person moving themselves or their business to this location',
                    'a family office or private office opening here',
                    'a business moving its headquarters here',
                ],
                excludes: [
                    'general "why people are moving to X" commentary with no named subject',
                    'relocation-service and visa-advice marketing pages',
                    'a company opening a branch office with no principal moving',
                ],
                markets: [Market::Uae],
                pack: SignalPresetPack::LuxuryRealEstate,
            ),
            // --- Luxury real estate (S9): the v1 thesis, filled out --------------------------
            new SignalPreset(
                key: self::LUXURY_FAMILY_OFFICE,
                name: 'Family office opens',
                description: 'A wealthy family sets up or expands an investment office — new money with a local address.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Landmark,
                queryTemplates: [
                    'family office launches {location}',
                    'new family office {location}',
                    '{location} single family office established',
                ],
                matchKeywords: ['family office', 'private office', 'wealth office', 'single family'],
                includes: [
                    'a named family or individual setting up an investment office',
                    'an existing family office opening in this market or hiring its first team',
                ],
                excludes: [
                    'commentary about family offices as a trend, with no named family',
                    'a professional-services firm opening an office, which is not a family office',
                    'conferences, awards and league tables about family offices',
                ],
                isStarter: false,
                pack: SignalPresetPack::LuxuryRealEstate,
                // The thinnest of the nine spiked at 10/4 results, and most of those were trend
                // pieces rather than openings. A family office opening is a real event that is
                // simply rare, which is also what v1 found about relocation.
                isThin: true,
            ),
            new SignalPreset(
                key: self::LUXURY_SENIOR_RELOCATION,
                name: 'Senior arrival',
                description: 'A senior executive moves to this market — the household move behind the job move.',
                category: SignalCategory::Movement,
                icon: SignalIcon::Plane,
                queryTemplates: [
                    '{location} executive relocates',
                    '{location} appoints regional head',
                    'senior banker moves {location}',
                ],
                matchKeywords: ['relocat', 'regional head', 'moves to', 'appointed to lead'],
                includes: [
                    'a named senior person moving to this market for a role',
                    'a company appointing a regional head who is relocating',
                ],
                excludes: [
                    'an internal promotion with no move',
                    '"people moves" round-ups covering several companies',
                    'relocation-service marketing and visa guides',
                ],
                isStarter: false,
                pack: SignalPresetPack::LuxuryRealEstate,
            ),

            // --- Recruitment & staffing (S9) -------------------------------------------------
            new SignalPreset(
                key: self::REC_HIRING_SURGE,
                name: 'Hiring surge',
                description: 'A business starts hiring in volume — the moment before they need help filling roles.',
                category: SignalCategory::Hiring,
                icon: SignalIcon::Users,
                queryTemplates: [
                    '{location} business expands team',
                    '{location} company hiring spree',
                    '{location} company recruitment drive',
                ],
                matchKeywords: ['hiring', 'recruit', 'headcount', 'expands team', 'jobs created'],
                includes: [
                    'a named company hiring a number of people, or opening a hiring round',
                    'a team or practice being built out, including a lift-out from a rival',
                ],
                excludes: [
                    'national or sector employment statistics',
                    'policy and Budget commentary about jobs',
                    'job adverts and careers pages',
                ],
                isStarter: false,
                pack: SignalPresetPack::Recruitment,
            ),
            new SignalPreset(
                key: self::REC_NEW_OFFICE,
                name: 'New office or market',
                description: 'A company opens somewhere new — a whole team to staff from nothing.',
                category: SignalCategory::Growth,
                icon: SignalIcon::Building,
                queryTemplates: [
                    '{location} company opens office',
                    '{location} business new headquarters',
                    '{location} firm enters market',
                ],
                matchKeywords: ['opens office', 'new office', 'market entry', 'expansion into'],
                includes: [
                    'a named company opening an office, site or first presence in a market',
                    'an entry into a new country or city by a named business',
                ],
                excludes: [
                    'office-space listings and letting adverts',
                    'commentary about a city as a business destination',
                    'a company merely attending an event in a market',
                ],
                isStarter: false,
                pack: SignalPresetPack::Recruitment,
            ),
            new SignalPreset(
                key: self::REC_SENIOR_DEPARTURE,
                name: 'Senior departure',
                description: 'A leader leaves — a vacancy at the top, and often a search already running.',
                category: SignalCategory::Movement,
                icon: SignalIcon::Users,
                queryTemplates: [
                    '{location} chief executive steps down',
                    '{location} company director resigns',
                    '{location} finance chief departs',
                ],
                matchKeywords: ['steps down', 'resign', 'departs', 'exits role', 'leaves company'],
                includes: [
                    'a named senior person leaving a named company',
                    'a board or C-suite departure, announced or confirmed',
                ],
                excludes: [
                    '"people moves" round-ups covering several companies',
                    'a retirement announced years in advance with a successor already in place',
                    'speculation that somebody is "under pressure"',
                ],
                isStarter: false,
                pack: SignalPresetPack::Recruitment,
            ),
            new SignalPreset(
                key: self::REC_RESTRUCTURE,
                name: 'Restructure or redundancies',
                description: 'A business cuts or reshapes its workforce — outplacement, contractors, and a rebuild after.',
                category: SignalCategory::Distress,
                icon: SignalIcon::TriangleAlert,
                queryTemplates: [
                    '{location} business announces redundancies',
                    '{location} company restructuring jobs',
                    '{location} firm cuts jobs',
                ],
                matchKeywords: ['redundanc', 'restructur', 'job cuts', 'layoff', 'downsiz'],
                includes: [
                    'a named company cutting roles or announcing a restructure',
                    'a consultation opening on redundancies at a named employer',
                ],
                excludes: [
                    'job-cut trackers covering a whole sector or year',
                    'national unemployment figures and Budget commentary',
                    'return-to-office and working-pattern opinion pieces',
                ],
                isStarter: false,
                pack: SignalPresetPack::Recruitment,
            ),

            // --- B2B software (S9) ------------------------------------------------------------
            new SignalPreset(
                key: self::SAAS_NEW_LEADER,
                name: 'New leader in a target function',
                description: 'A new head of a function arrives — the one buyer with a mandate to change tools.',
                category: SignalCategory::Movement,
                icon: SignalIcon::Users,
                queryTemplates: [
                    '{location} appoints chief technology officer',
                    '{location} company names head of operations',
                    '{location} company appoints vice president',
                ],
                matchKeywords: ['appoints', 'names head', 'new cto', 'new coo', 'joins as'],
                includes: [
                    'a named company appointing a named person to lead a function',
                    'a newly created leadership role at a named business',
                ],
                excludes: [
                    '"this week’s people moves" round-ups',
                    'non-executive and advisory board appointments',
                    'promotions announced inside a wider reshuffle with no named function',
                ],
                isStarter: false,
                pack: SignalPresetPack::B2bSaas,
            ),
            new SignalPreset(
                key: self::SAAS_EXPANSION,
                name: 'Expansion into a new region',
                description: 'A company starts selling somewhere new — new systems, new compliance, new urgency.',
                category: SignalCategory::Growth,
                icon: SignalIcon::TrendingUp,
                queryTemplates: [
                    '{location} business launches new market',
                    '{location} firm opens second office',
                    '{location} company international expansion',
                ],
                matchKeywords: ['expansion', 'new market', 'launches in', 'international'],
                includes: [
                    'a named company launching in a new country or region',
                    'a second site, a local entity or a first international office',
                ],
                excludes: [
                    'export and trade policy commentary',
                    'investment-promotion pieces about a region in general',
                    'a product launch that is not a geographic expansion',
                ],
                isStarter: false,
                pack: SignalPresetPack::B2bSaas,
            ),
            new SignalPreset(
                key: self::SAAS_OUTAGE,
                name: 'Outage or service failure',
                description: 'Something broke publicly — the week a replacement gets evaluated.',
                category: SignalCategory::Distress,
                icon: SignalIcon::TriangleAlert,
                queryTemplates: [
                    '{location} company service outage',
                    '{location} firm website down',
                    '{location} business system failure customers',
                ],
                matchKeywords: ['outage', 'downtime', 'service failure', 'systems down'],
                includes: [
                    'a named company whose service or systems failed, affecting customers',
                    'an incident the company has acknowledged',
                ],
                excludes: [
                    'status-page scrapes and "is X down" aggregator pages',
                    'planned maintenance notices',
                    'commentary about reliability in general',
                ],
                isStarter: false,
                pack: SignalPresetPack::B2bSaas,
            ),
            new SignalPreset(
                key: self::SAAS_SECURITY_INCIDENT,
                name: 'Security or compliance incident',
                description: 'A breach or a regulatory finding — budget appears the same month.',
                category: SignalCategory::Regulatory,
                icon: SignalIcon::TriangleAlert,
                queryTemplates: [
                    '{location} data breach customers',
                    '{location} firm hit cyber attack',
                    '{location} company hacked',
                ],
                matchKeywords: ['breach', 'cyber', 'hack', 'ransomware', 'ico fine', 'penalty'],
                includes: [
                    'a named organisation confirming a breach, attack or data incident',
                    'a regulator fining or formally finding against a named company',
                ],
                excludes: [
                    'vendor marketing and threat-report round-ups',
                    'advice pieces about how to avoid breaches',
                    'national cyber-strategy and policy coverage',
                ],
                isStarter: false,
                pack: SignalPresetPack::B2bSaas,
            ),

            // --- Wealth management (S9) ------------------------------------------------------
            new SignalPreset(
                key: self::WEALTH_COMPANY_SALE,
                name: 'Business sale by its owner',
                description: 'An owner sells up — the single clearest liquidity event there is.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} founder sells business',
                    '{location} owner exits company',
                    '{location} entrepreneur sells stake',
                ],
                matchKeywords: ['sells business', 'founder exit', 'sold his', 'sold her', 'stake sale'],
                includes: [
                    'a named founder or owner selling all or part of their business',
                    'a management buyout or sale to a named acquirer',
                ],
                excludes: [
                    'a fund or corporate selling a holding (no individual is liquid)',
                    'deal round-ups and M&A volume reports',
                    'rumours and "exploring a sale" stories',
                ],
                isStarter: false,
                pack: SignalPresetPack::WealthManagement,
            ),
            new SignalPreset(
                key: self::WEALTH_IPO,
                name: 'Listing or IPO',
                description: 'A company goes public — paper wealth becomes real on a known date.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::TrendingUp,
                queryTemplates: [
                    '{location} company lists shares',
                    '{location} business announces IPO',
                ],
                matchKeywords: ['ipo', 'lists', 'listing', 'flotation', 'goes public'],
                includes: [
                    'a named company listing, floating or announcing an IPO',
                    'a direct listing or a move between markets by a named business',
                ],
                excludes: [
                    'market-wide IPO pipeline and volume commentary',
                    'index movements and daily market reports',
                    'analyst speculation about a possible future listing',
                ],
                isStarter: false,
                pack: SignalPresetPack::WealthManagement,
            ),
            new SignalPreset(
                key: self::WEALTH_SECONDARY,
                name: 'Shareholder sells down',
                description: 'An existing shareholder takes money off the table without the company changing hands.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} shareholder sells shares',
                    '{location} company secondary sale',
                    '{location} investor exits stake',
                ],
                matchKeywords: ['secondary', 'sells shares', 'sells down', 'stake sold'],
                includes: [
                    'a named shareholder selling part of their holding',
                    'a secondary transaction in a named private company',
                ],
                excludes: [
                    'routine director share dealings of nominal size',
                    'index-fund and institutional rebalancing',
                    'market commentary about secondaries as an asset class',
                ],
                isStarter: false,
                pack: SignalPresetPack::WealthManagement,
                // Real transactions came back, mixed with exchange filings and nominal director
                // dealings. The relevance floor does the work here more than the templates do.
                isThin: true,
            ),

            // --- Construction & fit-out (S9) -------------------------------------------------
            new SignalPreset(
                key: self::CONST_PLANNING_GRANTED,
                name: 'Planning permission granted',
                description: 'A scheme is approved — the start of a procurement cycle with a date on it.',
                category: SignalCategory::Regulatory,
                icon: SignalIcon::FileText,
                queryTemplates: [
                    '{location} planning approved homes',
                    '{location} council grants permission',
                    '{location} development approved scheme',
                ],
                matchKeywords: ['planning', 'permission', 'approved', 'consent granted'],
                includes: [
                    'a named scheme receiving permission or consent',
                    'a committee approving a named development',
                ],
                excludes: [
                    'applications submitted but not yet decided',
                    'planning-policy and reform commentary',
                    'refusals and appeals, unless the appeal was allowed',
                ],
                isStarter: false,
                pack: SignalPresetPack::Construction,
            ),
            new SignalPreset(
                key: self::CONST_PROJECT_AWARD,
                name: 'Project or contract awarded',
                description: 'Somebody won the work — and now needs everyone below them in the chain.',
                category: SignalCategory::Growth,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} construction contract awarded',
                    '{location} firm appointed build',
                    '{location} contractor wins contract',
                ],
                matchKeywords: ['contract awarded', 'wins contract', 'appointed contractor', 'tender'],
                includes: [
                    'a named contractor winning or being appointed to a named project',
                    'a framework or package award to a named firm',
                ],
                excludes: [
                    'tender notices and opportunities not yet awarded',
                    'order-book and sector-outlook reporting',
                    'award ceremonies and industry prizes',
                ],
                isStarter: false,
                pack: SignalPresetPack::Construction,
            ),
            new SignalPreset(
                key: self::CONST_NEW_HQ,
                name: 'New headquarters or move',
                description: 'A company takes space — a fit-out brief, usually already written.',
                category: SignalCategory::Movement,
                icon: SignalIcon::Building,
                queryTemplates: [
                    '{location} company new headquarters building',
                    '{location} firm takes space',
                    '{location} company relocates headquarters',
                ],
                matchKeywords: ['headquarters', 'takes space', 'relocat', 'new premises', 'sq ft'],
                includes: [
                    'a named company signing for, taking or moving into space',
                    'a named business announcing a new headquarters',
                ],
                excludes: [
                    'agency listings and available-space adverts',
                    'office-market vacancy and rent reports',
                    'opinion pieces about where companies should be based',
                ],
                isStarter: false,
                pack: SignalPresetPack::Construction,
            ),
            new SignalPreset(
                key: self::CONST_HOTEL_LAUNCH,
                name: 'Hotel or tower launch',
                description: 'A hospitality or tower project reaches the public stage — interiors, FF&E, and a deadline.',
                category: SignalCategory::Growth,
                icon: SignalIcon::Building,
                queryTemplates: [
                    '{location} hotel opens',
                    '{location} tower launches',
                    '{location} resort breaks ground',
                ],
                matchKeywords: ['hotel', 'tower', 'resort', 'breaks ground', 'opens doors'],
                includes: [
                    'a named hotel, tower or resort opening, launching or starting on site',
                    'an operator announcing a named property in this market',
                ],
                excludes: [
                    'travel reviews, "best hotels" listicles and guest-experience pieces',
                    'occupancy and tourism statistics',
                    'refurbishment rumours with no named project',
                ],
                isStarter: false,
                pack: SignalPresetPack::Construction,
            ),

            // --- M&A and corporate finance (S9) ----------------------------------------------
            new SignalPreset(
                key: self::MA_SHAREHOLDING_CHANGE,
                name: 'Shareholding change',
                description: 'Who owns a company changed — the first public sign of a deal or a dispute.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::FileText,
                queryTemplates: [
                    '{location} company new shareholder',
                    '{location} business stake acquired',
                    '{location} firm ownership changes',
                ],
                matchKeywords: ['shareholder', 'stake', 'ownership', 'psc', 'controlling interest'],
                includes: [
                    'a named party taking or increasing a stake in a named company',
                    'a change of control or majority shareholder',
                ],
                excludes: [
                    'routine exchange notifications of nominal holdings',
                    'activist-investor commentary with no change of ownership',
                    'governance and stewardship opinion pieces',
                ],
                isStarter: false,
                pack: SignalPresetPack::MaAdvisory,
                // Genuine changes of control came back alongside exchange notices; the spike found
                // roughly one real event per ten results.
                isThin: true,
            ),
            new SignalPreset(
                key: self::MA_CFO_CHANGE,
                name: 'Finance chief or auditor change',
                description: 'A new CFO or a new auditor — often the quiet start of a transaction or a problem.',
                category: SignalCategory::Movement,
                icon: SignalIcon::FileText,
                queryTemplates: [
                    '{location} business new chief financial officer',
                    '{location} appoints finance director',
                    '{location} firm changes auditor',
                ],
                matchKeywords: ['cfo', 'finance director', 'auditor', 'financial officer'],
                includes: [
                    'a named company appointing a finance chief or changing auditor',
                    'a finance leadership change at a named business',
                ],
                excludes: [
                    '"people moves" round-ups covering several companies',
                    'accountancy-firm marketing and league tables',
                    'audit-reform and regulation commentary',
                ],
                isStarter: false,
                pack: SignalPresetPack::MaAdvisory,
            ),
            new SignalPreset(
                key: self::MA_INSOLVENCY,
                name: 'Administration or insolvency',
                description: 'A business fails — assets, a buyer, and a short window to act.',
                category: SignalCategory::Distress,
                icon: SignalIcon::TriangleAlert,
                queryTemplates: [
                    '{location} business liquidation',
                    '{location} firm insolvency appointed',
                    '{location} business goes into administration',
                ],
                matchKeywords: ['administration', 'insolvenc', 'liquidation', 'receiver', 'wound up'],
                includes: [
                    'a named company entering administration, liquidation or receivership',
                    'administrators or a liquidator being appointed to a named business',
                ],
                excludes: [
                    'insolvency statistics and sector failure-rate reports',
                    'insolvency-practitioner marketing and advice pages',
                    '"on the brink" speculation with no appointment',
                ],
                isStarter: false,
                pack: SignalPresetPack::MaAdvisory,
            ),
            new SignalPreset(
                key: self::MA_DIVESTMENT,
                name: 'Carve-out or divestment',
                description: 'A group sells a division — a transaction with a seller who has already decided.',
                category: SignalCategory::Liquidity,
                icon: SignalIcon::Handshake,
                queryTemplates: [
                    '{location} company sells division',
                    '{location} group carve out sale',
                    '{location} business divests unit',
                ],
                matchKeywords: ['divest', 'carve out', 'sells division', 'disposal', 'sells unit'],
                includes: [
                    'a named group selling a named subsidiary, division or brand',
                    'a carve-out or disposal agreed with a named buyer',
                ],
                excludes: [
                    'portfolio-review and strategy commentary with no sale',
                    'deal round-ups and league tables',
                    '"considering options for" stories',
                ],
                isStarter: false,
                pack: SignalPresetPack::MaAdvisory,
            ),
        ];
    }

    /**
     * The pack a preset key belongs to, or null when the catalogue no longer knows the key.
     *
     * Resolved here rather than stored on the row — see {@see SignalPresetPack} for why a
     * merchandising label has no business on a tenant's table. Null is a working state, not an
     * error: a row outlives the catalogue entry that created it, and the row is the thing a team is
     * paying to run.
     */
    public static function packFor(string $key): ?SignalPresetPack
    {
        foreach (self::all() as $preset) {
            if ($preset->key === $key) {
                return $preset->pack;
            }
        }

        return null;
    }

    /**
     * Whether the live spike found this preset thin — a real event that is simply rare.
     *
     * False for a key the catalogue does not know, which is the safe direction: an unknown row is
     * not advertised as weak.
     */
    public static function isThin(string $key): bool
    {
        foreach (self::all() as $preset) {
            if ($preset->key === $key) {
                return $preset->isThin;
            }
        }

        return false;
    }

    /**
     * The presets for one market, in declaration order.
     *
     * @return list<SignalPreset>
     */
    public static function forMarket(Market $market): array
    {
        return array_values(array_filter(
            self::all(),
            static fn (SignalPreset $preset): bool => $preset->markets === null
                || in_array($market, $preset->markets, strict: true),
        ));
    }

    /**
     * The preset keys for one market.
     *
     * @return list<string>
     */
    public static function keysForMarket(Market $market): array
    {
        return array_map(static fn (SignalPreset $preset): string => $preset->key, self::forMarket($market));
    }

    /**
     * The presets the one-click first watch starts a team with.
     *
     * Deliberately a **declared subset** rather than everything active, because the library grows
     * and the first watch must not: see {@see SignalPreset::$isStarter}. Today every preset is a
     * starter, which is why the three v1 types still behave exactly as they did.
     *
     * @return list<string>
     */
    public static function starterKeys(?Market $market = null): array
    {
        $keys = [];

        foreach ($market === null ? self::all() : self::forMarket($market) as $preset) {
            if ($preset->isStarter) {
                $keys[] = $preset->key;
            }
        }

        return $keys;
    }

    /**
     * The preset keys, for a caller that only needs the set — the admin registry's event filter,
     * for one, which is cross-tenant and so cannot ask a team what its types are.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_map(static fn (SignalPreset $preset): string => $preset->key, self::all());
    }

    /**
     * key => name, for a label map that needs no database read.
     *
     * @return array<string, string>
     */
    public static function labels(): array
    {
        $labels = [];

        foreach (self::all() as $preset) {
            $labels[$preset->key] = $preset->name;
        }

        return $labels;
    }
}
