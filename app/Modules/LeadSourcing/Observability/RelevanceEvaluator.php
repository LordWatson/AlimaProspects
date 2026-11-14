<?php

declare(strict_types=1);

namespace App\Modules\LeadSourcing\Observability;

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Models\AiRequest;
use App\Modules\AiPlatform\Support\UntrustedContent;
use App\Modules\BusinessIntelligence\Actions\AcceptBackgroundCheckAction;
use App\Modules\BusinessIntelligence\Data\AcceptBackgroundCheckData;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Contracts\CapabilityGate;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\SiteReader;
use App\Modules\Core\Support\RobotsPolicy;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Data\DiscoveryQuery;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Exceptions\LeadSourcingException;
use App\Modules\LeadSourcing\Services\CandidateRelevanceGate;
use App\Modules\LeadSourcing\Services\DiscoveryQueryShaper;
use App\Modules\LeadSourcing\Services\LeadCandidateFilter;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\Onboarding\Models\BusinessProfile;
use Illuminate\Contracts\Cache\Repository as Cache;
use RuntimeException;

/**
 * Runs one fixture business through the real chain — profile → background check → seeded ICP →
 * query shaping → providers → candidate screening — and measures how relevant the result is
 * (`relevance:eval`, docs/plans/ai-relevance-plan.md §2).
 *
 * It lives under `Observability/` by ADR 0002's own test: delete it and the product still works.
 * It is also the one class in this module that imports Business Intelligence, which nothing in the
 * product path may do; a harness that measures the whole chain has to start the whole chain.
 *
 * **It persists no leads.** It stops at the candidate list, so nothing reaches the workspace,
 * nothing is enriched and no lead allowance is spent. What it does write belongs to its own
 * `Relevance eval · <fixture>` team: a profile, a report version and a seeded ICP.
 *
 * Two measures, kept apart because they fail differently:
 *
 * - **Labelled** — the fixture's hand-labelled companies pushed through {@see self::screen()}. Exact
 *   and free: how many competitors survive, how many good fits are lost. No model's opinion in it.
 * - **Live** — the top candidates each provider really returned, graded by `lead_sourcing.eval_judge`
 *   against a ground-truth description the pipeline never sees. The judge is itself scored against
 *   the labels, and a precision figure is only as good as that agreement.
 */
final class RelevanceEvaluator
{
    public const string JUDGE_PROMPT = 'lead_sourcing.eval_judge';

    private const int JUDGE_BATCH = 30;

