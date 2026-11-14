<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\BusinessIntelligence\Enums\BackgroundCheckStatus;
use App\Modules\BusinessIntelligence\Models\BusinessIntelReport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\ICP\Enums\CriterionType;
use App\Modules\ICP\Enums\IcpSuggestionAction;
use App\Modules\ICP\Enums\IcpSuggestionStatus;
use App\Modules\ICP\Events\IcpUpdated;
use App\Modules\ICP\Models\Icp;
use App\Modules\ICP\Models\IcpSuggestion;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Onboarding\Models\BusinessProfile;
use App\Modules\Scoring\Enums\FeedbackRating;
use App\Modules\Scoring\Models\LeadScore;
use App\Modules\Scoring\Models\ScoreFeedback;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/*
 * Relevance P4: Alima proposes changes to an ideal customer profile and never makes one. Asking
 * stores proposals; a member accepts (an ordinary audited edit) or declines (kept, so it is not
 * raised again).
 */

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);
});

/**
 * An owner, their team's active profile, and the fake model.
 *
 * @return array{User, Team, Icp, FakeAiTransport}
 */
function bootSuggestions(): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $icp = app(TeamContext::class)->run($team, function () use ($team): Icp {
        BusinessProfile::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Kiln & Co',
            'industry' => 'Graphic design',
            'customer_examples' => ['Pollen Bakery'],
        ]);

        $icp = Icp::query()->create(['team_id' => $team->getKey(), 'name' => 'Food brands', 'is_active' => true]);

        foreach ([[CriterionType::Industry, 'Restaurants', 80], [CriterionType::Geography, 'Manchester', 40]] as $i => [$type, $value, $weight]) {
            $icp->criteria()->create(['team_id' => $team->getKey(), 'type' => $type, 'value' => $value, 'weight' => $weight, 'position' => $i]);
        }

        return $icp->load('criteria');
    });

    $transport = new FakeAiTransport;
    app()->instance(AiTransport::class, $transport);
    app()->forgetInstance(AiClient::class);

    return [$user, $team, $icp, $transport];
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function suggestion(array $overrides = []): array
{
    return [
        'action' => 'add', 'type' => 'industry', 'value' => 'Craft breweries', 'weight' => 70,
        'rationale' => 'Three leads marked good are breweries and the profile does not name them.',
        ...$overrides,
    ];
}

it('stores proposals and changes nothing in the profile', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();

    $transport->queueStructured(['suggestions' => [
        suggestion(),
        suggestion(['action' => 'reweight', 'value' => 'restaurants', 'weight' => 50, 'rationale' => '9 of 40 graded leads matched this.']),
        suggestion(['action' => 'remove', 'type' => 'geography', 'value' => 'Manchester', 'weight' => null, 'rationale' => 'No lead had a place on record.']),
        // Not about this profile: it already has this criterion, so there is nothing to add.
        suggestion(['value' => 'Restaurants']),
        // ...and it has no such criterion to remove.
        suggestion(['action' => 'remove', 'value' => 'Hotels', 'weight' => null]),
        // A re-weighting with no weight to move to.
        suggestion(['action' => 'reweight', 'value' => 'Restaurants', 'weight' => null]),
    ]]);

    $this->actingAs($user)
        ->post(route('icp.suggestions.store', ['icp' => $icp->id]))
        ->assertRedirect();

    $stored = app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->orderBy('id')->get());

    expect($stored)->toHaveCount(3)
        ->and($stored->pluck('status')->unique()->all())->toBe([IcpSuggestionStatus::Pending])
        ->and($stored[0]->action)->toBe(IcpSuggestionAction::Add)
        ->and($stored[2]->weight)->toBeNull()
        // The profile itself is exactly as it was: proposals are not edits.
        ->and($icp->fresh()->criteria->pluck('value')->all())->toBe(['Restaurants', 'Manchester']);

    // The model was given the seller, the profile as it stands and what the team has told us.
    expect($transport->requests[0]->prompt)
        ->toContain('Pollen Bakery')
        ->toContain('industry: Restaurants (weight 80)')
        ->toContain('No leads have been graded against this profile yet.');

    $this->actingAs($user)
        ->get(route('icp.index'))
        ->assertInertia(fn ($page) => $page->has('suggestions', 3)->where('suggestions.0.value', 'Craft breweries'));
});

