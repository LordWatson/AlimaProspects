<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\BusinessIntelligence\Data\BackgroundCheckFindings;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Jobs\RunBackgroundCheckJob;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\BusinessIntelligence\Support\ProfileMapping;
use App\Modules\Core\Contracts\WebContentFetcher;
use App\Modules\Core\Enums\Currency;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Services\SiteReader;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Core\Testing\FakeWebContentFetcher;
use App\Modules\Onboarding\Enums\DeliveryModel;
use App\Modules\Onboarding\Models\BusinessProfile;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

uses(RefreshDatabase::class);

/*
 * Relevance P3 (ADR 0036): the background check reads more than a homepage, has to show the page
 * and the words each finding rests on, and maps what it learned onto the profile without ever
 * overwriting what the team typed unless they choose it.
 */

beforeEach(function (): void {
    Cache::flush();
    $this->seed(AiPromptTemplateSeeder::class);
});

function siteFetcher(): FakeWebContentFetcher
{
    $fetcher = (new FakeWebContentFetcher('Kiln & Co. Branding for food and drink.'))
        ->linksFrom('https://kiln.test', [
            'https://kiln.test/pricing',
            'https://kiln.test/case-studies',
            'https://kiln.test/blog/2019/a-long-post',
            'https://kiln.test/case-studies/pollen-bakery/gallery',
            'https://kiln.test/brochure.pdf',
            'https://kiln.test/private/clients',
        ])
        ->returnsForUrl('https://kiln.test/case-studies', 'A full rebrand for Pollen Bakery ahead of its second shop.')
        ->returnsForUrl('https://kiln.test/pricing', 'Projects typically start at £2,500.')
        ->returnsForUrl('https://kiln.test/robots.txt', "User-agent: *\nDisallow: /private/");

    app()->instance(WebContentFetcher::class, $fetcher);

    return $fetcher;
}

it('reads the homepage and the pages that say who buys, and nothing robots.txt forbids', function (): void {
    $fetcher = siteFetcher();

    $pages = app(SiteReader::class)->read('https://kiln.test');

    // Proof of who buys first, then what it costs. A blog post, a deep gallery page and a PDF are
    // not pages that describe the business; `/private/` is disallowed and is never requested.
    expect(array_column($pages, 'url'))->toBe([
        'https://kiln.test',
        'https://kiln.test/case-studies',
        'https://kiln.test/pricing',
    ])
        ->and($fetcher->fetched)->not->toContain('https://kiln.test/private/clients')
        ->and($fetcher->fetched)->not->toContain('https://kiln.test/brochure.pdf');
});

it('stops at its page budget, and reads nothing from a site that disallows it', function (): void {
    siteFetcher();
    config()->set('web-fetch.site.max_pages', 2);

    expect(app(SiteReader::class)->read('https://kiln.test'))->toHaveCount(2);

    Cache::flush();
    app()->instance(WebContentFetcher::class, (new FakeWebContentFetcher('Home'))
        ->returnsForUrl('https://closed.test/robots.txt', "User-agent: *\nDisallow: /"));

    expect(app(SiteReader::class)->read('https://closed.test'))->toBe([]);
});

it('keeps evidence only where the quoted words really are on the page cited', function (): void {
    $pages = [
        ['url' => 'https://kiln.test', 'text' => 'Kiln & Co. Branding for food and drink.'],
        ['url' => 'https://kiln.test/pricing', 'text' => 'Projects typically start at £2,500. We don’t take on national chains.'],
    ];

    $findings = BackgroundCheckFindings::fromAi(backgroundFindings([
        'pricing_min' => 2500, 'pricing_currency' => 'GBP', 'delivery_model' => 'teleport',
        'evidence' => [
            // Word for word, bar the case and a straight apostrophe for the page's curly one.
            ['field' => 'pricing', 'value' => 'from 2,500', 'page' => 2, 'quote' => 'projects typically start at £2,500', 'confidence' => 95],
            ['field' => 'anti_customers', 'value' => 'National chains', 'page' => 2, 'quote' => "We don't take on national chains", 'confidence' => 90],
            // A paraphrase: thrown away, exactly as the prompt was told it would be.
            ['field' => 'offering', 'value' => 'Branding', 'page' => 1, 'quote' => 'They design brands for restaurants', 'confidence' => 80],
            // Right words, wrong page — and a page that was never offered.
            ['field' => 'pricing', 'value' => 'from 2,500', 'page' => 1, 'quote' => 'Projects typically start at £2,500', 'confidence' => 95],
            ['field' => 'pricing', 'value' => 'from 2,500', 'page' => 7, 'quote' => 'Projects typically start at £2,500', 'confidence' => 95],
            // Too short to be evidence of anything.
            ['field' => 'offering', 'value' => 'Branding', 'page' => 1, 'quote' => 'Kiln & Co', 'confidence' => 99],
        ],
    ]))->withVerifiedEvidence($pages);

    expect(array_column($findings->evidence, 'field'))->toBe(['pricing', 'anti_customers'])
        ->and($findings->evidence[0]['url'])->toBe('https://kiln.test/pricing')
        // Major units in, minor units held.
        ->and($findings->pricingMin)->toBe(250_000)
        ->and($findings->pricingCurrency)->toBe(Currency::Gbp)
        // A value outside the enum is "not stated", never coerced.
        ->and($findings->deliveryModel)->toBeNull();
});

