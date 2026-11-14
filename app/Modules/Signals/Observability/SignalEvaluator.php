<?php

declare(strict_types=1);

namespace App\Modules\Signals\Observability;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpCriterion;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Signals\Actions\DraftSignalSuggestionsAction;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Actions\TestSignalSuggestionAction;
use App\Modules\Signals\Data\DraftSignalDefinition;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\SignalSuggestionStatus;
use App\Modules\Signals\Enums\SignalWatchCadence;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalDefinitionSpend;
use App\Modules\Signals\Models\SignalRejection;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalSourceStat;
use App\Modules\Signals\Models\SignalSuggestionBatch;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Providers\SignalsServiceProvider;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPreset;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Runs one fixture business through the real signal run and measures what reaches the feed
 * (`signals:eval`, docs/plans/signals-v3-plan.md §2).
 *
 * Under `Observability/` by ADR 0002's test: delete it and the product still works.
 *
 * Two measures, kept apart because they fail differently:
 *
 * - **Labelled** — the fixture's hand-labelled articles served by a fake news source into the real
 *   run: classification, floors, dedupe, clustering, scoring. The input is fixed, so this is the
 *   figure that can be compared between runs.
 * - **Live** — the same types run against the real sources, and what reaches the feed graded by
 *   `signals.eval_judge` against a ground-truth description the pipeline never sees. The judge is
 *   itself scored against the labels.
 *
 * Unlike the relevance harness this one **does** persist: dedupe, clustering and scoring happen as
 * signals are written, so there is no stopping short of the table. Everything it writes belongs to
 * its own `Signals eval · <fixture>` team, which has no members, and is cleared before each pass.
 */
final class SignalEvaluator
{
    public const string JUDGE_PROMPT = 'signals.eval_judge';

    /** What each label should be judged as. A reprint of a good event is still a good event. */
    private const array EXPECTED = [
        'good' => 'good',
        'duplicate' => 'good',
        'misfit' => 'wrong_company',
        'competitor' => 'wrong_company',
        'not_event' => 'not_event',
        'stale' => 'stale',
    ];

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AiClient $ai,
        private readonly SignalSourceRegistry $sources,
        private readonly RunSignalWatchAction $run,
        private readonly DraftSignalSuggestionsAction $draftSuggestions,
        private readonly TestSignalSuggestionAction $testSuggestion,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture  A decoded `tests/Fixtures/relevance/*.json` with a `signals` block.
     * @param  int  $n  How many of the live feed's top cards are judged.
     * @return array<string, mixed>
     */
    public function evaluate(array $fixture, int $n, bool $live = true, bool $suggested = false, bool $research = false): array
    {
        $key = (string) $fixture['key'];
        /** @var array<string, mixed> $profileData */
        $profileData = $fixture['profile'];

        $team = Team::query()->firstOrCreate(
            ['name' => 'Signals eval · '.$key],
            ['timezone' => 'UTC', 'locale' => 'en', 'market' => (string) $profileData['primary_market']],
        );

        // Team names are not unique, and what follows deletes the team's signals, watches and
        // ICPs. An eval team has no members; anything else carrying this name is somebody's.
        if ($team->users()->exists()) {
            throw new RuntimeException(sprintf('A team named "%s" has members, so it is not an evaluation team. Refusing to touch it.', $team->name));
        }

        return $this->teamContext->run($team, function () use ($team, $fixture, $profileData, $key, $n, $live, $suggested, $research): array {
            $teamId = (int) $team->getKey();
            $ledgerMark = (int) AiRequest::query()->max('id');
            /** @var array<string, mixed> $signals */
            $signals = $fixture['signals'];

            $icp = $this->prepare($teamId, $profileData, $signals);
            $definitions = $this->definitions($teamId, (array) $signals['presets']);
            $market = Market::from((string) $profileData['primary_market']);

            try {
                $labelled = $this->labelled($teamId, $market, $icp, $definitions, $fixture);
                $liveResult = $live ? $this->live($teamId, $market, $icp, $definitions, $fixture, $n) : null;
                $researchResult = $research ? $this->researched($teamId, $market, $icp, $definitions, $fixture, $n) : null;
                $suggestedResult = $suggested ? $this->suggested($team, $market, $icp, $fixture, $n) : null;
            } finally {
                // `signals:dispatch-due` runs every active watch that is due. One left active here
                // would be run again tomorrow against the live sources, for a team nobody bills.
                // The rows stay, so a result can still be inspected.
                SignalWatch::query()->where('team_id', $teamId)->update(['is_active' => false]);
            }

            $spend = AiRequest::query()->where('team_id', $teamId)->where('id', '>', $ledgerMark)->get();

            return [
                'fixture' => $key,
                'labelled' => $labelled,
                'live' => $liveResult,
                'suggested' => $suggestedResult,
                'research' => $researchResult,
                'pipeline_ai_cost_micros' => (int) $spend->where('prompt_key', '!=', self::JUDGE_PROMPT)->sum('cost_micros'),
                'judge_ai_cost_micros' => (int) $spend->where('prompt_key', self::JUDGE_PROMPT)->sum('cost_micros'),
            ];
        });
    }