    public function __construct(
        private readonly TeamContext $teamContext,
        private readonly AiClient $ai,
        private readonly CapabilityGate $capabilities,
        private readonly AcceptBackgroundCheckAction $accept,
        private readonly DiscoveryQueryShaper $shaper,
        private readonly LeadSourceProviderRegistry $registry,
        private readonly LeadCandidateFilter $filter,
        private readonly CandidateRelevanceGate $gate,
        private readonly Cache $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $fixture  A decoded `tests/Fixtures/relevance/*.json`.
     * @return array<string, mixed>
     */
    public function evaluate(array $fixture, int $n, bool $thin = false): array
    {
        $key = (string) $fixture['key'];
        /** @var array<string, mixed> $profileData */
        $profileData = $fixture['profile'];

        // A team that has typed nothing but its name and its address: everything else the chain
        // knows has to come from the background check reading the site. This is what measures
        // the check itself rather than the wizard.
        if ($thin) {
            $profileData = array_intersect_key($profileData, array_flip(['company_name', 'website', 'primary_market']));
        }

        $team = Team::query()->firstOrCreate(
            ['name' => 'Relevance eval · '.$key],
            ['timezone' => 'UTC', 'locale' => 'en', 'market' => (string) $profileData['primary_market']],
        );

        // Team names are not unique, and what follows rewrites the team's profile and deletes its
        // ICPs. An eval team has no members; anything else carrying this name is somebody's.
        if ($team->users()->exists()) {
            throw new RuntimeException(sprintf('A team named "%s" has members, so it is not an evaluation team. Refusing to touch it.', $team->name));
        }

        return $this->teamContext->run($team, function () use ($team, $fixture, $profileData, $key, $n): array {
            $ledgerMark = (int) AiRequest::query()->max('id');

            [$profile, $icp] = $this->prepare($team, $fixture, $profileData);

            $sources = [];
            $reference = null;

            $selected = array_values(array_map(
                static fn (mixed $value): LeadSource => LeadSource::from((string) $value),
                (array) $fixture['sources'],
            ));

            // One shaping call for every source, exactly as `StartDiscoveryRunAction` makes it.
            $shaped = $this->shaper->shapeAll($selected, $profile, $icp, $n, null, (string) ($fixture['location'] ?? ''));

            foreach ($selected as $source) {
                [$sources[$source->value], $query] = $this->runSource($source, $shaped[$source->value], $fixture, $n);

                // The labelled set is screened with the web-search query where there is one (it
                // carries the richest exclusions), else with the first query that was shaped.
                if ($query instanceof DiscoveryQuery && ($reference === null || $source === LeadSource::WebSearch)) {
                    $reference = $query;
                }
            }

            $labelled = $this->labelled($fixture, $reference);

            $spend = AiRequest::query()->where('team_id', $team->getKey())->where('id', '>', $ledgerMark)->get();

            return [
                'fixture' => $key,
                'icp_seeded' => $icp instanceof Icp,
                'sources' => $sources,
                'labelled' => $labelled,
                'pipeline_ai_cost_micros' => (int) $spend->where('prompt_key', '!=', self::JUDGE_PROMPT)->sum('cost_micros'),
                'judge_ai_cost_micros' => (int) $spend->where('prompt_key', self::JUDGE_PROMPT)->sum('cost_micros'),
            ];
        });
    }

    /**
     * The one place a candidate list is screened before it would become leads: the word-matching
     * exclusion filter, then the relevance gate (ADR 0035) — the same two steps, in the same order,
     * as `DiscoverLeadsJob`.
     *
     * @param  list<SourcedLead>  $leads
     * @return list<SourcedLead>
     */
    public function screen(DiscoveryQuery $query, array $leads): array
    {
        return $this->gate->screen($query, $this->filter->apply($query, $leads)[0])['kept'];
    }

    /**
     * Precision over the first `$k` verdicts. `unknown` counts against it: a candidate nobody can
     * tell is a fit is not a lead worth a member's time.
     *
     * @param  list<string>  $verdicts
     */
    public static function precisionAt(array $verdicts, int $k): ?float
    {
        $top = array_slice($verdicts, 0, $k);

        return $top === [] ? null : count(array_keys($top, 'good', true)) / count($top);
    }

    /**
     * Write the fixture's profile, run the real background check over the fixture's homepage text,
     * accept it with ICP seeding, and hand back what the search will be built from.
     *
     * @param  array<string, mixed>  $fixture
     * @param  array<string, mixed>  $profileData
     * @return array{BusinessProfile, ?Icp}
     */
    private function prepare(Team $team, array $fixture, array $profileData): array
    {
        $teamId = (int) $team->getKey();

        // Recreated, not updated: an earlier run's accepted findings are on the old row, and a
        // profile that remembers them would be measuring last time's background check.
        BusinessProfile::query()->where('team_id', $teamId)->delete();
        $profile = BusinessProfile::query()->create(['team_id' => $teamId, ...$profileData]);
        $profile->forceFill(['completed_at' => now()])->save();

        // Last run's seeded profile would otherwise be picked up as this run's.
        Icp::query()->where('team_id', $teamId)->get()->each->delete();

        /** @var array<string, string> $pages path => text */
        $pages = (array) ($fixture['pages'] ?? []);
        $origin = rtrim((string) $profile->website, '/');

        // The fixture stands in for the website: its pages, keyed by path, linked from the
        // homepage. No `robots.txt`, so the reader is allowed everywhere.
        $site = new class($pages, $origin) implements WebContentFetcher
        {
            /**
             * @param  array<string, string>  $pages
             */
            public function __construct(private readonly array $pages, private readonly string $origin) {}

            public function fetch(?string $url): string
            {
                return $this->fetchPage($url)['text'];
            }

            public function fetchLines(?string $url): string
            {
                return '';
            }

            public function fetchPage(?string $url): array
            {
                $path = '/'.ltrim(substr(rtrim((string) $url, '/'), strlen($this->origin)), '/');

                return [
                    'text' => $this->pages[$path] ?? '',
                    'links' => $path === '/'
                        ? array_values(array_map(fn (string $p): string => $this->origin.$p, array_diff(array_keys($this->pages), ['/'])))
                        : [],
                ];
            }
        };

        // Called directly rather than dispatched: a queue would run the job with the container's
        // real fetcher.
        (new RunBackgroundCheckJob($teamId))->handle(
            $this->ai,
            new SiteReader($site, new RobotsPolicy($site, $this->cache)),
            $this->teamContext,
            $this->capabilities,
        );

        $report = BusinessIntelReport::query()->where('team_id', $teamId)->orderByDesc('version')->first();

        if (! $report instanceof BusinessIntelReport || ! $report->isReady()) {
            // A team whose plan lacks the capability leaves the report pending without an error,
            // which would otherwise read as a pipeline that found nothing.
            throw new RuntimeException(sprintf(
                'The background check did not complete for %s (status: %s). Check the eval team\'s plan capability and AI allowance.',
                $team->name,
                $report instanceof BusinessIntelReport ? $report->status->value : 'no report',
            ));
        }

        $this->accept->handle($team, $report, new AcceptBackgroundCheckData(applyToProfile: true, seedIcp: true));

        $icp = Icp::query()->where('team_id', $teamId)->with('criteria')->latest('id')->first();

        return [$profile->refresh(), $icp];
    }

    /**
     * @param  array<string, mixed>  $fixture
     * @return array{array<string, mixed>, ?DiscoveryQuery}
     */
    private function runSource(LeadSource $source, DiscoveryQuery $query, array $fixture, int $n): array
    {
        if (! $this->registry->has($source)) {
            return [['error' => 'not_registered'], null];
        }

        if (trim($query->terms) === '' && $query->sicCodes === []) {
            return [['error' => 'nothing_to_search', 'shaped' => $query->shaped], null];
        }

        $provider = $this->registry->for($source);

        try {
            $returned = $provider->discover($query);
        } catch (LeadSourcingException $e) {
            // A missing key or a provider outage is a fact about this machine, not about relevance:
            // record it and carry on with the other sources.
            return [['error' => $e->errorCode(), 'terms' => $query->terms], $query];
        }

        $kept = array_slice($this->screen($query, $returned), 0, $n);
        $verdicts = $this->judge($fixture, $kept);

        return [[
            'terms' => $query->terms,
            'queries' => $query->queries,
            'sic_codes' => $query->sicCodes,
            'shaped' => $query->shaped,
            'exclude_terms' => $query->excludeTerms,
            'returned' => count($returned),
            'kept' => count($kept),
            'provider_cost_micros' => $provider->costMicros(count($returned)),
            'precision_at_10' => self::precisionAt($verdicts, 10),
            'precision_at_n' => self::precisionAt($verdicts, $n),
            'competitors' => count(array_keys($verdicts, 'competitor', true)),
            'unknown' => count(array_keys($verdicts, 'unknown', true)),
            'candidates' => array_map(
                static fn (SourcedLead $lead, string $verdict): array => [
                    'name' => $lead->companyName,
                    'website' => $lead->website,
                    'verdict' => $verdict,
                ],
                $kept,
                $verdicts,
            ),
        ], $query];
    }

    /**
     * The hand-labelled companies: what the screen does to them, and whether the judge agrees with
     * the labels (which is what entitles the live precision figures to be believed).
     *
     * @param  array<string, mixed>  $fixture
     * @return array<string, mixed>
     */
    private function labelled(array $fixture, ?DiscoveryQuery $query): array
    {
        /** @var list<array{name: string, website: string, about: string, label: string}> $companies */
        $companies = $fixture['companies'];

        $leads = array_map(static fn (array $company): SourcedLead => new SourcedLead(
            source: LeadSource::WebSearch,
            companyName: $company['name'],
            website: $company['website'],
            raw: ['snippet' => $company['about']],
        ), $companies);

        $labels = array_column($companies, 'label');
        $total = array_count_values($labels);

        $result = ['competitor_leak' => null, 'good_fit_loss' => null];

        if ($query instanceof DiscoveryQuery) {
            $survivors = array_map(static fn (SourcedLead $lead): string => $lead->companyName, $this->screen($query, $leads));
            $kept = ['good' => 0, 'competitor' => 0];

            foreach ($companies as $company) {
                if (isset($kept[$company['label']]) && in_array($company['name'], $survivors, true)) {
                    $kept[$company['label']]++;
                }
            }

            // Null, not zero, for a label the fixture does not carry: nothing was measured.
            $result['competitor_leak'] = isset($total['competitor']) ? $kept['competitor'] / $total['competitor'] : null;
            $result['good_fit_loss'] = isset($total['good']) ? 1 - $kept['good'] / $total['good'] : null;
        }

        $verdicts = $this->judge($fixture, $leads);
        $agreed = count(array_filter(array_map(
            static fn (string $label, string $verdict): bool => $label === $verdict,
            $labels,
            $verdicts,
        )));

        $result['judge_agreement'] = $agreed / max(1, count($labels));

        return $result;
    }

    /**
     * One verdict per candidate, in order. A candidate the judge skipped is `unknown`.
     *
     * @param  array<string, mixed>  $fixture
     * @param  list<SourcedLead>  $leads
     * @return list<string>
     */
    private function judge(array $fixture, array $leads): array
    {
        /** @var array<string, mixed> $profile */
        $profile = $fixture['profile'];
        $verdicts = [];

        foreach (array_chunk($leads, self::JUDGE_BATCH) as $batch) {
            $lines = [];

            foreach ($batch as $i => $lead) {
                $lines[] = sprintf('%d. %s', $i + 1, implode(' | ', array_filter([
                    $lead->companyName,
                    $lead->website,
                    $lead->industry,
                    // What a register knows a company by when its name says nothing.
                    $lead->sicCodes === [] ? null : 'SIC '.implode(', ', $lead->sicCodes),
                    $lead->locality,
                    $lead->companyStatus,
                    is_string($lead->raw['snippet'] ?? null) ? $lead->raw['snippet'] : null,
                ])));
            }

            $response = $this->ai->structured(self::JUDGE_PROMPT, [
                'seller' => sprintf(
                    '%s — %s. Sells: %s',
                    $profile['company_name'],
                    $profile['value_proposition'],
                    implode(', ', (array) $profile['products_services']),
                ),
                'ground_truth' => (string) $fixture['ground_truth'],
                // Search results and model output: data, never instructions.
                'candidates' => UntrustedContent::from(implode("\n", $lines)),
            ]);

            $byIndex = [];

            foreach ((array) $response->get('verdicts', []) as $row) {
                if (is_array($row) && is_int($row['index'] ?? null) && is_string($row['verdict'] ?? null)) {
                    $byIndex[$row['index']] = $row['verdict'];
                }
            }

            foreach (array_keys($batch) as $i) {
                $verdicts[] = $byIndex[$i + 1] ?? 'unknown';
            }
        }

        return $verdicts;
    }
}
