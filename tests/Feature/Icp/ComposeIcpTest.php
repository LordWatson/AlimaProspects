<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Models\AiPromptTemplate;
use App\Modules\AiPlatform\Services\JsonSchemaValidator;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\ICP\Models\Icp;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * The ICP composer: a sentence in, a filled-in form out, and **nothing saved**. The draft arrives
 * flashed rather than as a prop, which is exactly its lifetime — until the member saves or leaves.
 */
function fakeComposerTransport(): FakeAiTransport
{
    $fake = new FakeAiTransport;
    app()->instance(AiTransport::class, $fake);
    app()->forgetInstance(AiClient::class);

    return $fake;
}

it('flashes a drafted profile back to the editor and saves nothing', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    fakeComposerTransport()->queueStructured([
        'is_viable' => true,
        'reason' => null,
        'summary' => 'Early-stage food brands that need packaging and social work.',
        'profile' => [
            'name' => 'UK craft food startups',
            'description' => 'Young food producers selling a packaged product.',
            'criteria' => [
                ['type' => 'industry', 'value' => 'Craft food producers', 'weight' => 85],
                ['type' => 'company_size', 'value' => '2-20 employees', 'weight' => 55],
                ['type' => 'exclusion', 'value' => 'Design agencies'],
            ],
        ],
    ]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.compose'), [
            'brief' => 'We are a design agency looking for startup food businesses that need packaging branding.',
        ])
        ->assertRedirect(route('icp.create'));

    expect(session('inertia.flash_data.draft.viable'))->toBeTrue()
        ->and(session('inertia.flash_data.draft.profile.name'))->toBe('UK craft food startups')
        ->and(session('inertia.flash_data.draft.profile.criteria'))->toHaveCount(3)
        // Normalised by the save path's own code: an exclusion is never weighted, a blank weight on
        // a signal becomes a neutral 50.
        ->and(session('inertia.flash_data.draft.profile.criteria.2.weight'))->toBeNull()
        // The brief goes back with it, so the box keeps what they typed — a composer that cleared
        // the text on a refusal would make its own reason unactionable.
        ->and(session('inertia.flash_data.brief'))->toContain('design agency');

    // The AI drafts; the member owns.
    expect(Icp::query()->count())->toBe(0);
});

it('passes a refusal and its reason back to the member', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    fakeComposerTransport()->queueStructured([
        'is_viable' => false,
        'reason' => 'That asks for personal contact details rather than for a kind of business.',
        'summary' => null,
        'profile' => null,
    ]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.compose'), ['brief' => 'Get me the mobile numbers of rich people in Dubai.'])
        ->assertRedirect(route('icp.create'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'info');

    expect(session('inertia.flash_data.draft.viable'))->toBeFalse()
        ->and(session('inertia.flash_data.draft.profile'))->toBeNull()
        ->and(session('inertia.flash_data.toast.message'))->toContain('contact details');
});

it('refuses a draft the save path would reject rather than passing it on', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    // Schema-valid (the type is a real case, the weight is in range) but not a profile: a criterion
    // with no value. The schema is the first guard and `IcpData::rules()` is the second.
    fakeComposerTransport()->queueStructured([
        'is_viable' => true,
        'reason' => null,
        'summary' => 'Something.',
        'profile' => [
            'name' => 'Nameless things',
            'description' => null,
            'criteria' => [['type' => 'industry', 'value' => '', 'weight' => 50]],
        ],
    ]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.compose'), ['brief' => 'We sell commercial kitchen equipment to restaurants.'])
        ->assertRedirect(route('icp.create'));

    expect(session('inertia.flash_data.draft.viable'))->toBeFalse()
        ->and(Icp::query()->count())->toBe(0);
});

it('keeps the criteria it can use and drops only the row the save path would reject', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    // One unusable row among four. Refusing the lot was the first version's behaviour and it threw
    // away three good criteria to tell the member their clear description was not understood.
    fakeComposerTransport()->queueStructured([
        'is_viable' => true,
        'reason' => null,
        'summary' => 'Small food producers.',
        'profile' => [
            'name' => 'UK craft food startups',
            'description' => 'Young food producers.',
            'criteria' => [
                ['type' => 'industry', 'value' => 'Craft food producers', 'weight' => 80],
                ['type' => 'industry', 'value' => '', 'weight' => 60],
                ['type' => 'geography', 'value' => 'United Kingdom', 'weight' => 50],
                ['type' => 'exclusion', 'value' => 'Design agencies'],
            ],
        ],
    ]);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.compose'), ['brief' => 'We are a design agency after startup food brands.'])
        ->assertRedirect(route('icp.create'));

    expect(session('inertia.flash_data.draft.viable'))->toBeTrue()
        ->and(session('inertia.flash_data.draft.profile.criteria'))->toHaveCount(3)
        ->and(array_column(session('inertia.flash_data.draft.profile.criteria'), 'value'))
        ->toBe(['Craft food producers', 'United Kingdom', 'Design agencies']);
});

/*
 * The hole the first live call fell straight through: the model answered `{"is_viable": true}` and
 * nothing else. It was valid — `profile` was nullable and not required — and useless, and the
 * member was told their perfectly clear description could not be turned into a profile.
 * `SchemaConverter` passes `required` to the model, so an optional field is one a model may decline
 * to write. This pins it at the schema, which is where it has to hold: by the time our own code
 * sees an empty answer, the call has already been paid for.
 */
it('requires a profile in the prompt schema, so an empty viable answer cannot validate', function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $schema = (array) AiPromptTemplate::query()
        ->where('key', 'icp.compose_profile')
        ->orderByDesc('version')
        ->firstOrFail()
        ->schema;

    $errors = app(JsonSchemaValidator::class)->validate(['is_viable' => true], $schema);

    expect($errors)->not->toBeEmpty();
});

it('rejects a brief too short to draft from', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('icp.compose'), ['brief' => 'food'])
        ->assertSessionHasErrors('brief');
});

it('needs a signed-in member', function (): void {
    $this->post(route('icp.compose'), ['brief' => 'We are a design agency looking for food startups.'])
        ->assertRedirect(route('login'));
});
