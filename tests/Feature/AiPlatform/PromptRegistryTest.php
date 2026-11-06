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
        // The composer (S9). A **viable** draft, because that is the branch with a schema to
        // satisfy — a refusal is `is_viable: false` and a reason, and would validate trivially.
        'signals.compose_definition' => [
            'is_viable' => true,
            'reason' => null,
            'summary' => 'A UK manufacturer changing hands.',
            'definition' => [
                'name' => 'Manufacturer sold',
                'description' => 'A manufacturing business changes hands or its owner sells a controlling stake.',
                'category' => 'liquidity',
                'icon' => 'handshake',
                'query_templates' => ['{location} manufacturer acquired', '{location} engineering firm sold'],
                'match_keywords' => ['acqui', 'sold'],
                'includes' => ['a named manufacturer being bought or sold'],
                'excludes' => ['deal round-ups covering several companies'],
                'min_confidence' => 60,
                'min_relevance' => 40,
                'suggested_approach_hint' => 'Introduce yourself via LinkedIn or a mutual referral.',
            ],
        ],
    ];
}

it('seeds every prompt from docs/ai-prompts.md', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    // Twelve **rows** for nine keys. Three have a v2, and all three v1 rows stay seeded so a
    // report, message or signal that recorded `prompt_version: 1` can still be traced back to what
    // produced it; the registry resolves the highest active version.
    //
    // - `outreach.draft_message` v2 (Phase 11)
    // - `business_intel.background_check` v2 (Phase 7c — split the team's own industry from its
    //   customers')
    // - `signals.extract_event` v2 (Phase S7 — signal types became rows, so the schema's
    //   `event_type` enum had to go; ADR 0018 section 7)
    //
    // `signals.compose_definition` (S9) is the ninth key: the builder's drafting prompt, and the
    // only one on the reasoning tier that writes **configuration** rather than prose.
    expect(AiPromptTemplate::query()->count())->toBe(12)
        ->and(AiPromptTemplate::query()->pluck('key')->unique()->sort()->values()->all())->toBe([
            'business_intel.background_check',
            'icp.refine_suggestions',
            'insights.periodic_digest',
            'lead.enrich_extract',
            'lead_sourcing.shape_query',
            'outreach.draft_message',
            'scoring.qualitative_fit',
            'signals.compose_definition',
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

    expect(AiPromptTemplate::query()->count())->toBe(12);
});
