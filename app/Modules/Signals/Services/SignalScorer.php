<?php

declare(strict_types=1);

namespace App\Modules\Signals\Services;

use App\Modules\ICP\Models\Icp;
use App\Modules\Signals\Data\SignalScore;
use App\Modules\Signals\Data\SignalScoreComponentValue;
use App\Modules\Signals\Enums\SignalScoreComponent;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Support\SignalAmountBands;
use App\Modules\Signals\Support\SignalIcpFit;
use Illuminate\Support\Str;

/**
 * How much is this event worth to **this** team? (S12)
 *
 * A composite 0–100 `heat` from eight components ({@see SignalScoreComponent}), each of which is
 * **stored beside the total** rather than folded into it. That is the phase's own risk note
 * answered: *a scorer nobody understands is worse than date order.* Every card can show its
 * breakdown, so a weight is something to argue about with evidence rather than a number somebody
 * chose once.
 *
 * **Deterministic, and there is no AI in it.** Two of the components *read* what the AI said —
 * relevance and confidence, which v1's whole judgement consisted of — but nothing here asks a
 * model anything. Given the same signal, the same team history and the same weights it returns the
 * same answer, which is what makes the explanation auditable and the unit tests meaningful.
 *
 * The arithmetic follows two rules, both taken from `Scoring\Services\DeterministicScorer` so that
 * the two scores in this product behave the same way:
 *
 * 1. **Weights are normalised, not summed.** Heat is `contributed / assessed`, so an untuned
 *    definition and one with half its weights zeroed sit on the same scale, and adding a ninth
 *    component later will not re-scale every signal in the feed.
 * 2. **Missing data is not a failure.** A component with nothing to judge — no amount in the
 *    article, no history for the outlet, no active ICP — is *unknown*, and its weight leaves
 *    **both** sides of the ratio. The alternative scores a thinly-reported event down for being
 *    thinly reported, which would make heat measure how much Alima knows rather than what the
 *    event is worth.
 *
 * It is cheap to call in a loop **as long as the instance is reused**: the amount distribution and
 * the source-authority rows are read once per team and held, like
 * {@see SignalLeadMatcher}'s lead index. Scoring fifty signals with fifty fresh scorers would be
 * fifty passes over the same two tables for answers that cannot change mid-run.
 */
final class SignalScorer
{
    /**
     * Source-authority rows by team, keyed `sourceKey|eventKey`.
     *
     * @var array<int, array<string, SignalSourceStat>>
     */
    private array $authorityByTeam = [];

    /**
     * The team's active ICP, read once. `false` distinguishes "looked and there is none" from
     * "not looked yet", which null could not.
     *
     * @var array<int, Icp|false>
     */
    private array $icpByTeam = [];

    public function __construct(
        private readonly SignalItemNormaliser $normaliser,
        private readonly SignalAmountBands $amounts,
        private readonly SignalIcpFit $icpFit,
    ) {}

    /**
     * Score one signal.
     *
     * `$cluster` is passed rather than read off the relation because the run scores a signal in the
     * same transaction that created its cluster, where the relation would be a second query for a
     * row already in hand — and because `preventLazyLoading` is on outside production, so a
     * forgotten eager load would throw rather than quietly cost a query.
     *
     * `$definition` carries the per-type weight overrides. A signal whose definition has been
     * deleted scores on the configured defaults, which is the same fallback
     * {@see SignalDefinition::effectiveThresholds()} makes for its floors.
     */
    public function score(Signal $signal, ?SignalDefinition $definition = null, ?SignalCluster $cluster = null): SignalScore
    {
        $overrides = $definition?->scoreWeights();
        $teamId = (int) $signal->team_id;

        $components = [
            $this->relevance($signal, $overrides),
            $this->confidence($signal, $overrides),
            $this->recency($signal, $overrides),
            $this->amount($signal, $teamId, $overrides),
            $this->sourceAuthority($signal, $teamId, $overrides),
            $this->seniority($signal, $overrides),
            $this->breadth($signal, $cluster, $overrides),
            $this->icp($signal, $teamId, $overrides),
        ];

        return $this->combine($components);
    }