it('applies an accepted suggestion through the ordinary profile update', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();
    Event::fake([IcpUpdated::class]);

    $transport->queueStructured(['suggestions' => [
        suggestion(),
        suggestion(['action' => 'reweight', 'value' => 'Restaurants', 'weight' => 50]),
        suggestion(['action' => 'remove', 'type' => 'geography', 'value' => 'Manchester', 'weight' => null]),
    ]]);

    $this->actingAs($user)->post(route('icp.suggestions.store', ['icp' => $icp->id]));

    $ids = app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->orderBy('id')->pluck('id'));

    foreach ($ids as $id) {
        $this->actingAs($user)->post(route('icp.suggestions.accept', ['suggestion' => $id]))->assertRedirect();
    }

    $criteria = $icp->fresh()->criteria->mapWithKeys(fn ($c) => [$c->value => $c->weight])->all();

    expect($criteria)->toBe(['Restaurants' => 50, 'Craft breweries' => 70])
        ->and(app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->where('status', IcpSuggestionStatus::Accepted)->count()))->toBe(3);

    // The same path a hand edit takes: the active profile changed, so scoring is told.
    Event::assertDispatched(IcpUpdated::class);

    // Answering twice is harmless.
    $this->actingAs($user)->post(route('icp.suggestions.accept', ['suggestion' => $ids[0]]))->assertRedirect();
    expect($icp->fresh()->criteria)->toHaveCount(2);
});

it('keeps a declined suggestion so the same idea is never raised twice', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();

    $transport->queueStructured(['suggestions' => [suggestion()]]);
    $this->actingAs($user)->post(route('icp.suggestions.store', ['icp' => $icp->id]));

    $id = app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->sole()->getKey());
    $this->actingAs($user)->post(route('icp.suggestions.decline', ['suggestion' => $id]))->assertRedirect();

    // The profile has moved on since, so this is a fresh question rather than a cached answer.
    app(TeamContext::class)->run($team, fn () => $icp->update(['name' => 'Food and drink brands']));

    // Asked again, the model offers the same idea in different casing, plus a new one.
    $transport->queueStructured(['suggestions' => [
        suggestion(['value' => 'craft  BREWERIES']),
        suggestion(['value' => 'Distilleries']),
    ]]);
    $this->actingAs($user)->post(route('icp.suggestions.store', ['icp' => $icp->id]));

    $rows = app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->orderBy('id')->get());

    expect($rows)->toHaveCount(2)
        ->and($rows[0]->status)->toBe(IcpSuggestionStatus::Declined)
        ->and($rows[1]->value)->toBe('Distilleries')
        ->and($icp->fresh()->criteria)->toHaveCount(2);
});

