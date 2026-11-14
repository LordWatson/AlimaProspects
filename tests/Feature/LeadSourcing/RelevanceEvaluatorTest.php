<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\LeadSourcing\Data\SourcedLead;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadSourcing\Observability\RelevanceEvaluator;
use App\Modules\LeadSourcing\Services\LeadSourceProviderRegistry;
use App\Modules\LeadSourcing\Testing\FakeLeadSourceProvider;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('measures a fixture through the real chain without storing a lead', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $ai = new FakeAiTransport;
    app()->instance(AiTransport::class, $ai);
    app()->forgetInstance(AiClient::class);

    $candidate = static fn (string $name): SourcedLead => new SourcedLead(
        source: LeadSource::WebSearch,
        companyName: $name,
        website: 'https://'.Str::slug($name).'.example',
    );

    app(LeadSourceProviderRegistry::class)->register(
        (new FakeLeadSourceProvider(LeadSource::WebSearch))->willReturn([
            $candidate('Saltmarsh Sourdough'),
            $candidate('Apex Graphic Design'),
            $candidate('Studio Eight'),
        ]),
    );

    // In call order: the background check, the query shaper, then for what the provider returned
    // and again for the labelled set, the relevance gate followed by the eval's own judge.
    $ai->queueStructured(backgroundFindings([
        'own_industry' => 'Graphic design', 'customer_industries' => ['Food and drink'],
        'target_customers' => ['Independent bakeries'], 'decision_maker_titles' => ['Founder'],
        'competitors' => [],
    ]));
    $ai->queueStructured(shapedQueries('independent bakeries'));
    // The gate is unsure about both survivors of the word filter, so it keeps them.
    $ai->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'fit', 'confidence' => 80, 'reason' => 'Bakery.'],
        ['index' => 2, 'verdict' => 'unclear', 'confidence' => 50, 'reason' => 'Only a name.'],
    ]]);
    $ai->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'good', 'reason' => 'Bakery.'],
        ['index' => 2, 'verdict' => 'competitor', 'reason' => 'Design studio.'],
    ]]);
    // On the labelled set, where each company has a description, it recognises the studio.
    $ai->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'fit', 'confidence' => 90, 'reason' => 'Bakery.'],
        ['index' => 2, 'verdict' => 'competitor', 'confidence' => 90, 'reason' => 'Design studio.'],
    ]]);
    $ai->queueStructured(['verdicts' => [
        ['index' => 1, 'verdict' => 'good', 'reason' => 'Bakery.'],
        ['index' => 2, 'verdict' => 'competitor', 'reason' => 'Studio.'],
        // The third is left out on purpose: a skipped candidate must read as unknown, not as agreement.
    ]]);

    $company = static fn (string $name, string $label): array => [
        'name' => $name, 'website' => 'https://'.Str::slug($name).'.example', 'about' => 'About '.$name, 'label' => $label,
    ];

    $result = app(RelevanceEvaluator::class)->evaluate([
        'key' => 'test-studio',
        'location' => 'Manchester',
        'sources' => ['web_search'],
        'profile' => [
            'company_name' => 'Kiln & Co', 'website' => 'https://kiln.example', 'industry' => 'Graphic design',
            'primary_market' => 'uk', 'products_services' => ['Packaging design'], 'value_proposition' => 'Shelf-ready brands.',
        ],
        'pages' => ['/' => 'We design packaging for bakeries.'],
        'ground_truth' => 'Independent bakeries.',
        'companies' => [
            $company('Saltmarsh Sourdough', 'good'),
            $company('Studio Eight', 'competitor'),
            $company('Apex Graphic Design', 'competitor'),
        ],
    ], 20);

    $source = $result['sources']['web_search'];

    expect($result['icp_seeded'])->toBeTrue()
        // "Graphic design" is the seeded exclusion: it drops the competitor that says so in its
        // name. The one that does not is the relevance gate's to catch.
        ->and($source['returned'])->toBe(3)
        ->and($source['kept'])->toBe(2)
        ->and($source['precision_at_10'])->toBe(0.5)
        ->and($source['competitors'])->toBe(1)
        ->and($result['labelled']['competitor_leak'])->toEqual(0)
        ->and($result['labelled']['good_fit_loss'])->toEqual(0)
        ->and(round($result['labelled']['judge_agreement'], 2))->toBe(0.67)
        ->and(Lead::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Team::query()->where('name', 'Relevance eval · test-studio')->count())->toBe(1);
});

it('counts an unknown verdict against precision', function (): void {
    expect(RelevanceEvaluator::precisionAt(['good', 'unknown', 'bad', 'good'], 10))->toBe(0.5)
        ->and(RelevanceEvaluator::precisionAt(['good', 'bad', 'bad'], 1))->toBe(1.0)
        ->and(RelevanceEvaluator::precisionAt([], 10))->toBeNull();
});

it('ships fixtures the harness can read, each with enough of every label', function (): void {
    $files = File::glob(base_path('tests/Fixtures/relevance/*.json'));

    expect($files)->toHaveCount(6);

    foreach ($files as $file) {
        $fixture = json_decode(File::get($file), true, flags: JSON_THROW_ON_ERROR);
        $labels = array_count_values(array_column($fixture['companies'], 'label'));

        expect($fixture)->toHaveKeys(['key', 'location', 'sources', 'profile', 'pages', 'ground_truth'])
            ->and($labels['good'])->toBeGreaterThanOrEqual(10)
            ->and($labels['bad'])->toBeGreaterThanOrEqual(10)
            ->and($labels['competitor'])->toBeGreaterThanOrEqual(5)
            ->and(array_map(LeadSource::from(...), $fixture['sources']))->not->toBeEmpty();
    }
});

it('does not spend anything until the operator confirms', function (): void {
    $this->artisan('relevance:eval', ['fixture' => 'uk-design-studio'])
        ->expectsConfirmation('Run 1 fixture(s) against LIVE providers and the live model? This costs money.', 'no')
        ->assertSuccessful();

    expect(Team::query()->where('name', 'like', 'Relevance eval%')->count())->toBe(0);
});
