<?php

declare(strict_types=1);

namespace App\Modules\Signals\Support;

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Data\SignalQueryPlan;
use App\Modules\Signals\Data\SignalQuerySpec;
use App\Modules\Signals\Enums\SignalCategory;
use App\Modules\Signals\Enums\SignalIcon;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;

/**
 * What a signal type would cost, computed from the team's actual watches (S9).
 *
 * **A standing instruction to spend money should say how much, where it is created.** Every template
 * becomes a paid search on every run of every watch that uses the type, multiplied by the places
 * those watches cover (ADR 0019 §4).
 *
 * **It asks the query builder rather than doing the arithmetic**, which is the correction the S9
 * review forced. Multiplying regions × places × templates looked equivalent and was not: it ignored
 * `signals.max_queries_per_run`, so a watch already at its ceiling was quoted for searches the run
 * would never make; it ignored the de-duplication of identical queries; and it could not know that
 * a non-English region swaps in a different number of translated templates. Building a real plan
 * from a stub definition costs nothing and cannot drift from what a run asks for, because it **is**
 * what a run asks for.
 *
 * Two honest limits remain, and the form says both in words. It is the **search** cost only — the AI
 * pass is per batch of articles rather than per query, so it does not scale with the thing the
 * member is editing, and a guess at it would make the number less true rather than more complete.
 * And it is an **upper bound across the team's active watches**: a new type runs on a watch only
 * once somebody attaches it, so this answers "if you add it to all of them".
 */
final class SignalCostEstimator
{
    /** A month, for an estimate. Nobody needs 30.44 here, and a round number reads as an estimate. */
    private const int DAYS_PER_MONTH = 30;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly SignalQueryBuilder $queries,
    ) {}

    /**
     * The monthly cost of a definition with this many templates **on these sources**, for this team.
     *
     * The sources joined the signature at S13 and they are a multiplier, not a detail: one template
     * on two sources is two paid searches per run (ADR 0023, `SignalQueryBuilder::push()`). A form
     * that let somebody tick "web search" without the number moving would be the one place in this
     * feature that hid a doubling of cost.
     *
     * @param  list<SignalSourceKind>  $sourceKinds  Empty means news, which is what a type starts on.
     */
    public function estimate(Team $team, int $templates, array $sourceKinds = []): SignalCostEstimate
    {
        $templates = max(0, $templates);
        $stub = $this->stub($templates, $sourceKinds);

        /** @var list<SignalWatch> $watches */
        $watches = $this->teamContext->run($team, fn (): array => SignalWatch::query()
            ->where('is_active', true)
            ->get()
            ->all());

        $hypothetical = $watches === [];
        $perRun = 0;
        $perMonth = 0;
        $runsPerMonth = 0;
        $costPerMonth = 0;

        if ($hypothetical) {
            // No watch yet — which is the normal case for somebody building their first type, and
            // the moment the number is most worth showing. Costed as one daily watch over the
            // team's own market defaults, which is exactly what the one-click first watch creates.
            $plan = $templates === 0
                ? null
                : $this->queries->buildFromSpec(SignalQuerySpec::forDraft($team, [], []), [$stub]);
            $perRun = $plan?->count() ?? 0;
            $runsPerMonth = $this->runsPerMonth(SignalWatchCadence::Daily);
            $perMonth = $perRun * $runsPerMonth;
            $costPerMonth = $this->costOf($plan) * $runsPerMonth;
        } else {
            foreach ($watches as $watch) {
                $plan = $templates === 0
                    ? null
                    : $this->queries->buildFromSpec(SignalQuerySpec::forWatch($watch), [$stub]);

                $queries = $plan?->count() ?? 0;
                $runs = $this->runsPerMonth($watch->cadence);

                $perRun += $queries;
                $perMonth += $queries * $runs;
                $runsPerMonth += $runs;
                $costPerMonth += $this->costOf($plan) * $runs;
            }
        }

        return new SignalCostEstimate(
            templates: $templates,
            queriesPerRun: $perRun,
            runsPerMonth: $runsPerMonth,
            queriesPerMonth: $perMonth,
            costMicrosPerMonth: $costPerMonth,
            watchCount: $hypothetical ? 1 : count($watches),
            hypothetical: $hypothetical,
        );
    }

    /**
     * What one run of a plan would cost, summed **per query at its own source's price**.
     *
     * Not `count() * config('signals.cost_micros_per_request')`, which is what this was until S13:
     * sources are priced separately (`signals.sources.*.cost_micros_per_request`) and one of the
     * declared ones is free, so a flat multiplier would quote a free source as though it were
     * Serper. Reading each query's own kind cannot drift from what the run will be charged, for the
     * same reason the query count is taken from a real plan rather than multiplied out.
     */
    private function costOf(?SignalQueryPlan $plan): int
    {
        if (! $plan instanceof SignalQueryPlan) {
            return 0;
        }

        $cost = 0;

        foreach ($plan->queries as $query) {
            $cost += $query->kind->costMicrosPerRequest();
        }

        return $cost;
    }

    /**
     * A definition that exists only to be costed: N templates and nothing else.
     *
     * Unsaved, like the preview's draft — the builder has not needed a persisted row since
     * `SignalQuerySpec` was extracted, which is what makes this possible at all. The templates are
     * distinct so the builder's de-duplication does not collapse them, and each carries
     * `{location}` so the count reflects a real per-place multiplication.
     *
     * `source_keys` is set from the caller's choice (S13), because the builder emits one query per
     * source and the whole point of this class is that the arithmetic is the builder's rather than
     * ours.
     *
     * @param  list<SignalSourceKind>  $sourceKinds
     */
    private function stub(int $templates, array $sourceKinds = []): SignalDefinition
    {
        $definition = new SignalDefinition;
        $definition->forceFill([
            'key' => 'cost_estimate',
            'name' => 'Cost estimate',
            'description' => 'Not a real signal type.',
            'category' => SignalCategory::Custom,
            'icon' => SignalIcon::Satellite,
            'is_active' => true,
            'query_templates' => array_map(
                static fn (int $index): string => '{location} estimate '.$index,
                range(1, max($templates, 1)),
            ),
            'match_keywords' => [],
            'classification_rules' => ['includes' => [], 'excludes' => []],
            'thresholds' => ['min_confidence' => null, 'min_relevance' => null],
            'markets' => null,
            // Empty falls back to news in `SignalSourceKind::parseList()`, which is what a type
            // starts on and therefore the right answer for a caller that does not care.
            'source_keys' => array_map(static fn (SignalSourceKind $kind): string => $kind->value, $sourceKinds),
            'source_config' => null,
            'version' => 1,
        ]);

        return $definition;
    }

    /**
     * How often a cadence runs in a month, from the interval the scheduler actually uses.
     *
     * Derived from `staleAfterHours()` rather than hard-coded as 30 and 4, so a third cadence added
     * later is costed correctly without anybody remembering this file exists.
     */
    private function runsPerMonth(SignalWatchCadence $cadence): int
    {
        return max(1, (int) round(self::DAYS_PER_MONTH * 24 / $cadence->staleAfterHours()));
    }
}