    /**
     * Turn the weighed components into a heat, filling in each one's share of it.
     *
     * @param  list<SignalScoreComponentValue>  $components
     */
    private function combine(array $components): SignalScore
    {
        $assessed = 0;
        $points = 0.0;

        foreach ($components as $component) {
            if (! $component->isAssessed() || $component->weight === 0) {
                continue;
            }

            $assessed += $component->weight;
            $points += $component->weight * ((int) $component->value / 100);
        }

        if ($assessed === 0) {
            // Nothing could be weighed. The heat is zero *and meaningless* —
            // `SignalScore::isMeaningful()` is what a caller checks before showing it as a
            // judgement, and the feed's sort puts it with the unscored rather than with the cold.
            return new SignalScore(heat: 0, components: $components, assessed: 0);
        }

        $heat = (int) round($points / $assessed * 100);

        // The share each component contributed **of the final heat**, which is what a member reads:
        // "relevance is doing most of the work here". Rounded independently, so the shares may not
        // sum to exactly the heat — a breakdown that lied about one row to make the column add up
        // would be the worse trade.
        $withShares = array_map(
            static function (SignalScoreComponentValue $component) use ($assessed): SignalScoreComponentValue {
                if (! $component->isAssessed() || $component->weight === 0) {
                    return $component;
                }

                return $component->contributing(
                    (int) round($component->weight * ((int) $component->value / 100) / $assessed * 100),
                );
            },
            $components,
        );

        return new SignalScore(heat: max(0, min(100, $heat)), components: $withShares, assessed: $assessed);
    }

