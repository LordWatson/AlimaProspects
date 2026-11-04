<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\CircuitBreaker;
use App\Modules\Core\Support\RobotsPolicy;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Core\Testing\FakeWebContentFetcher;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalCluster;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalArticleEnricher;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Billing;
use Throwable;

/*
 * Reading the full article for the top of the feed (S12), and `robots.txt`.
 *
 * The oldest limitation in the module: amounts and names are best-effort from a search snippet. For
 * the signals a member will actually read it is worth fetching the page — and the five things that
 * bound that are what most of this file is about, because this is the only part of Signals that
 * fetches arbitrary pages.
 *
 * The assertion that matters most is the one about the body: it is read, used, and **not stored**.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fetcher = new FakeWebContentFetcher;
    app()->instance(WebContentFetcher::class, $this->fetcher);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
    app()->forgetInstance(SignalArticleEnricher::class);
    app()->forgetInstance(RobotsPolicy::class);

    config()->set('signals.article_fetch.enabled', true);
    config()->set('signals.article_fetch.min_heat', 70);
    config()->set('ai-platform.cache.enabled', false);
});

/** A hot, unread signal in a run, ready to be enriched. */
function fetchableSignal(Team $team, array $attributes = []): array
{
    return app(TeamContext::class)->run($team, function () use ($team, $attributes): array {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        $signal = Signal::factory()->create(array_merge([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'heat' => 85,
            'amount_minor' => null,
            'currency' => null,
            'source_url' => 'https://gulfbusiness.com/acme-series-a',
            'article_fetched_at' => null,
        ], $attributes));

        return [$run, $signal];
    });
}

/** The re-extraction the prompt would return from a full article. */
function fullTextEvent(array $overrides = []): array
{
    return ['events' => [array_merge([
        'item_index' => 0,
        'is_event' => true,
        'event_type' => 'funding_round',
        'subject_person_name' => 'Jane Founder',
        'subject_person_role' => 'Chief Executive',
        'subject_company_name' => 'Acme DMCC',
        'counterparty_name' => 'Regional Ventures',
        'amount' => 40_000_000,
        'currency' => 'USD',
        'location' => 'Dubai',
        'confidence' => 95,
        'relevance' => 85,
        'summary' => 'Acme raised $40m from Regional Ventures, per the full article.',
        'suggested_approach' => 'Ask a mutual connection for a LinkedIn introduction.',
    ], $overrides)]];
}

/**
 * How many **articles** were fetched.
 *
 * Not $fetcher->calls(), which counts the `robots.txt` read that precedes each one — two requests
 * per page. Counting those as articles would make every per-run ceiling assertion off by the
 * number of distinct publishers, which is exactly the sort of arithmetic a test should not be
 * quietly doing on the reader's behalf.
 */
function articlesFetched($fetcher): int
{
    return count(array_filter(
        $fetcher->fetched,
        static fn (string $url): bool => ! str_ends_with($url, '/robots.txt'),
    ));
}

function enrichRun(Team $team, SignalRun $run): int
{
    return app(TeamContext::class)->run(
        $team,
        fn (): int => app(SignalArticleEnricher::class)->enrich($run, (int) $team->getKey()),
    );
}

// --- The thing it exists for --------------------------------------------------------------------

it('fills in what the snippet could not, and never stores the article', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $body = str_repeat('Acme DMCC has raised forty million dollars. ', 40);
    $this->fetcher->returns($body);
    $this->fakeAi->queueStructured(fullTextEvent());

    $spent = enrichRun($team, $run);

    $signal->refresh();

    expect($spent)->toBeGreaterThan(0)
        ->and($signal->amount_minor)->toBe(40_000_000_00)
        ->and($signal->currency?->value)->toBe('usd')
        ->and($signal->counterparty_name)->toBe('Regional Ventures')
        ->and($signal->article_fetched_at)->not->toBeNull()
        // **The body is read, used and dropped.** Retention and DSAR both get harder the moment
        // the product keeps prose, and a signal is supposed to hold a name, a role and a URL.
        ->and($signal->summary)->not->toContain('forty million dollars. Acme')
        ->and($signal->getAttributes())->not->toHaveKey('article_body')
        ->and(json_encode($signal->raw))->not->toContain('forty million dollars. Acme');
});