    /**
     * The team's profile and one active ICP, both rewritten from the fixture.
     *
     * @param  array<string, mixed>  $profileData
     * @param  array<string, mixed>  $signals
     */
    private function prepare(int $teamId, array $profileData, array $signals): Icp
    {
        BusinessProfile::query()->where('team_id', $teamId)->delete();
        // With what an accepted background check adds: how far the seller travels, how big its
        // customers are, who is not one. A real account has these, and the classifier reads them.
        BusinessProfile::query()->create(['team_id' => $teamId, ...$profileData, ...(array) ($signals['profile_after_check'] ?? [])])
            ->forceFill(['completed_at' => now()])->save();

        Icp::query()->where('team_id', $teamId)->get()->each->delete();
        $icp = Icp::query()->create(['team_id' => $teamId, 'name' => 'Signals eval', 'is_active' => true]);

        foreach (array_values((array) $signals['icp']) as $position => $criterion) {
            IcpCriterion::query()->create([
                'team_id' => $teamId,
                'icp_id' => $icp->getKey(),
                'type' => $criterion['type'],
                'value' => $criterion['value'],
                'weight' => $criterion['weight'],
                'position' => $position,
            ]);
        }

        return $icp;
    }

    /**
     * The team's own copies of the named library types, made once and kept.
     *
     * From the catalogue rather than from the seeded preset rows, so the harness measures the
     * library this build ships and does not depend on a seeder having run.
     *
     * @param  array<array-key, mixed>  $keys
     * @return list<SignalDefinition>
     */
    private function definitions(int $teamId, array $keys): array
    {
        $definitions = [];

        foreach ($keys as $key) {
            $existing = SignalDefinition::query()->where('team_id', $teamId)->where('key', (string) $key)->first();

            if (! $existing instanceof SignalDefinition) {
                $preset = collect(SignalPresetCatalogue::all())
                    ->firstOrFail(static fn (SignalPreset $candidate): bool => $candidate->key === $key);

                $existing = SignalDefinition::query()->create([...$preset->toRow(), 'team_id' => $teamId]);
            }

            $definitions[] = $existing;
        }

        return $definitions;
    }

    /**
     * The hand-labelled articles through the real run, with the news source replaced by a fake.
     *
     * @param  list<SignalDefinition>  $definitions
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function labelled(int $teamId, Market $market, Icp $icp, array $definitions, array $fixture): array
    {
        /** @var list<array{id: int, title: string, snippet: string, source: string, days_ago: int, label: string, url: string, of?: int}> $articles */
        $articles = $fixture['signals']['articles'];

        $fake = new FakeSignalSource;
        $byHash = [];
        $items = [];

        foreach ($articles as $article) {
            $item = $fake->item(
                title: $article['title'],
                link: $article['url'],
                snippet: $article['snippet'],
                source: $article['source'],
                publishedAt: Carbon::now()->subDays($article['days_ago'])->utc(),
            );
            $items[] = $item;
            $byHash[$item->urlHash] = $article['id'];
        }

        $this->reset($teamId);
        // Throws when no news source is registered: there would be nothing to put back, and the
        // fake would then serve the live pass too.
        $real = $this->sources->for(SignalSourceKind::News);
        $this->sources->register($fake->willReturn($items));

        try {
            $run = $this->runWatch($teamId, $market, $icp, $definitions, (array) $fixture['signals']['locations']);
        } finally {
            $this->sources->register($real);
        }

        if ($run->status !== SignalRunStatus::Completed) {
            return ['error' => $run->error_code ?? $run->error ?? $run->status->value];
        }

