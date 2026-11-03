<?php

declare(strict_types=1);

namespace App\Modules\Signals\Enums;

use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Services\SignalScorer;

/**
 * The eight things {@see SignalScorer} weighs to reach a signal's heat (S12).
 *
 * **An enum, and the application branches on every case** — each one is computed from a different
 * field by a different rule, which is ADR 0018 §1's test for an enum rather than a row. The
 * *weights* are data (`config('signals.scoring.weights')`, overridable per definition); the set of
 * questions is not.
 *
 * Each case carries its own label and the one-line explanation the card's "why you're seeing this"
 * panel shows, as translation keys, because that panel is the phase's real deliverable: a score
 * nobody can read is worse than date order.
 */
enum SignalScoreComponent: string
{
    /** What the AI thought this event is worth to this team — the only component that read the article. */
    case Relevance = 'relevance';

    /** How sure the AI was that this is a real, correctly-classified event. */
    case Confidence = 'confidence';

    /** Freshness, halving on `signals.scoring.recency_half_life_days`. */
    case Recency = 'recency';

    /** The deal size, ranked against **this team's own** history rather than an absolute. */
    case Amount = 'amount';

    /** What this team's own behaviour says about the outlet, for this type of event. */
    case SourceAuthority = 'source_authority';

    /** Founder / C-suite / other, from the extracted public role. */
    case Seniority = 'seniority';

    /** How many outlets reported it ({@see SignalCluster::$sources_count}). */
    case ClusterBreadth = 'cluster_breadth';

    /** Fit against the team's active ICP, through `Scoring`'s own criteria match. */
    case IcpFit = 'icp_fit';

    /** A translation key naming the component on the card. */
    public function label(): string
    {
        return match ($this) {
            self::Relevance => 'Relevance to you',
            self::Confidence => 'Confidence it is real',
            self::Recency => 'How recent',
            self::Amount => 'Deal size',
            self::SourceAuthority => 'This source’s track record with you',
            self::Seniority => 'Who it is about',
            self::ClusterBreadth => 'How widely reported',
            self::IcpFit => 'Fit with your ideal customer',
        };
    }

    /**
     * A translation key saying what the component measures, shown beside its value.
     *
     * Deliberately phrased as what it is **for**, not as the formula: a member reading the panel is
     * deciding whether to trust the ranking, and "ranked against the deal sizes you have seen"
     * answers that where "percentile rank of amount_minor" does not.
     */
    public function explanation(): string
    {
        return match ($this) {
            self::Relevance => 'How well this event matches what you told Alima you are looking for.',
            self::Confidence => 'How certain Alima is that this really happened and is the kind of event you asked for.',
            self::Recency => 'Trigger events go cold. This halves every week.',
            self::Amount => 'Ranked against the deal sizes you have seen, not against a fixed number.',
            self::SourceAuthority => 'Built from what you have done with this outlet’s stories before — kept or dismissed.',
            self::Seniority => 'An event about a founder or a chief executive is worth more than one about a department.',
            self::ClusterBreadth => 'A story several outlets carried is likelier to be real than one only an aggregator ran.',
            self::IcpFit => 'The same criteria your lead scores use, applied to what this event is about.',
        };
    }

    /**
     * The configured weight for this component, with a definition's own override taking precedence.
     *
     * Clamped at zero: a negative weight would make the arithmetic mean nothing, and a component
     * somebody wants to switch off is one set to zero.
     *
     * @param  array<string, mixed>|null  $overrides  `signal_definitions.score_weights`
     */
    public function weight(?array $overrides = null): int
    {
        $override = $overrides[$this->value] ?? null;

        if (is_numeric($override)) {
            return max(0, (int) $override);
        }

        /** @var array<string, mixed> $configured */
        $configured = (array) config('signals.scoring.weights', []);
        $weight = $configured[$this->value] ?? 0;

        return max(0, is_numeric($weight) ? (int) $weight : 0);
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $component): string => $component->value, self::cases());
    }
}
