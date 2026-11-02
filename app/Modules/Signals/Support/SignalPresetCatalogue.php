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
 * The three presets here are v1's `SignalEventType` cases, carried over **unchanged**: the same
 * templates (from `config/signals.php`, loosened to plain keywords by the S0 spike — exact phrases
 * and `-based` returned zero results on `/news`), the same descriptions (from the enum), the same
 * ICP keyword map (from `SignalQueryBuilder::KEYWORDS`), and null thresholds so the global
 * `signals.min_confidence` / `signals.min_relevance` still apply. S7 changes where a type lives,
 * not what it finds.
 */
final class SignalPresetCatalogue
{
    public const string ACQUISITION = 'acquisition';

    public const string FUNDING_ROUND = 'funding_round';

    public const string RELOCATION = 'relocation';

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
     * The presets the one-click first watch starts a team with.
     *
     * Deliberately a **declared subset** rather than everything active, because the library grows
     * and the first watch must not: see {@see SignalPreset::$isStarter}. Today every preset is a
     * starter, which is why the three v1 types still behave exactly as they did.
     *
     * @return list<string>
     */
    public static function starterKeys(): array
    {
        $keys = [];

        foreach (self::all() as $preset) {
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