        $stored = $this->articleIds(Signal::query()->where('team_id', $teamId)->get()->all(), $byHash);
        $shown = $this->articleIds(Signal::query()->where('team_id', $teamId)->representatives()->get()->all(), $byHash);
        // Every card, including one whose link no longer matches an article. Zero is the expected
        // difference; anything else is the harness failing to recognise its own input.
        $cards = Signal::query()->where('team_id', $teamId)->representatives()->count();

        $totals = array_count_values(array_column($articles, 'label'));
        $shownByLabel = array_fill_keys(array_keys(self::EXPECTED), 0);
        $extraCards = 0;
        $standIns = [];

        foreach ($articles as $article) {
            if (! isset($shown[$article['id']])) {
                continue;
            }

            $shownByLabel[$article['label']]++;

            if ($article['label'] === 'duplicate') {
                // A reprint on its own card beside the original, or beside another reprint of
                // it, is the failure. The first reprint standing in for an original that was not
                // shown is the event's one card, which is fine.
                $of = (int) ($article['of'] ?? -1);
                isset($shown[$of]) || isset($standIns[$of]) ? $extraCards++ : $standIns[$of] = true;
            }
        }

        $goodKept = count(array_filter(
            $articles,
            static fn (array $a): bool => $a['label'] === 'good' && (isset($shown[$a['id']]) || isset($standIns[$a['id']])),
        ));
        $goodCards = $shownByLabel['good'] + count($standIns);

        // Null, not zero, for a label the fixture does not carry: nothing was measured.
        $rate = static fn (string $label): ?float => isset($totals[$label]) ? $shownByLabel[$label] / $totals[$label] : null;

        $verdicts = $this->judge($fixture, array_map(
            static fn (array $a): string => implode(' | ', [
                $a['title'], $a['snippet'], $a['source'], 'published '.Carbon::now()->subDays($a['days_ago'])->toDateString(),
            ]),
            $articles,
        ));

        $agreed = 0;

        foreach ($articles as $i => $article) {
            $agreed += (int) (self::EXPECTED[$article['label']] === $verdicts[$i]);
        }