    /**
     * What the AI thought this is worth to this team. A not-null column, so always assessed.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function relevance(Signal $signal, ?array $overrides): SignalScoreComponentValue
    {
        return SignalScoreComponentValue::of(
            SignalScoreComponent::Relevance,
            $signal->relevance,
            SignalScoreComponent::Relevance->weight($overrides),
            $signal->relevance.'/100',
        );
    }

    /**
     * How sure the AI was that this is a real event of the type it named.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function confidence(Signal $signal, ?array $overrides): SignalScoreComponentValue
    {
        return SignalScoreComponentValue::of(
            SignalScoreComponent::Confidence,
            $signal->confidence,
            SignalScoreComponent::Confidence->weight($overrides),
            $signal->confidence.'/100',
        );
    }

    /**
     * Freshness, halving every `signals.scoring.recency_half_life_days`.
     *
     * An exponential rather than a cliff, because an event does not stop mattering on a particular
     * morning. Measured from **`published_at`** — when the event was reported — and not from
     * `created_at`: a watch that was paused for a fortnight and then run must not present a
     * three-week-old round as today's news, which is the mistake measuring from our own discovery
     * would make.
     *
     * A future `published_at` scores a full 100 rather than over it. Provider dates are
     * best-effort ("3 days ago", S2), and a clock skew should not invent a hotter-than-possible
     * signal.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function recency(Signal $signal, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::Recency->weight($overrides);
        $published = $signal->published_at;

        if ($published === null) {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::Recency,
                $weight,
                'No publication date.',
                translatable: true,
            );
        }

        $halfLife = max(1, (int) config('signals.scoring.recency_half_life_days', 7));

        // `$published->diffInDays(now())` counts **forwards from the article** to now, so a past
        // date is positive and a future one negative. The clamp is therefore what handles a
        // future date — it scores a full 100 rather than more than full, because provider dates
        // are best-effort ("3 days ago", S2) and a clock skew must not invent a
        // hotter-than-possible signal.
        //
        // The first version negated this and every signal scored 100 for ever, which is the kind
        // of bug a test that only checked the ordering would have missed: the *order* was right
        // because nothing moved.
        $days = max(0.0, (float) $published->diffInDays(now(), absolute: false));

        $value = (int) round(100 * (2 ** (-$days / $halfLife)));

        return SignalScoreComponentValue::of(
            SignalScoreComponent::Recency,
            $value,
            $weight,
            // The **date**, not `diffForHumans()`. The breakdown is stored, so a relative phrase is
            // frozen at scoring time: a card scored this morning would still read "2 hours ago" a
            // week later (found by `laravel-module-reviewer`). A date is true whenever it is read,
            // and the client formats it in the team's locale like every other timestamp.
            $published->toDateString(),
        );
    }

    /**
     * The deal size, as a percentile against this team's own history.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function amount(Signal $signal, int $teamId, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::Amount->weight($overrides);
        $currency = $signal->currency?->value;
        // Excluding the signal's own amount: a deal is not part of the history it is ranked against.
        $self = $signal->exists ? (int) $signal->getKey() : null;
        $percentile = $this->amounts->percentileFor($teamId, $signal->amount_minor, $currency, $self);

        if ($percentile === null) {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::Amount,
                $weight,
                // The two reasons read very differently to a member, so they are not merged: one
                // says the article was thin, the other says Alima has not seen enough yet.
                $signal->amount_minor === null
                    ? 'No amount given.'
                    : 'Not enough history to rank this against yet.',
                translatable: true,
            );
        }

        return SignalScoreComponentValue::of(
            SignalScoreComponent::Amount,
            $percentile,
            $weight,
            // Numbers rather than a sentence: the component's own `explanation()` already says
            // what the ranking means, in the member's language, so the evidence only has to carry
            // the figures. A stored English sentence would reach an Arabic card untranslated.
            $percentile.'% · '.$this->amounts->historyCount($teamId, $currency, $self),
        );
    }

    /**
     * What this team has done with this outlet's stories about this kind of event.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function sourceAuthority(Signal $signal, int $teamId, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::SourceAuthority->weight($overrides);
        $sourceKey = $this->normaliser->sourceKey($signal->source_name);

        if ($sourceKey === '') {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::SourceAuthority,
                $weight,
                'The provider named no outlet.',
                translatable: true,
            );
        }

        $stat = $this->authorityFor($teamId, $sourceKey, $signal->event_key);
        $authority = $stat?->authority();

        if ($authority === null) {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::SourceAuthority,
                $weight,
                'Not enough history with this outlet yet.',
                translatable: true,
            );
        }

        return SignalScoreComponentValue::of(
            SignalScoreComponent::SourceAuthority,
            $authority,
            $weight,
            // Figures, not prose — see the amount component for why.
            $stat->dismissed_count.'/'.$stat->observations(),
        );
    }

    /**
     * Founder / C-suite / other, from the extracted public role.
     *
     * Substring matching on a bounded vocabulary, in the same spirit as the ICP buying-signal match
     * — and with the same honest limitation: a title this list does not know scores as `other`
     * rather than as unknown, because "a role we do not recognise" is genuinely weaker evidence
     * than "the founder", not an absence of evidence. A signal naming **no** role abstains.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function seniority(Signal $signal, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::Seniority->weight($overrides);
        $role = Str::lower(Str::squish((string) $signal->subject_person_role));

        if ($role === '') {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::Seniority,
                $weight,
                'The article named no role.',
                translatable: true,
            );
        }

        // **Word boundaries, not substrings**, which is the same correction
        // `Scoring\Services\DeterministicScorer` makes for its short criterion values. The first
        // version matched `partner` as a substring and read "Head of Partnerships" as a partner —
        // a false positive that would have quietly promoted every partnerships manager in the
        // feed above the directors.
        //
        // Owner-level first: a founder who just sold is the person this product exists to find.
        // **The deputy tier is tested first**, and that ordering is the fix for a real
        // mis-tiering: `president` matches "Vice President" as a whole word, so a VP scored 85 —
        // the chief-executive tier — and the `vice president` entry below was unreachable. Word
        // boundaries fixed the "Partnerships" false positive and did nothing about this one
        // (found by `laravel-module-reviewer`).
        $value = match (true) {
            $this->roleMentions($role, ['(vice|deputy|assistant) .*', 's?vp', 'evp', 'avp']) => 60,
            $this->roleMentions($role, ['founders?', 'owners?', 'proprietors?', 'chair(man|woman|person)?']) => 100,
            $this->roleMentions($role, ['ceo', 'chief .*', 'c[feoitm]o', 'managing director', 'president', 'partner']) => 85,
            $this->roleMentions($role, ['directors?', 'heads?', 'principal']) => 60,
            default => 30,
        };

        return SignalScoreComponentValue::of(
            SignalScoreComponent::Seniority,
            $value,
            $weight,
            Str::limit((string) $signal->subject_person_role, 80),
        );
    }

    /**
     * Whether a role mentions any of these, as whole words.
     *
     * The patterns are our own closed vocabulary, so they go into the regex as written; nothing a
     * provider or a model produced reaches it. `\b` on both ends is what makes "partner" not match
     * "partnerships" and "head" not match "headcount".
     *
     * @param  list<string>  $patterns
     */
    private function roleMentions(string $role, array $patterns): bool
    {
        return preg_match('/\b('.implode('|', $patterns).')\b/u', $role) === 1;
    }