it('fills gaps and never erases with nothing', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team, [
        'amount_minor' => 1_000_000_00,
        'currency' => 'usd',
        'counterparty_name' => 'Somebody From The Snippet',
    ]);

    $this->fetcher->returns(str_repeat('A long article that names nobody in particular. ', 40));
    // The full text turns out to be vaguer than the snippet was.
    $this->fakeAi->queueStructured(fullTextEvent([
        'amount' => null,
        'currency' => null,
        'counterparty_name' => null,
    ]));

    enrichRun($team, $run);

    $signal->refresh();

    // The same rule conversion applies when it merges a lead: a re-read that found nothing must
    // not erase what the snippet found.
    expect($signal->amount_minor)->toBe(1_000_000_00)
        ->and($signal->counterparty_name)->toBe('Somebody From The Snippet');
});

it('never moves a signal between the team’s types', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $this->fetcher->returns(str_repeat('This was in fact an acquisition all along. ', 40));
    $this->fakeAi->queueStructured(fullTextEvent(['event_type' => 'acquisition']));

    enrichRun($team, $run);

    // A re-read that reclassified an event would move a card between types behind the team's
    // back, and the guard that makes an event key trustworthy lives in the run (ADR 0018 §7).
    expect($signal->refresh()->event_key)->toBe('funding_round');
});

// --- The five things that bound it ---------------------------------------------------------------

it('does nothing at all while the feature is off', function (): void {
    config()->set('signals.article_fetch.enabled', false);

    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    expect(enrichRun($team, $run))->toBe(0)
        ->and($signal->refresh()->article_fetched_at)->toBeNull()
        ->and(articlesFetched($this->fetcher))->toBe(0);
});

it('reads only the top of the feed', function (): void {
    $team = Team::factory()->create();
    [$run] = fetchableSignal($team, ['heat' => 40]);

    // Only the signals a member is actually going to read are worth the money.
    expect(enrichRun($team, $run))->toBe(0)
        ->and(articlesFetched($this->fetcher))->toBe(0);
});

it('reads at most the configured number per run', function (): void {
    config()->set('signals.article_fetch.max_per_run', 1);

    $team = Team::factory()->create();
    [$run] = fetchableSignal($team);
    fetchableSignal($team, ['source_url' => 'https://khaleejtimes.com/second']);

    $this->fetcher->returns(str_repeat('A long enough article to be worth reading. ', 40));
    $this->fakeAi->queueStructured(fullTextEvent());

    enrichRun($team, $run);

    // One busy news day must not become a hundred page fetches.
    expect(articlesFetched($this->fetcher))->toBe(1);
});

it('stops at the team’s monthly ceiling', function (): void {
    config()->set('signals.article_fetch.cost_micros_per_fetch', 2_000);

    // **The plan's figure, not the module's config floor.** Since S16 the ceiling comes from
    // `SignalQuotaSource`, and Billing's plan-backed source wins over
    // `signals.article_fetch.monthly_cap_micros` — so capping that key alone would be capping the
    // thing that gets overridden, and this test would quietly stop testing anything.
    Billing::capSignalAllowance('article_fetch_micros', 2_000);

    $team = Team::factory()->create();

    // One article already read this month: the allowance is spent.
    app(TeamContext::class)->run($team, fn () => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'article_fetched_at' => now(),
    ]));

    [$run] = fetchableSignal($team);

    // The ceiling is a ceiling, not a warning: the run carries on and the rest of the feed is
    // snippet-only, which is what it would have been anyway.
    expect(enrichRun($team, $run))->toBe(0)
        ->and(articlesFetched($this->fetcher))->toBe(0);
});