it('gives the model the team’s own words and every page, numbered, and stores verified evidence', function (): void {
    siteFetcher();

    $team = Team::factory()->create();
    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'company_name' => 'Kiln & Co',
        'website' => 'https://kiln.test',
        'customer_examples' => ['Three Rivers Gin'],
    ]));

    $ai = new FakeAiTransport;
    app()->instance(AiTransport::class, $ai);
    app()->forgetInstance(AiClient::class);

    $ai->queueStructured(backgroundFindings([
        'proof_customers' => ['Pollen Bakery'],
        'evidence' => [
            ['field' => 'proof_customers', 'value' => 'Pollen Bakery', 'page' => 2, 'quote' => 'A full rebrand for Pollen Bakery', 'confidence' => 95],
            ['field' => 'proof_customers', 'value' => 'Made Up Ltd', 'page' => 2, 'quote' => 'We also worked with Made Up Ltd', 'confidence' => 95],
        ],
    ]));

    app()->call([new RunBackgroundCheckJob((int) $team->getKey()), 'handle']);

    $report = BusinessIntelReport::query()->withoutGlobalScopes()->where('team_id', $team->getKey())->sole();
    $prompt = $ai->requests[0]->prompt;

    expect($report->status)->toBe(BackgroundCheckStatus::Ready)
        ->and($report->findings['evidence'])->toHaveCount(1)
        ->and($report->findings['evidence'][0]['url'])->toBe('https://kiln.test/case-studies')
        ->and($prompt)->toContain('=== Page 2: https://kiln.test/case-studies ===')
        ->and($prompt)->toContain('=== Page 3: https://kiln.test/pricing ===')
        // What the team typed about itself goes in beside what the site says.
        ->and($prompt)->toContain('Three Rivers Gin');
});

it('fills blanks, leaves matches alone, and never overwrites the team’s entry unless they choose it', function (): void {
    $team = Team::factory()->create();

    $profile = app(TeamContext::class)->run($team, fn (): BusinessProfile => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'industry' => 'Design',
        'value_proposition' => 'Done-for-you demand generation.',
        'customer_examples' => ['Three Rivers Gin'],
        'pricing_min' => 100_000, 'pricing_max' => null, 'pricing_currency' => 'gbp',
        'competitors' => null,
        'delivery_model' => null,
    ]));

    $findings = BackgroundCheckFindings::fromAi(backgroundFindings([
        'own_industry' => 'Graphic design',
        'proof_customers' => ['Pollen Bakery', 'three rivers gin'],
        'pricing_min' => 2500, 'pricing_max' => 15000, 'pricing_currency' => 'gbp',
        'delivery_model' => 'national',
    ]));

    $mapping = app(ProfileMapping::class);
    $states = array_column($mapping->rows($profile, $findings), 'state', 'field');

    expect($states['industry'])->toBe(ProfileMapping::CONFLICT)
        ->and($states['value_proposition'])->toBe(ProfileMapping::SAME)
        // A customer the site names is added to the ones the team typed. Nothing is taken away,
        // so there is nothing to ask about.
        ->and($states['customer_examples'])->toBe(ProfileMapping::FILL)
        ->and($states['pricing'])->toBe(ProfileMapping::CONFLICT)
        ->and($states['competitors'])->toBe(ProfileMapping::FILL)
        ->and($states['delivery_model'])->toBe(ProfileMapping::FILL)
        // Nothing found, nothing offered.
        ->and($states)->not->toHaveKey('problems_solved');

    // Accepting with no conflict chosen: blanks are filled, the team's entries stand.
    $written = $mapping->apply($profile, $findings, []);
    $profile->refresh();

    expect($written)->toEqualCanonicalizing(['customer_examples', 'customer_industries', 'target_customers', 'buyer_titles', 'competitors', 'delivery_model'])
        // Theirs, kept: they did not choose the finding.
        ->and($profile->industry)->toBe('Design')
        ->and($profile->pricing_min)->toBe(100_000)
        // Merged without a duplicate, and without losing the one they typed.
        ->and($profile->customer_examples)->toBe(['Three Rivers Gin', 'Pollen Bakery'])
        ->and($profile->competitors)->toBe(['Beta Agency'])
        ->and($profile->delivery_model)->toBe(DeliveryModel::National);
});

it('accepts over HTTP with the member’s choices, and refuses a field that is not one', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'industry' => 'Design',
    ]));

    $report = BusinessIntelReport::factory()->create([
        'team_id' => $team->getKey(),
        'status' => BackgroundCheckStatus::Ready,
        'findings' => backgroundFindings(['own_industry' => 'Graphic design']),
    ]);

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), ['overrides' => ['is_admin']])
        ->assertSessionHasErrors('overrides.0');

    $this->actingAs($user)
        ->get(route('business-intel.show'))
        ->assertInertia(fn ($page) => $page->where('mapping.0.field', 'industry')->where('mapping.0.state', 'conflict'));

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), ['apply_to_profile' => true, 'overrides' => ['industry']])
        ->assertRedirect(route('business-intel.show'));

    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(BusinessProfile::query()->where('team_id', $team->getKey())->sole()->industry)->toBe('Graphic design');
    });
});