    /**
     * How widely the event was reported (S11's cluster).
     *
     * Saturating rather than linear: the difference between one outlet and six says something
     * about whether the event is real, and the difference between six and sixteen says something
     * about syndication. A signal with no cluster is **one** source, which is a judgement and not
     * an absence — so this component does not abstain for an unclustered signal.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function breadth(Signal $signal, ?SignalCluster $cluster, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::ClusterBreadth->weight($overrides);
        $sources = $cluster instanceof SignalCluster ? max(1, $cluster->sources_count) : 1;
        $saturation = max(1, (int) config('signals.scoring.breadth_saturation', 6));

        $value = (int) round(min(1.0, $sources / $saturation) * 100);

        return SignalScoreComponentValue::of(
            SignalScoreComponent::ClusterBreadth,
            $value,
            $weight,
            // Count-neutral: Arabic needs dual and plural forms that ":count outlets" cannot give,
            // so the figure stands alone and the component's explanation carries the words.
            (string) $sources,
        );
    }

    /**
     * Fit against the team's active ICP, through `Scoring`'s own criteria match.
     *
     * @param  array<string, mixed>|null  $overrides
     */
    private function icp(Signal $signal, int $teamId, ?array $overrides): SignalScoreComponentValue
    {
        $weight = SignalScoreComponent::IcpFit->weight($overrides);
        $icp = $this->icpFor($teamId);
        $fit = $this->icpFit->score($signal, $icp instanceof Icp ? $icp : null);

        if ($fit === null) {
            return SignalScoreComponentValue::unknown(
                SignalScoreComponent::IcpFit,
                $weight,
                $icp instanceof Icp
                    ? 'Your profile has nothing to judge this event by.'
                    : 'No active ideal customer profile.',
                translatable: true,
            );
        }

        return SignalScoreComponentValue::of(
            SignalScoreComponent::IcpFit,
            $fit,
            $weight,
            $icp instanceof Icp ? Str::limit($icp->name, 80) : null,
        );
    }

    /**
     * This team's source-authority rows, read once.
     *
     * Scoped twice — the global scope and an explicit `team_id` — because this runs from a queued
     * run with no request behind it, and another tenant's reputation for an outlet is not evidence
     * about this one.
     */
    private function authorityFor(int $teamId, string $sourceKey, string $eventKey): ?SignalSourceStat
    {
        if (! isset($this->authorityByTeam[$teamId])) {
            $rows = [];

            foreach (SignalSourceStat::query()->where('team_id', $teamId)->get() as $stat) {
                $rows[$stat->source_key.'|'.$stat->event_key] = $stat;
            }

            $this->authorityByTeam[$teamId] = $rows;
        }

        return $this->authorityByTeam[$teamId][$sourceKey.'|'.$eventKey] ?? null;
    }

    /**
     * The team's active ICP, read once. The same lookup `RunSignalWatchAction` makes, kept here so
     * a caller scoring one signal does not have to know to pass it.
     */
    private function icpFor(int $teamId): Icp|false
    {
        if (isset($this->icpByTeam[$teamId])) {
            return $this->icpByTeam[$teamId];
        }

        $icp = Icp::query()
            ->where('team_id', $teamId)
            ->where('is_active', true)
            ->with('criteria')
            ->orderByDesc('id')
            ->first();

        return $this->icpByTeam[$teamId] = $icp ?? false;
    }
}
