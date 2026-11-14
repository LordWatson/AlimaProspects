<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Console;

use App\Modules\LeadSourcing\Observability\RelevanceEvaluator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The relevance measuring stick (docs/plans/ai-relevance-plan.md §2): runs each fixture business
 * through the real chain and reports precision@N, the competitor leak rate and the cost.
 *
 * **It calls live providers and a live model, and it costs money.** That is why it is a command and
 * not a test, why it asks before running, and why it refuses in production. It writes only to its
 * own `Relevance eval · <fixture>` teams and never stores a lead.
 */
final class RelevanceEvalCommand extends Command
{
    protected $signature = 'relevance:eval
        {fixture? : One fixture key; every fixture when omitted}
        {--n=20 : Candidates judged per source}
        {--thin : Start each business from its name and website only, so the background check has to supply the rest}
        {--min-agreement=0.85 : Judge/label agreement below which precision is not reported}';

    protected $description = 'Measure how relevant discovery results are for the fixture businesses (live, paid).';

    public function handle(RelevanceEvaluator $evaluator): int
    {
        if ($this->laravel->isProduction()) {
            $this->components->error('relevance:eval creates evaluation teams and spends provider money; it does not run in production.');

            return self::FAILURE;
        }

        $fixtures = $this->fixtures();

        if ($fixtures === []) {
            $this->components->error('No matching fixture in tests/Fixtures/relevance.');

            return self::FAILURE;
        }

        if (! $this->confirm(sprintf('Run %d fixture(s) against LIVE providers and the live model? This costs money.', count($fixtures)))) {
            return self::SUCCESS;
        }

        $n = max(1, (int) $this->option('n'));
        $minAgreement = (float) $this->option('min-agreement');
        $results = [];
        $rows = [];

        foreach ($fixtures as $fixture) {
            $this->components->info('Evaluating '.$fixture['key']);
            $result = $evaluator->evaluate($fixture, $n, (bool) $this->option('thin'));
            $results[] = $result;

            $agreement = (float) $result['labelled']['judge_agreement'];
            $trusted = $agreement >= $minAgreement;

            foreach ($result['sources'] as $source => $row) {
                $rows[] = isset($row['error'])
                    ? [$result['fixture'], $source, $row['error'], '', '', '', '', '']
                    : [
                        $result['fixture'],
                        $source,
                        $row['kept'].'/'.$row['returned'],
                        $trusted ? $this->percent($row['precision_at_10']) : 'n/a',
                        $trusted ? $this->percent($row['precision_at_n']) : 'n/a',
                        $row['competitors'],
                        $row['unknown'],
                        $this->usd($row['provider_cost_micros']),
                    ];
            }

            $rows[] = [
                $result['fixture'],
                'labelled set',
                'leak '.$this->percent($result['labelled']['competitor_leak']),
                'loss '.$this->percent($result['labelled']['good_fit_loss']),
                'judge '.$this->percent($agreement),
                '',
                '',
                'AI '.$this->usd($result['pipeline_ai_cost_micros']).' + judge '.$this->usd($result['judge_ai_cost_micros']),
            ];
        }

        $this->table(['Fixture', 'Source', 'Kept/returned', 'P@10', 'P@'.$n, 'Competitors', 'Unknown', 'Cost'], $rows);

        $path = storage_path('app/relevance/'.now()->format('Ymd-His').'.json');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $this->components->info('Full result, with every candidate and verdict: '.$path);

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

            if ($only === null || $fixture['key'] === $only) {
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
