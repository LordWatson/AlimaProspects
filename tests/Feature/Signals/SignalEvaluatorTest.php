<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Observability\SignalEvaluator;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPreset;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

/**
 * @return array<string, mixed>
 */
function signalEvalFixture(): array
{
    $article = static fn (int $id, string $title, string $label): array => [
        'id' => $id, 'title' => $title, 'snippet' => $title.'.', 'source' => 'Leeds Business Daily',
        'days_ago' => 2, 'label' => $label, 'url' => 'https://lbd.example/'.$id,
    ];

    return [
        'key' => 'unit',
        'ground_truth' => 'Property and facilities companies near Leeds. Not electricians.',
        'profile' => [
            'company_name' => 'Aire Valley Electrical', 'website' => 'https://ave.example', 'industry' => 'Electrical contracting',
            'primary_market' => 'uk', 'products_services' => ['Commercial electrical installation'],
            'value_proposition' => 'A commercial electrician landlords can leave to it.',
        ],
        'signals' => [
            'presets' => ['uk_acquisition'],
            'locations' => ['Leeds'],
            'icp' => [['type' => 'geography', 'value' => 'Leeds', 'weight' => 70]],
            'articles' => [
                $article(0, 'Northgate FM acquires Castle Facilities', 'good'),
                $article(1, 'Weekly deals round-up for Yorkshire', 'not_event'),
                $article(2, 'Sparkwell Electrical acquires Volt Services', 'competitor'),
            ],
        ],
    ];
}

it('measures labelled articles through the real run and leaves the news source as it found it', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $ai = new FakeAiTransport;
    app()->instance(AiTransport::class, $ai);
    app()->forgetInstance(AiClient::class);

    $event = static fn (int $index, bool $isEvent, string $company): array => [
        'item_index' => $index, 'is_event' => $isEvent, 'event_type' => $isEvent ? 'uk_acquisition' : null,
        'subject_person_name' => null, 'subject_person_role' => null, 'subject_company_name' => $company,
        'counterparty_name' => null, 'amount' => null, 'currency' => null, 'location' => 'Leeds',
        'confidence' => 90, 'relevance' => 80, 'summary' => $company.' made an acquisition.', 'subject_fit' => 'unknown', 'fit_confidence' => 0, 'why_it_matters' => null, 'suggested_approach' => null,
    ];

    // The run's one classification batch: today's pipeline keeps the competitor, which is the
    // leak the harness exists to show.
    $ai->queueStructured(['events' => [
        $event(0, true, 'Northgate FM'),
        $event(1, false, 'Nobody'),
        $event(2, true, 'Sparkwell Electrical'),
    ]]);
    // The judge, on the labelled articles themselves.
    $ai->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'good', 'reason' => 'FM firm.'],
        ['index' => 2, 'verdict' => 'not_event', 'reason' => 'Round-up.'],
        ['index' => 3, 'verdict' => 'wrong_company', 'reason' => 'Electrician.'],
    ]]);

    $bystander = Team::factory()->create();

    $result = app(SignalEvaluator::class)->evaluate(signalEvalFixture(), 20, live: false);

    expect($result['live'])->toBeNull()
        ->and($result['labelled'])->toMatchArray([
            'cards' => 2,
            'unmatched_cards' => 0,
            'feed_precision' => 0.5,
            'good_loss' => 0.0,
            'competitor_leak' => 1.0,
            'not_event_leak' => 0.0,
            'misfit_leak' => null,
            'judge_agreement' => 1.0,
        ])
        ->and(array_column($result['labelled']['articles'], 'outcome'))->toBe(['shown', 'dropped', 'shown'])
        // Everything it wrote is the eval team's.
        ->and(Signal::query()->withoutGlobalScopes()->where('team_id', $bystander->getKey())->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->count())->toBe(2)
        // Nothing is left for `signals:dispatch-due` to run live tomorrow.
        ->and(SignalWatch::query()->withoutGlobalScopes()->where('is_active', true)->count())->toBe(0)
        ->and(app(SignalSourceRegistry::class)->find(SignalSourceKind::News))->not->toBeInstanceOf(FakeSignalSource::class);
});

it('refuses a team with members that happens to carry an eval team name', function (): void {
    $team = Team::factory()->create(['name' => 'Signals eval · unit']);
    $team->users()->attach(User::factory()->create(), ['role' => 'owner']);

    app(SignalEvaluator::class)->evaluate(signalEvalFixture(), 20, live: false);
})->throws(RuntimeException::class, 'has members');

it('ships signal fixtures the harness can read', function (): void {
    $presets = array_map(static fn (SignalPreset $preset): string => $preset->key, SignalPresetCatalogue::all());
    $seen = 0;

    foreach (File::glob(base_path('tests/Fixtures/relevance/*.json')) as $file) {
        $fixture = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);

        if (! isset($fixture['signals'])) {
            continue;
        }

        $seen++;
        $articles = $fixture['signals']['articles'];
        $labels = array_column($articles, 'label', 'id');

        expect(array_diff($fixture['signals']['presets'], $presets))->toBe([])
            ->and(array_diff(array_unique($labels), ['good', 'misfit', 'competitor', 'not_event', 'stale', 'duplicate']))->toBe([])
            // Articles are recognised in the feed by their link.
            ->and(count(array_unique(array_column($articles, 'url'))))->toBe(count($articles))
            // The fake source serves one page, so a fixture longer than a page is silently cut.
            ->and(count($articles))->toBeLessThanOrEqual((int) config('signals.results_per_query'));

        foreach ($articles as $article) {
            if ($article['label'] === 'duplicate') {
                expect($labels[$article['of']])->toBe('good');
            }
        }
    }

    expect($seen)->toBeGreaterThan(0);
});

it('asks before spending anything', function (): void {
    $this->artisan('signals:eval', ['--no-live' => true])
        ->expectsConfirmation('Run 4 fixture(s) against the live model? This costs money.', 'no')
        ->assertSuccessful();

    expect(Team::query()->where('name', 'like', 'Signals eval%')->count())->toBe(0);
});