it('honours robots.txt', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $this->fetcher->returnsForUrl(
        'https://gulfbusiness.com/robots.txt',
        "User-agent: *\nDisallow: /acme-series-a\n",
    );

    expect(enrichRun($team, $run))->toBe(0)
        ->and($signal->refresh()->article_fetched_at)->toBeNull();
});

it('does not pay to read eight outlets’ versions of one event', function (): void {
    $team = Team::factory()->create();
    [$run] = fetchableSignal($team);

    // A member only ever actions the cluster's primary (S11), and paying to read all eight
    // versions would be the exact waste that phase removed, reintroduced at eight times the price.
    $cluster = app(TeamContext::class)->run($team, fn () => SignalCluster::factory()->create([
        'team_id' => $team->getKey(),
        'sources_count' => 2,
    ]));

    app(TeamContext::class)->run($team, function () use ($team, $run, $cluster): void {
        $member = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_run_id' => $run->getKey(),
            'signal_cluster_id' => $cluster->getKey(),
            'heat' => 95,
            'article_fetched_at' => null,
        ]);

        $primary = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_run_id' => $run->getKey(),
            'signal_cluster_id' => $cluster->getKey(),
            'heat' => 95,
            'article_fetched_at' => null,
        ]);

        $cluster->forceFill(['primary_signal_id' => $primary->getKey()])->save();

        unset($member);
    });

    $this->fetcher->returns(str_repeat('A long enough article to be worth reading. ', 40));
    $this->fakeAi->queueStructured(fullTextEvent());
    $this->fakeAi->queueStructured(fullTextEvent());

    enrichRun($team, $run);

    // Two candidates in the run — the standalone signal and the cluster's primary — and never the
    // cluster's other member.
    expect(articlesFetched($this->fetcher))->toBeLessThanOrEqual(2);
});

// --- Failure is ordinary ------------------------------------------------------------------------

it('keeps the snippet’s answer when the page will not load', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team, ['counterparty_name' => 'From The Snippet']);

    // The fetcher returns an empty string for an ordinary failure.
    $this->fetcher->returns('');

    expect(enrichRun($team, $run))->toBe(0)
        ->and($signal->refresh()->counterparty_name)->toBe('From The Snippet')
        ->and($signal->article_fetched_at)->toBeNull();
});

it('marks a page read even when the model gives nothing back', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $this->fetcher->returns(str_repeat('A long article the model makes nothing of. ', 40));
    $this->fakeAi->queueStructured(['events' => []]);

    enrichRun($team, $run);

    // The page was fetched and the money spent; leaving the flag off would have the next run fetch
    // the same page again.
    expect($signal->refresh()->article_fetched_at)->not->toBeNull();
});

it('ignores a consent wall rather than paying a model to read it', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $this->fetcher->returns('Accept cookies to continue.');

    expect(enrichRun($team, $run))->toBe(0)
        ->and($signal->refresh()->article_fetched_at)->toBeNull()
        ->and($this->fakeAi->callCount())->toBe(0);
});

it('re-scores a signal whose facts the article corrected', function (): void {
    config()->set('signals.scoring.amount_history_minimum', 2);

    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team, ['heat' => 85, 'subject_person_role' => 'Site foreman']);

    // Enough history for the amount component to have an opinion once there is an amount.
    app(TeamContext::class)->run($team, function () use ($team): void {
        foreach ([1, 2, 3] as $millions) {
            Signal::factory()->create([
                'team_id' => $team->getKey(),
                'amount_minor' => $millions * 1_000_000_00,
                'currency' => 'usd',
                'article_fetched_at' => null,
            ]);
        }
    });

    $this->fetcher->returns(str_repeat('Acme DMCC has raised forty million dollars. ', 40));
    $this->fakeAi->queueStructured(fullTextEvent(['subject_person_role' => 'Founder & CEO']));

    enrichRun($team, $run);

    $signal->refresh();

    // The amount, the role and the company drive four of the eight components, and the candidate
    // was chosen on the pre-enrichment heat — so a card showing a $40m deal beside a score that
    // described the one with no amount in it is the inconsistency the panel exists to prevent.
    expect($signal->heat)->not->toBe(85)
        ->and($signal->score_breakdown)->not->toBeNull();

    $amount = collect($signal->score_breakdown)->firstWhere('component', 'amount');
    $seniority = collect($signal->score_breakdown)->firstWhere('component', 'seniority');

    expect($amount['value'])->not->toBeNull()
        // "Founder & CEO" out of the full text, where the snippet had a site foreman.
        ->and($seniority['value'])->toBe(100);
});

