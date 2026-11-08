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
        'lead_sourcing.ai_discovery' => [
            'companies' => [
                [
                    'company_name' => 'Northern Rooms', 'industry' => 'Boutique hotel',
                    'employee_range' => '11-50', 'website' => 'https://northernrooms.test',
                    'locality' => 'Manchester', 'region' => 'Greater Manchester', 'country' => 'GB',
                    'why' => 'An independent hotel group that rebrands regularly.',
                ],
            ],
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
        // The ICP composer. A **viable** draft, because that is the branch with a schema to
        // satisfy — a refusal is `is_viable: false` and a reason, and would validate trivially.
        'icp.compose_profile' => [
            'is_viable' => true,
            'reason' => null,
            'summary' => 'Early-stage food brands that need packaging work.',
            'profile' => [
                'name' => 'UK craft food startups',
                'description' => 'Young food producers selling a packaged product.',
                'criteria' => [
                    ['type' => 'industry', 'value' => 'Craft food producers', 'weight' => 85],
                    ['type' => 'exclusion', 'value' => 'Design agencies', 'weight' => null],
                ],
            ],
        ],
        // PL1. The one prompt whose job is to add nothing: given a lead's strongest evidence,
        // it compresses it into a sentence and may use no other fact.
        'lead.why_now' => [
            'summary' => 'Raised a Series A on 12 October, reported by Example Wire.',
            'cited_sources' => ['Example Wire'],
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

    // Seventeen **rows** for eleven keys. Six have a v2, and every v1 row stays seeded so a report,
    // message, signal, score or draft that recorded `prompt_version: 1` can still be traced back to
    // what produced it; the registry resolves the highest active version.
    //
    // - `outreach.draft_message` v2 (Phase 11)
    // - `business_intel.background_check` v2 (Phase 7c — split the team's own industry from its
    //   customers')
    // - `signals.extract_event` v2 (Phase S7 — signal types became rows, so the schema's
    //   `event_type` enum had to go; ADR 0018 section 7)
    // - `lead.enrich_extract` v2 (cheap tier — adds the people array for inferred contacts)
    // - `scoring.qualitative_fit` v2 (reads the criteria the deterministic rules deferred, so the
    //   model judges the vocabulary gaps rather than re-deriving the whole fit)
    // - `icp.compose_profile` v2 (the profile is required when viable — v1 could return
    //   `{"is_viable": true}` with no profile — and need-based/horizontal customers are handled)
    //
    // `signals.compose_definition` (S9) is the ninth key: the builder's drafting prompt, and the
    // only one on the reasoning tier that writes **configuration** rather than prose.
    //
    // `lead_sourcing.ai_discovery` (Phase 7c) is the tenth, and the only one whose output becomes
    // **rows a team acts on** rather than a judgement about rows that already exist.
    //
    // `icp.compose_profile` is the eleventh: the second prompt that writes **configuration** rather
    // than prose, and the one whose job is an inversion — the brief describes the seller as much as
    // the buyer, and returning the seller's own trade is the failure two other prompts here already
    // learned about.
    // `lead.why_now` (PL1) is the twelfth key, and the only one asked to **compress** facts the
    // application already holds rather than to produce something it does not have — which is why
    // its schema carries `cited_sources` that nothing renders: a summary citing an item the prompt
    // was never given is a hallucination the field makes visible.
    expect(AiPromptTemplate::query()->count())->toBe(18)
        ->and(AiPromptTemplate::query()->pluck('key')->unique()->sort()->values()->all())->toBe([
            'business_intel.background_check',
            'icp.compose_profile',
            'icp.refine_suggestions',
            'insights.periodic_digest',
            'lead.enrich_extract',
            'lead.why_now',
            'lead_sourcing.ai_discovery',
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

    expect($template->version)->toBe(2)
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

    expect(AiPromptTemplate::query()->count())->toBe(18);
});
