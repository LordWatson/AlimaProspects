<?php

declare(strict_types=1);

namespace App\Modules\Signals\Console;

use App\Modules\Signals\Observability\SignalEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The signal measuring stick (docs/plans/signals-v3-plan.md §2): runs each fixture business's
 * signal types and reports what reached the feed, what was lost, and what it cost.
 *
 * **It calls a live model, and without `--no-live` live news sources, and it costs money.** That is
 * why it is a command and not a test, why it asks before running, and why it refuses in
 * production. It writes only to its own `Signals eval · <fixture>` teams.
 */
final class SignalEvalCommand extends Command
{
    protected $signature = 'signals:eval
        {fixture? : One fixture key; every fixture with a signals block when omitted}
        {--n=20 : Live feed cards judged per fixture}
        {--no-live : Labelled articles only; no live news search}
        {--suggested : Also run live with the signal types Alima suggests for each business}
        {--research : Also run live with AI research (Claude web search) added to the library types}';

    protected $description = 'Measure the quality of the signal feed for the fixture businesses (live, paid).';

    public function handle(SignalEvaluator $evaluator): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('signals:eval creates evaluation teams and spends provider money; it does not run in production.');

            return self::FAILURE;
        }

        $fixtures = $this->fixtures();

        if ($fixtures === []) {
            $this->components->error('No matching fixture with a signals block in tests/Fixtures/relevance.');

            return self::FAILURE;
        }

        $live = ! $this->option('no-live');

        if (! $this->confirm(sprintf(
            'Run %d fixture(s) against the live model%s? This costs money.',
            count($fixtures),
            $live ? ' and LIVE news sources' : '',
        ))) {
            return self::SUCCESS;
        }

        $n = max(1, (int) $this->option('n'));
        $results = [];
        $labelled = [];
        $liveRows = [];
        $suggestedRows = [];
        $researchRows = [];

        foreach ($fixtures as $fixture) {
            $this->components->info('Evaluating '.$fixture['key']);
            $result = $evaluator->evaluate($fixture, $n, $live, (bool) $this->option('suggested'), (bool) $this->option('research'));

            if (is_array($result['research'])) {
                $v = $result['research'];
                $by = [];

                foreach ((array) $v['by_source'] as $kind => $row) {
                    $by[] = $kind.' '.$row['good'].'/'.$row['shown'];
                }

                $researchRows[] = [
                    $result['fixture'], $v['status'], $v['results'], $v['cards'], $v['judged'],
                    $this->percent($v['precision']), implode(' ', $by), $this->usd($v['source_cost_micros']),
                ];
            }

            if (is_array($result['suggested'])) {
                $s = $result['suggested'];
                $tested = array_filter($s['suggestions'], static fn (array $row): bool => ($row['preview']['kept'] ?? 0) > 0);
                $v = $s['live'];
                $suggestedRows[] = [
                    $result['fixture'],
                    count($s['suggestions']),
                    count($tested),
                    is_array($v) ? $v['results'] : 'n/a',
                    is_array($v) ? $v['cards'] : 'n/a',
                    is_array($v) ? $v['judged'] : 'n/a',
                    is_array($v) ? $this->percent($v['precision']) : 'n/a',
                    is_array($v) ? ($v['verdicts']['good'] ?? 0) : 'n/a',
                    $this->usd((int) $s['cost_micros']),
                ];
            }
            $results[] = $result;

            $l = $result['labelled'];
            $labelled[] = isset($l['error'])
                ? [$result['fixture'], $l['error'], '', '', '', '', '', '', '', '']
                : [
                    $result['fixture'],
                    $l['cards'],
                    $this->percent($l['feed_precision']),
                    $this->percent($l['good_loss']),
                    $this->percent($l['misfit_leak']),
                    $this->percent($l['competitor_leak']),
                    $this->percent($l['not_event_leak']),
                    $this->percent($l['stale_leak']),
                    $this->percent($l['duplicate_rate']),
                    $this->percent($l['judge_agreement']),
                ];

            if (is_array($result['live'])) {
                $v = $result['live'];
                $bySource = [];

                foreach ((array) $v['by_source'] as $kind => $row) {
                    $bySource[] = $kind.' '.$row['good'].'/'.$row['shown'];
                }

                $liveRows[] = [
                    $result['fixture'],
                    $v['status'].($v['error'] === null ? '' : ' ('.$v['error'].')'),
                    $v['paid_queries'].'/'.$v['queries'],
                    $v['results'],
                    $v['cards'],
                    $v['judged'],
                    $this->percent($v['precision']),
                    implode(' ', $bySource),
                    $this->usd($v['source_cost_micros']),
                    $this->usd($result['pipeline_ai_cost_micros']).' + judge '.$this->usd($result['judge_ai_cost_micros']),
                ];
            }
        }

        $this->components->info('Labelled articles through the real run');
        $this->table(
            ['Fixture', 'Cards', 'Feed precision', 'Good lost', 'Misfit leak', 'Competitor leak', 'Not-event leak', 'Stale leak', 'Duplicate cards', 'Judge agrees'],
            $labelled,
        );

        if ($liveRows !== []) {
            $this->components->info('Live sources, top '.$n.' cards judged');
            $this->table(
                ['Fixture', 'Run', 'Paid/all queries', 'Results', 'Cards', 'Judged', 'Precision', 'Good/shown by source', 'Live run cost (search + AI)', 'AI cost (both passes)'],
                $liveRows,
            );
        }

        if ($researchRows !== []) {
            $this->components->info('Live, library types with AI research added');
            $this->table(
                ['Fixture', 'Run', 'Results', 'Cards', 'Judged', 'Precision', 'Good/shown by source', 'Run cost line'],
                $researchRows,
            );
        }

        if ($suggestedRows !== []) {
            $this->components->info('Live, with the types Alima suggested');
            $this->table(
                ['Fixture', 'Suggested', 'Found something', 'Results', 'Cards', 'Judged', 'Precision', 'Good cards', 'Cost to suggest and test'],
                $suggestedRows,
            );
        }

        $path = storage_path('app/signals-eval/'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info('Full result, with every article and verdict: '.$path);

        return self::SUCCESS;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fixtures(): array
    {
        $only = $this->argument('fixture');
        $fixtures = [];

        foreach (File::glob(base_path('tests/Fixtures/relevance/*.json')) as $file) {
            /** @var array<string, mixed> $fixture */
            $fixture = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

            if (isset($fixture['signals']) && ($only === null || $fixture['key'] === $only)) {
                $fixtures[] = $fixture;
            }
        }

        return $fixtures;
    }

    private function percent(?float $value): string
    {
        return $value === null ? 'n/a' : number_format($value * 100).'%';
    }

    private function usd(int $micros): string
    {
        return '$'.number_format($micros / 1_000_000, 3);
    }
}