        return [
            'cards' => $cards,
            'unmatched_cards' => $cards - count($shown),
            'feed_precision' => $cards === 0 ? null : $goodCards / $cards,
            'good_loss' => isset($totals['good']) ? 1 - $goodKept / $totals['good'] : null,
            'misfit_leak' => $rate('misfit'),
            'competitor_leak' => $rate('competitor'),
            'not_event_leak' => $rate('not_event'),
            'stale_leak' => $rate('stale'),
            'duplicate_rate' => isset($totals['duplicate']) ? $extraCards / $totals['duplicate'] : null,
            'judge_agreement' => $agreed / max(1, count($articles)),
            'articles' => array_map(static fn (array $a, string $verdict): array => [
                'id' => $a['id'],
                'label' => $a['label'],
                'outcome' => match (true) {
                    isset($shown[$a['id']]) => 'shown',
                    isset($stored[$a['id']]) => 'clustered',
                    default => 'dropped',
                },
                'judge' => $verdict,
            ], $articles, $verdicts),
        ];
    }

    /**
     * The same types against the real sources, and the top of the resulting feed judged.
     *
     * @param  list<SignalDefinition>  $definitions
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function live(int $teamId, Market $market, Icp $icp, array $definitions, array $fixture, int $n, ?string $watching = null): array
    {
        $this->reset($teamId);
        $run = $this->runWatch($teamId, $market, $icp, $definitions, (array) $fixture['signals']['locations']);

        $result = [
            'status' => $run->status->value,
            'error' => $run->error_code ?? $run->error,
            'queries' => (int) $run->queries_count,
            'paid_queries' => (int) $run->paid_queries_count,
            'results' => (int) $run->results_count,
            'source_cost_micros' => (int) $run->cost_micros,
        ];

        /** @var list<Signal> $feed */
        $feed = Signal::query()->where('team_id', $teamId)->representatives()->get()
            // Ranked as the feed ranks it, an unscored card last.
            ->sortByDesc(static fn (Signal $signal): int => $signal->heat ?? -1)
            ->take($n)->values()->all();

        $verdicts = $this->judge($fixture, array_map(
            static fn (Signal $s): string => implode(' | ', array_filter([
                $s->headline, $s->summary, $s->subject_company_name, $s->source_name,
                $s->published_at === null ? 'no date' : 'published '.$s->published_at->toDateString(),
            ])),
            $feed,
        ), $watching);

        $bySource = [];

        foreach ($feed as $i => $signal) {
            $kind = $signal->source_kind->value;
            $bySource[$kind]['shown'] = ($bySource[$kind]['shown'] ?? 0) + 1;
            $bySource[$kind]['good'] = ($bySource[$kind]['good'] ?? 0) + (int) ($verdicts[$i] === 'good');
        }

        return [
            ...$result,
            'cards' => Signal::query()->where('team_id', $teamId)->representatives()->count(),
            'judged' => count($feed),
            'precision' => $feed === [] ? null : count(array_keys($verdicts, 'good', true)) / count($feed),
            'verdicts' => array_count_values($verdicts),
            'by_source' => $bySource,
            'signals' => array_map(static fn (Signal $s, string $verdict): array => [
                'headline' => $s->headline,
                'company' => $s->subject_company_name,
                'source' => $s->source_name,
                'event' => $s->event_key,
                'heat' => $s->heat,
                'verdict' => $verdict,
            ], $feed, $verdicts),
        ];
    }

    /**
     * The live pass again, with the types Alima suggests for this business in place of the
     * fixture's library types (Signals v3 P2). The suggestions come from the product's own
     * actions, run inline rather than queued, and nothing but the eval team is written to.
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function suggested(Team $team, Market $market, Icp $icp, array $fixture, int $n): array
    {
        $teamId = (int) $team->getKey();

        // Last pass's drafted types and batches, so "already has it" does not shape this one.
        SignalSuggestionBatch::query()->where('team_id', $teamId)->delete();
        SignalDefinition::query()->withTrashed()->where('team_id', $teamId)
            ->whereNotIn('key', (array) $fixture['signals']['presets'])->forceDelete();

        $batch = SignalSuggestionBatch::query()->create(['team_id' => $teamId, 'status' => SignalSuggestionStatus::Pending]);
        $batch = $this->draftSuggestions->handle($batch, queueTests: false);

        foreach (array_keys($batch->suggestions ?? []) as $index) {
            $this->testSuggestion->handle($batch->refresh(), $index);
        }

        $rows = $batch->refresh()->suggestions ?? [];
        $working = array_values(array_filter($rows, static fn (array $row): bool => ($row['preview']['kept'] ?? 0) > 0));
        $definitions = [];

        // What a member would add: the ones a sample search found something for.
        foreach ($working as $row) {
            if (is_string($row['preset_key'] ?? null)) {
                $definitions = [...$definitions, ...$this->definitions($teamId, [$row['preset_key']])];

                continue;
            }

            $draft = DraftSignalDefinition::fromAi(['is_viable' => true, 'definition' => $row['definition']]);

            if ($draft->viable) {
                $model = $draft->toUnsavedDefinition();
                $model->forceFill(['team_id' => $teamId])->save();
                $definitions[] = $model;
            }
        }

        $names = array_map(static fn (SignalDefinition $d): string => $d->name.' ('.$d->description.')', $definitions);

        return [
            'cost_micros' => (int) $batch->cost_micros,
            'suggestions' => array_map(static fn (array $row): array => [
                'title' => $row['title'],
                'rationale' => $row['rationale'],
                'preset_key' => $row['preset_key'] ?? null,
                'templates' => $row['definition']['query_templates'] ?? null,
                'state' => $row['state'],
                'preview' => $row['preview'],
            ], $rows),
            'live' => $definitions === []
                ? null
                : $this->live($teamId, $market, $icp, $definitions, $fixture, $n, implode('; ', $names)),
        ];
    }

    /**
     * The live pass once more, with AI research switched on for every library type and asked
     * whatever the other sources found (Signals v3 P4). The product only calls it where they
     * found little; here the question is what it adds, so it is always asked.
     *
     * @param  list<SignalDefinition>  $definitions
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function researched(int $teamId, Market $market, Icp $icp, array $definitions, array $fixture, int $n): array
    {
        $before = [
            'signals.sources.ai_research.enabled' => config('signals.sources.ai_research.enabled'),
            'signals.sources.ai_research.min_results' => config('signals.sources.ai_research.min_results'),
            'signals.sources.ai_research.max_calls_per_run' => config('signals.sources.ai_research.max_calls_per_run'),
        ];

        config([
            'signals.sources.ai_research.enabled' => true,
            'signals.sources.ai_research.min_results' => PHP_INT_MAX,
            'signals.sources.ai_research.max_calls_per_run' => count($definitions),
        ]);
        $this->sources->register(SignalsServiceProvider::aiResearchSource(app()));

        $ids = array_map(static fn (SignalDefinition $d): int => (int) $d->getKey(), $definitions);
        SignalDefinition::query()->where('team_id', $teamId)->whereIn('id', $ids)
            ->update(['source_keys' => [SignalSourceKind::News->value, SignalSourceKind::AiResearch->value]]);

        try {
            $fresh = SignalDefinition::query()->where('team_id', $teamId)->whereIn('id', $ids)->get()->all();

            return $this->live($teamId, $market, $icp, array_values($fresh), $fixture, $n);
        } finally {
            SignalDefinition::query()->where('team_id', $teamId)->whereIn('id', $ids)
                ->update(['source_keys' => [SignalSourceKind::News->value]]);
            config($before);
        }
    }

    /**
     * @param  list<SignalDefinition>  $definitions
     * @param  array<array-key, mixed>  $locations
     */
    private function runWatch(int $teamId, Market $market, Icp $icp, array $definitions, array $locations): SignalRun
    {
        $watch = SignalWatch::query()->create([
            'team_id' => $teamId,
            'icp_id' => $icp->getKey(),
            'name' => 'Signals eval',
            'market' => $market,
            'locations' => array_values($locations),
            'regions' => [SearchRegion::forMarket($market)->value],
            'cadence' => SignalWatchCadence::Daily,
            'is_active' => true,
        ]);

        $watch->definitions()->sync(array_fill_keys(
            array_map(static fn (SignalDefinition $d): int => (int) $d->getKey(), $definitions),
            ['team_id' => $teamId],
        ));

        return $this->run->handle($watch);
    }

    /**
     * Everything a previous pass left behind. The rejection ledger in particular: it remembers a
     * "no" for thirty days, and a pass that inherited one would be measuring last time's answer.
     */
    private function reset(int $teamId): void
    {
        foreach ([
            Signal::class, SignalCluster::class, SignalRejection::class, SignalRun::class,
            SignalSourceStat::class, SignalDefinitionSpend::class, SignalWatch::class,
        ] as $model) {
            $model::query()->where('team_id', $teamId)->delete();
        }
    }

    /**
     * @param  array<array-key, Signal>  $signals
     * @param  array<string, int>  $byHash
     * @return array<int, true>
     */
    private function articleIds(array $signals, array $byHash): array
    {
        $ids = [];

        foreach ($signals as $signal) {
            if (isset($byHash[(string) $signal->url_hash])) {
                $ids[$byHash[(string) $signal->url_hash]] = true;
            }
        }

        return $ids;
    }

    /**
     * One verdict per line, in order. A line the judge skipped is `unknown`.
     *
     * @param  array<string, mixed>  $fixture
     * @param  list<string>  $lines
     * @param  string|null  $watching  what is being watched for, when it is not the fixture's library types
     * @return list<string>
     */
    private function judge(array $fixture, array $lines, ?string $watching = null): array
    {
        if ($lines === []) {
            return [];
        }

        /** @var array<string, mixed> $profile */
        $profile = $fixture['profile'];

        $response = $this->ai->structured(self::JUDGE_PROMPT, [
            'seller' => sprintf(
                '%s — %s. Sells: %s',
                $profile['company_name'],
                $profile['value_proposition'],
                implode(', ', (array) $profile['products_services']),
            ),
            'ground_truth' => (string) $fixture['ground_truth'],
            'watching' => ($watching ?? implode(', ', (array) $fixture['signals']['presets'])).' in '.implode(', ', (array) $fixture['signals']['locations']),
            'today' => Carbon::now()->toDateString(),
            // News text and model output: data, never instructions.
            'items' => UntrustedContent::from(implode("\n", array_map(
                static fn (string $line, int $i): string => ($i + 1).'. '.$line,
                $lines,
                array_keys($lines),
            ))),
        ]);

        $byIndex = [];

        foreach ((array) $response->get('verdicts', []) as $row) {
            if (is_array($row) && is_int($row['index'] ?? null) && is_string($row['verdict'] ?? null)) {
                $byIndex[$row['index']] = $row['verdict'];
            }
        }

        return array_map(static fn (int $i): string => $byIndex[$i + 1] ?? 'unknown', array_keys($lines));
    }
}
