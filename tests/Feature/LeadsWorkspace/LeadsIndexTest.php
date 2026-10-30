<?php

declare(strict_types=1);

use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Enums\EnrichmentStatus;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\ICP\Models\Icp;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('redirects a guest away from the leads list', function (): void {
    $this->get('/leads')->assertRedirect(route('login'));
});

it('lists the team’s leads with their grade and contact count', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee'], $icp, ScoreGrade::A, 88);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->count(2)->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('leads/Index')
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Bright Bean Coffee')
            ->where('leads.data.0.score.grade', 'A')
            ->where('leads.data.0.score.score', 88)
            ->where('leads.data.0.contacts_count', 2)
            ->where('leads.meta.total', 1)
            ->has('options.grades', 4)
            ->where('can.saveView', true));
});

it('shows a lead that has no score without one, rather than hiding it', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Ungraded Ltd']);

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.score', null));
});

it('only surfaces the grade taken against the ICP that is active now', function (): void {
    [$user, $team, $active] = Fixtures::team();

    $lead = Fixtures::lead($team, [], $active, ScoreGrade::A, 90);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $retired = Icp::factory()->create(['team_id' => $team->getKey()]);

        LeadScore::factory()->grade(ScoreGrade::D, 10)->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'icp_id' => $retired->getKey(),
        ]);
    });

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leads.data.0.score.grade', 'A'));
});

it('filters by grade', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Strong fit'], $icp, ScoreGrade::A, 90);
    Fixtures::lead($team, ['company_name' => 'Weak fit'], $icp, ScoreGrade::D, 12);

    $this->actingAs($user)
        ->get(route('leads.index', ['grades' => ['A']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Strong fit')
            ->where('filters.grades', ['A']));
});

it('filters by source and by enrichment state', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, [
        'company_name' => 'From the registry',
        'source' => LeadSource::CompaniesHouse,
        'enrichment_status' => EnrichmentStatus::Enriched,
    ]);
    Fixtures::lead($team, [
        'company_name' => 'From a search',
        'source' => LeadSource::WebSearch,
        'enrichment_status' => EnrichmentStatus::Pending,
        'dedupe_key' => 'domain:search.test',
    ]);

    $this->actingAs($user)
        ->get(route('leads.index', ['sources' => ['web_search']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'From a search'));

    $this->actingAs($user)
        ->get(route('leads.index', ['enrichment_statuses' => ['enriched']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'From the registry'));
});

it('searches company fields and contact details', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'company_number' => '11112222']);
    Fixtures::lead($team, ['company_name' => 'Unrelated Holdings', 'dedupe_key' => 'no:unrelated']);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'email' => 'hello@brightbean.test',
    ]));

    foreach (['Bright Bean', '11112222', 'brightbean.test'] as $term) {
        $this->actingAs($user)
            ->get(route('leads.index', ['search' => $term]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('leads.data', 1)
                ->where('leads.data.0.company_name', 'Bright Bean Coffee'));
    }
});

it('matches a contact against its own lead only', function (): void {
    [$user, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    Fixtures::lead($team, ['company_name' => 'Nothing To Do With It', 'dedupe_key' => 'no:other']);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'email' => 'hello@brightbean.test',
    ]));

    // The contact sub-query must stay correlated to its lead; a stray `or` in the raw clause would
    // make one matching contact pull every lead in the team into the results.
    $this->actingAs($user)
        ->get(route('leads.index', ['search' => 'brightbean.test']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Bright Bean Coffee'));
});

it('treats a wildcard in a search term as a character, not a wildcard', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => '100% Pure Ltd']);
    Fixtures::lead($team, ['company_name' => 'Something Else', 'dedupe_key' => 'no:else']);

    // Unescaped, `%` would match every lead in the team.
    $this->actingAs($user)
        ->get(route('leads.index', ['search' => '100%']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', '100% Pure Ltd'));
});

it('treats an underscore and the escape character itself as characters', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'a_b Trading']);
    Fixtures::lead($team, ['company_name' => 'axb Trading', 'dedupe_key' => 'no:axb']);
    Fixtures::lead($team, ['company_name' => 'Wow! Coffee', 'dedupe_key' => 'no:wow']);

    // Unescaped, `_` is LIKE's single-character wildcard and would also match "axb".
    $this->actingAs($user)
        ->get(route('leads.index', ['search' => 'a_b']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'a_b Trading'));

    // `!` is the ESCAPE character, so it has to be escaped to be searched for.
    $this->actingAs($user)
        ->get(route('leads.index', ['search' => 'Wow!']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Wow! Coffee'));
});

it('filters by country, as a code or as a place', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Dubai Co', 'country' => 'AE', 'locality' => 'Dubai']);
    Fixtures::lead($team, [
        'company_name' => 'London Co',
        'country' => 'GB',
        'locality' => 'London',
        'dedupe_key' => 'no:london',
    ]);

    $this->actingAs($user)
        ->get(route('leads.index', ['country' => 'ae']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'Dubai Co'));

    $this->actingAs($user)
        ->get(route('leads.index', ['country' => 'London']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 1)
            ->where('leads.data.0.company_name', 'London Co'));
});

