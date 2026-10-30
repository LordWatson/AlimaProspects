<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Services\JsonSchemaValidator;
use App\Modules\AiPlatform\Services\PromptRegistry;
use App\Modules\AiPlatform\Transport\SchemaConverter;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * A canned, schema-valid response for each seeded prompt (ai-prompts.md rule: every structured
 * prompt must have a test proving a canned response parses).
 *
 * @return array<string, array<string, mixed>>
 */
function cannedResponses(): array
{
    return [
        'business_intel.background_check' => [
            'summary' => 'A UK marketing agency.', 'offering' => 'Campaigns.', 'positioning' => 'Boutique.',
            'own_industry' => 'Marketing agency', 'customer_industries' => ['Retail'],
            'target_customers' => ['SMEs'], 'decision_maker_titles' => ['Marketing Director'],
            'competitors' => ['Rival Ltd'], 'confidence' => 0.8,
        ],
        'lead_sourcing.shape_query' => [
            'terms' => 'boutique hotels', 'customer_description' => 'Independent hotels that rebrand.',
            'exclude_terms' => ['graphic design'], 'reasoning' => 'Hospitality buys design work.',
        ],
        'icp.refine_suggestions' => [
            'suggestions' => [
                ['type' => 'industry', 'value' => 'SaaS', 'weight' => 80, 'rationale' => 'Fits.'],
                ['type' => 'exclusion', 'value' => 'competitor.com', 'weight' => null, 'rationale' => 'Rival.'],
            ],
        ],
        'lead.enrich_extract' => [
            'company_name' => 'Acme', 'website' => 'https://acme.test', 'industry' => 'Retail',
            'employee_range' => '11-50', 'locations' => ['London'], 'technologies' => ['Shopify'],
            'emails' => ['hi@acme.test'], 'phone' => null,
        ],
        'scoring.qualitative_fit' => [
            'score' => 72, 'grade' => 'B', 'rationale' => 'Good regional fit.', 'strengths' => ['Location'], 'concerns' => ['Size'],
        ],
        'outreach.draft_message' => [
            'subject' => 'Quick idea', 'variants' => [['channel' => 'email', 'body' => 'Hello there.']],
        ],
        'insights.periodic_digest' => [
            'insights' => [[
                'topic' => 'outreach', 'title' => 'LinkedIn is replying and email is not',
                'body' => 'Six of eleven LinkedIn approaches came back against one of nine emails.',
                'severity' => 'action',
            ]],
        ],
        'signals.extract_event' => [
            'events' => [[
                'item_index' => 0, 'is_event' => true, 'event_type' => 'funding_round',
                'subject_person_name' => 'Jane', 'subject_person_role' => 'CEO', 'subject_company_name' => 'Acme',
                'counterparty_name' => null, 'amount' => 40_000_000, 'currency' => 'USD', 'location' => 'Dubai',
                'confidence' => 90, 'relevance' => 80, 'summary' => 'Acme raised a Series A.', 'suggested_approach' => 'Intro via LinkedIn.',
            ]],
        ],
    ];
}

it('seeds every prompt from docs/ai-prompts.md', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    // Ten **rows** for eight keys: `outreach.draft_message` has a version 2 (Phase 11) and
    // `business_intel.background_check` a version 2 (Phase 7c, which split the team's own industry
    // from its customers'). Both v1 rows stay seeded so a report or message that recorded
    // `prompt_version: 1` can still be traced back to what produced it. The registry resolves the
    // highest active version.
    expect(AiPromptTemplate::query()->count())->toBe(10)
        ->and(AiPromptTemplate::query()->pluck('key')->unique()->sort()->values()->all())->toBe([
            'business_intel.background_check',
            'icp.refine_suggestions',
            'insights.periodic_digest',
            'lead.enrich_extract',
            'lead_sourcing.shape_query',
            'outreach.draft_message',
            'scoring.qualitative_fit',
            'signals.extract_event',
        ]);
});

it('resolves the active version of a seeded prompt', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $template = app(PromptRegistry::class)->resolve('scoring.qualitative_fit');

    expect($template->version)->toBe(1)
        ->and($template->hasSchema())->toBeTrue();
});

it('seeds schemas the transport can convert and a canned response validates against', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $validator = new JsonSchemaValidator;
    $canned = cannedResponses();

    AiPromptTemplate::query()->get()->each(function (AiPromptTemplate $template) use ($validator, $canned): void {
        // The SDK must be able to turn the stored schema into its property types without throwing.
        $properties = SchemaConverter::properties((array) $template->schema);
        expect($properties)->not->toBeEmpty();

        // The canned response must satisfy our own validator.
        $errors = $validator->validate($canned[$template->key], (array) $template->schema);
        expect($errors)->toBe([]);
    });
});

it('is idempotent when re-seeded', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
    $this->seed(AiPromptTemplateSeeder::class);

    expect(AiPromptTemplate::query()->count())->toBe(10);
});