it('stops the whole loop when the team runs out of AI credit', function (): void {
    config()->set('signals.article_fetch.max_per_run', 5);

    $team = Team::factory()->create();
    [$run] = fetchableSignal($team);
    fetchableSignal($team, ['source_url' => 'https://khaleejtimes.com/second']);
    fetchableSignal($team, ['source_url' => 'https://zawya.com/third']);

    Billing::capAllowance(BillingMetric::AiCredits, 0);

    $this->fetcher->returns(str_repeat('A long enough article to be worth reading. ', 40));

    // Continuing would pay for a page per remaining candidate to meet the same refusal — the one
    // failure where carrying on costs real money for a guaranteed nothing.
    expect(enrichRun($team, $run))->toBe(0)
        ->and(articlesFetched($this->fetcher))->toBe(1);
});

it('keeps the snippet’s answer when the model cannot read the article', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team, ['subject_company_name' => 'Acme DMCC']);

    $this->fetcher->returns(str_repeat('A long enough article to be worth reading. ', 40));

    // Queued **twice** because the client retries once on a schema-invalid response
    // (`ai-platform.retries.schema`), so one malformed payload is not the failure this is about —
    // it is a fake that runs dry on the second call.
    $this->fakeAi->queueStructured(['events' => 'not an array of events']);
    $this->fakeAi->queueStructured(['events' => 'not an array of events']);

    // `InvalidAiResponseException` is an `AiPlatformException`: swallowed, because this call sits
    // after the run has been committed as Completed and anything escaping it fails an
    // already-finished job — which retries, re-pays for the Serper search and alerts somebody
    // about a run that actually succeeded.
    expect(fn () => enrichRun($team, $run))->not->toThrow(Throwable::class);

    $signal->refresh();

    // Marked read even though nothing was learned: the page was fetched and the money spent, and
    // leaving the flag off would have the next run buy the same page again.
    expect($signal->article_fetched_at)->not->toBeNull()
        ->and($signal->subject_company_name)->toBe('Acme DMCC');
});

it('lets nothing at all escape past the commit', function (): void {
    $team = Team::factory()->create();
    [$run, $signal] = fetchableSignal($team);

    $this->fetcher->returns(str_repeat('A long enough article to be worth reading. ', 40));
    // Nothing queued: the fake throws a bare `RuntimeException`, which is **not** an
    // `AiPlatformException` and so reaches the loop's own `Throwable` guard. That is the point —
    // the AI stack is deep enough that enumerating its exceptions is not a safety argument.
    expect(fn () => enrichRun($team, $run))->not->toThrow(Throwable::class);

    // Not marked read, deliberately: we never found out whether the page was usable, so the next
    // run is allowed to try again.
    expect($signal->refresh()->article_fetched_at)->toBeNull();
});

it('holds an unreadable publisher against the breaker', function (): void {
    $team = Team::factory()->create();
    [$run] = fetchableSignal($team);

    // The shared fetcher swallows every error and returns `''`, so an empty body **is** how a
    // failure reaches us. A version that only trusted a thrown exception would retry a host that
    // always times out on every run, for ever.
    $this->fetcher->returns('');

    enrichRun($team, $run);

    // Read through a breaker built with the **module's** prefix, which is what the provider injects.
    // `app(CircuitBreaker::class)` is the default `cb` namespace and would report zero for ever —
    // the whole point of a per-module prefix (see the provider).
    $breaker = new CircuitBreaker(app(CacheRepository::class), prefix: 'signals:cb');

    expect($breaker->failures('signals_article_fetch'))->toBeGreaterThan(0);
});
