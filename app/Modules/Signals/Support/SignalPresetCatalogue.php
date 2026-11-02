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
            ),
        ];
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