it('tells the model what members marked and what searches left out, without naming a company', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();

    app(TeamContext::class)->run($team, function () use ($team, $icp, $user): void {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'company_name' => 'Hollin Brook Brewery',
            'industry' => 'Craft brewery',
            'employee_range' => '11-50',
            'locality' => 'Stockport',
        ]);

        $score = LeadScore::factory()->create(['team_id' => $team->getKey(), 'lead_id' => $lead->getKey(), 'icp_id' => $icp->getKey()]);

        ScoreFeedback::query()->create([
            'team_id' => $team->getKey(), 'lead_score_id' => $score->getKey(), 'user_id' => $user->getKey(),
            'rating' => FeedbackRating::Good, 'scored_value' => $score->score, 'scored_grade' => $score->grade,
        ]);

        DiscoveryRun::factory()->create([
            'team_id' => $team->getKey(), 'icp_id' => $icp->getKey(), 'source' => LeadSource::WebSearch,
            'leads_rejected' => 1,
            'rejections' => [['name' => 'Studio Eight', 'verdict' => 'competitor', 'reason' => 'A packaging design studio.']],
        ]);
    });

    $transport->queueStructured(['suggestions' => []]);
    $this->actingAs($user)->post(route('icp.suggestions.store', ['icp' => $icp->id]))->assertRedirect();

    expect($transport->requests[0]->prompt)
        ->toContain('trade: Craft brewery; size: 11-50; place: Stockport')
        ->toContain('competitor: A packaging design studio.')
        // Evidence about the profile, not about anybody: no company is named.
        ->not->toContain('Hollin Brook Brewery')
        ->not->toContain('Studio Eight');
});

it('is closed to another team and to a member who cannot edit the profile', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();

    $transport->queueStructured(['suggestions' => [suggestion()]]);
    $this->actingAs($user)->post(route('icp.suggestions.store', ['icp' => $icp->id]));
    $id = app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->sole()->getKey());

    $outsider = createUserWithTeam();

    $this->actingAs($outsider)->post(route('icp.suggestions.store', ['icp' => $icp->id]))->assertNotFound();
    $this->actingAs($outsider)->post(route('icp.suggestions.accept', ['suggestion' => $id]))->assertNotFound();
    $this->actingAs($outsider)->post(route('icp.suggestions.decline', ['suggestion' => $id]))->assertNotFound();

    $this->actingAs($outsider)
        ->get(route('icp.index'))
        ->assertInertia(fn ($page) => $page->where('suggestions', []));

    expect(app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->sole()->status))->toBe(IcpSuggestionStatus::Pending)
        ->and(app(TeamContext::class)->run($team, fn () => $icp->fresh()->criteria))->toHaveCount(2);
});

it('refuses cleanly when the AI allowance is spent, storing nothing', function (): void {
    [$user, $team, $icp, $transport] = bootSuggestions();
    $transport->queueFailure(AiCreditExceededException::make());

    // A web request is sent back with the reason, as every domain refusal on a form is; the
    // status code is the API's concern.
    $this->actingAs($user)
        ->from(route('icp.index'))
        ->post(route('icp.suggestions.store', ['icp' => $icp->id]))
        ->assertRedirect(route('icp.index'));

    expect(app(TeamContext::class)->run($team, fn () => IcpSuggestion::query()->count()))->toBe(0);
});

it('seeds size, place and buying signals from what the background check read', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn () => BusinessProfile::factory()->completed()->create([
        'team_id' => $team->getKey(),
        'locations' => ['Leeds'],
    ]));

    $report = BusinessIntelReport::factory()->create([
        'team_id' => $team->getKey(),
        'status' => BackgroundCheckStatus::Ready,
        'findings' => backgroundFindings([
            'customer_size_range' => '10-200 employees',
            'delivery_model' => 'local',
            'buying_triggers' => ['An EICR falling due'],
            'anti_customers' => ['Homeowners'],
        ]),
    ]);

    $this->actingAs($user)
        ->post(route('business-intel.accept', ['report' => $report->id]), ['seed_icp' => true])
        ->assertRedirect();

    $criteria = app(TeamContext::class)->run($team, fn () => Icp::query()->with('criteria')->sole()->criteria);
    $of = fn (CriterionType $type): array => $criteria->where('type', $type)->pluck('value')->values()->all();

    expect($of(CriterionType::CompanySize))->toBe(['10-200 employees'])
        // In person and local, so tied to where the business is based.
        ->and($of(CriterionType::Geography))->toBe(['Leeds'])
        ->and($of(CriterionType::BuyingSignal))->toBe(['An EICR falling due'])
        ->and($of(CriterionType::Exclusion))->toContain('Homeowners');
});