it('sorts by fit, keeping ungraded leads in the list', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Middling'], $icp, ScoreGrade::C, 50);
    Fixtures::lead($team, ['company_name' => 'Best', 'dedupe_key' => 'no:best'], $icp, ScoreGrade::A, 95);
    Fixtures::lead($team, ['company_name' => 'Ungraded', 'dedupe_key' => 'no:ungraded']);

    $this->actingAs($user)
        ->get(route('leads.index', ['sort' => 'fit']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 3)
            ->where('leads.data.0.company_name', 'Best'));
});

it('sorts by company name, both ways', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::lead($team, ['company_name' => 'Zebra Ltd']);
    Fixtures::lead($team, ['company_name' => 'Acme Ltd', 'dedupe_key' => 'no:acme']);

    $this->actingAs($user)
        ->get(route('leads.index', ['sort' => 'company']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leads.data.0.company_name', 'Acme Ltd'));

    $this->actingAs($user)
        ->get(route('leads.index', ['sort' => 'company', 'direction' => 'desc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leads.data.0.company_name', 'Zebra Ltd'));
});

it('sorts newest first by default, and oldest first when asked', function (): void {
    [$user, $team] = Fixtures::team();

    $first = Fixtures::lead($team, ['company_name' => 'Older Ltd']);
    $last = Fixtures::lead($team, ['company_name' => 'Newer Ltd', 'dedupe_key' => 'no:newer']);

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leads.data.0.id', $last->getKey()));

    // The default ordering has to honour a direction too, or `direction=asc` would silently do
    // nothing on the sort the list opens with.
    $this->actingAs($user)
        ->get(route('leads.index', ['sort' => 'newest', 'direction' => 'asc']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('leads.data.0.id', $first->getKey()));
});

it('paginates, and keeps the filters on the page links', function (): void {
    [$user, $team] = Fixtures::team();

    app(TeamContext::class)->run($team, function () use ($team): void {
        for ($i = 1; $i <= 4; $i++) {
            Lead::factory()->create([
                'team_id' => $team->getKey(),
                'company_name' => 'Acme '.$i,
                'dedupe_key' => 'no:acme-'.$i,
            ]);
        }
    });

    $this->actingAs($user)
        ->get(route('leads.index', ['per_page' => 2, 'search' => 'Acme']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('leads.data', 2)
            ->where('leads.meta.total', 4)
            ->where('leads.meta.last_page', 2)
            // The page links must carry the search, or page 2 would be unfiltered.
            ->where('leads.meta.links.2.url', fn (string $url): bool => str_contains($url, 'search=Acme'))
            ->where('leads.links.next', fn (string $url): bool => str_contains($url, 'search=Acme')));
});

it('rejects a filter value that is not one of the enum’s', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->getJson(route('leads.index', ['grades' => ['Z']]))
        ->assertStatus(422)
        ->assertJsonValidationErrors('grades.0');

    $this->actingAs($user)
        ->getJson(route('leads.index', ['sort' => 'rowid']))
        ->assertStatus(422)
        ->assertJsonValidationErrors('sort');
});

it('loads a page of leads without a lazy load, whatever the page size', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    app(TeamContext::class)->run($team, function () use ($team, $icp): void {
        for ($i = 1; $i <= 5; $i++) {
            $lead = Lead::factory()->create([
                'team_id' => $team->getKey(),
                'company_name' => 'Acme '.$i,
                'dedupe_key' => 'no:acme-'.$i,
            ]);

            LeadScore::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'icp_id' => $icp->getKey(),
            ]);

            LeadContact::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
            ]);
        }
    });

    // shouldBeStrict is on outside production, but say so explicitly: this test exists to catch an
    // N+1 the moment a column is added to the list, and it must not depend on the app env.
    Model::preventLazyLoading();

    $this->actingAs($user)
        ->get(route('leads.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('leads.data', 5));
});

it('costs the same number of queries whatever the page size', function (): void {
    [$user, $team, $icp] = Fixtures::team();

    app(TeamContext::class)->run($team, function () use ($team, $icp): void {
        for ($i = 1; $i <= 15; $i++) {
            $lead = Lead::factory()->create([
                'team_id' => $team->getKey(),
                'company_name' => 'Acme '.$i,
                'dedupe_key' => 'no:acme-'.$i,
            ]);

            LeadScore::factory()->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
                'icp_id' => $icp->getKey(),
            ]);

            LeadContact::factory()->count(2)->create([
                'team_id' => $team->getKey(),
                'lead_id' => $lead->getKey(),
            ]);
        }
    });

    $queriesFor = function (int $perPage) use ($user): int {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->actingAs($user)
            ->get(route('leads.index', ['per_page' => $perPage]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->has('leads.data', $perPage));

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    // One warm-up first: the very first request of a test also pays for whatever the framework
    // resolves once (the session, the team lookup), which isn't what this is measuring.
    $queriesFor(5);

    // `preventLazyLoading` catches a lazy *relation* read; this catches any other per-row query —
    // a `withCount` turned into a loop, say — which would make the list cost grow with the page.
    expect($queriesFor(15))->toBe($queriesFor(5));
});
