<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\LeadsWorkspace\Models\LeadAssignment;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Listeners\AnnounceAccountSignals;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalMatchConfidence;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Enums\WatchlistEntityType;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Events\AccountSignalsDetected;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Services\SignalLeadMatcher;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Testing\FakeNewsSearchClient;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\NotificationFixtures as Notifications;

uses(RefreshDatabase::class);

/**
 * Events about the accounts a team already works (S10, ADR 0021).
 *
 * Three things are being pinned down here, and the order matters. **What is searched** — bare
 * names, a window of a long list at a time. **What counts as a match** — the team's own statement
 * or an identical name, never a guess. And **what a match does** — one timeline entry, one
 * notification to the owner, and an "add to this lead" that creates nothing.
 */
beforeEach(function (): void {
    // The type's default channels include mail, and this phase is not a test of the mailer.
    Mail::fake();

    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeNewsSearchClient;
    app()->instance(NewsSearchClient::class, $this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

/** A lead this team is working. */
function accountLead(Team $team, string $name, array $attributes = []): Lead
{
    return app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'company_name' => $name,
    ], $attributes)));
}

/**
 * A manual list holding these names, and a watch pointed at it.
 *
 * @param  list<array{0: string, 1: ?Lead}>  $names  name and, where the team said so, its lead
 */
function accountWatch(Team $team, array $names, array $watchAttributes = []): SignalWatch
{
    return app(TeamContext::class)->run($team, function () use ($team, $names, $watchAttributes): SignalWatch {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
            'name' => 'Accounts I care about',
        ]);

        foreach ($names as [$name, $lead]) {
            SignalWatchlistEntry::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'entity_type' => WatchlistEntityType::Company,
                'name' => $name,
                'lead_id' => $lead?->getKey(),
            ]);
        }

        $list->forceFill(['entries_count' => count($names)])->save();

        return SignalWatch::factory()
            ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
            ->create(array_merge([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
                'locations' => ['Dubai'],
            ], $watchAttributes));
    });
}

function runAccountWatch(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

/** The AI's answer for one genuine funding event about `$company`. */
function eventAbout(string $company, int $index = 0, ?string $person = null, ?string $counterparty = null): array
{
    return [
        'item_index' => $index,
        'is_event' => true,
        'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'subject_person_name' => $person,
        'subject_person_role' => $person === null ? null : 'Founder & CEO',
        'subject_company_name' => $company,
        'counterparty_name' => $counterparty,
        'amount' => 40_000_000,
        'currency' => 'USD',
        'location' => 'Dubai',
        'confidence' => 90,
        'relevance' => 80,
        'summary' => $company.' raised a $40m round.',
        'suggested_approach' => 'Ask a mutual connection for an introduction.',
    ];
}

// --- What an entity watch searches --------------------------------------------------------------

it('searches a watchlist by bare company name, with no keyword and no quotes', function (): void {
    $team = Team::factory()->create();
    $watch = accountWatch($team, [['Acme Engineering Ltd', null], ['Borealis Trading LLC', null]]);

    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    runAccountWatch($team, $watch);

    $queries = array_map(static fn (SignalQuery $q): string => $q->q, $this->fakeNews->queries());

    // The spike settled both halves of this: every quoted query returned nothing, and appending a
    // keyword *lost* genuine results. So a company is searched by name alone and the classifier
    // decides whether what comes back is an event of a watched type.
    expect($queries)->toBe(['Acme Engineering Ltd', 'Borealis Trading LLC']);
});

it('qualifies a person by a keyword, because a name alone is every namesake alive', function (): void {
    $team = Team::factory()->create();

    $watch = app(TeamContext::class)->run($team, function () use ($team): SignalWatch {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'entity_type' => WatchlistEntityType::Person,
            'name' => 'Jane Founder',
        ]);

        return SignalWatch::factory()
            ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
            ->create([
                'team_id' => $team->getKey(),
                'signal_watchlist_id' => $list->getKey(),
            ]);
    });

    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    runAccountWatch($team, $watch);

    $queries = array_map(static fn (SignalQuery $q): string => $q->q, $this->fakeNews->queries());

    expect($queries)->not->toBe(['Jane Founder'])
        ->and($queries[0] ?? '')->toStartWith('Jane Founder ');
});

it('covers a long list a window at a time, and comes round again', function (): void {
    config()->set('signals.watchlist.window', 2);

    $team = Team::factory()->create();
    $watch = accountWatch($team, [
        ['First Ltd', null],
        ['Second Ltd', null],
        ['Third Ltd', null],
    ]);

    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);
    runAccountWatch($team, $watch);

    // The fake records every query it was ever asked, so the second run's are the tail.
    $asked = static fn (array $queries): array => array_map(static fn (SignalQuery $q): string => $q->q, $queries);

    expect($asked($this->fakeNews->queries()))->toBe(['First Ltd', 'Second Ltd'])
        ->and((int) $watch->refresh()->entity_cursor)->toBe(2);

    // The next run continues where it stopped and **wraps**, rather than trimming to the first two
    // forever — which is how the eighty-ninth account on a real list would never be searched at all.
    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);
    runAccountWatch($team, $watch);

    expect(array_slice($asked($this->fakeNews->queries()), 2))->toBe(['Third Ltd', 'First Ltd'])
        ->and((int) $watch->refresh()->entity_cursor)->toBe(1);
});

it('searches nothing when the list it is pointed at is paused or empty', function (): void {
    $team = Team::factory()->create();
    $watch = accountWatch($team, [['Acme Engineering Ltd', null]]);

    app(TeamContext::class)->run($team, function () use ($watch): void {
        $watch->watchlist?->forceFill(['is_active' => false])->save();
    });

    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runAccountWatch($team, $watch->refresh());

    // A paused list searches nothing rather than falling back to places: the member said what they
    // wanted watched, and a surprise bill for a city they did not ask about is the alternative.
    expect($this->fakeNews->queries())->toBe([])
        ->and($run->queries_count)->toBe(0);
});

// --- What counts as a match ---------------------------------------------------------------------

it('treats the team’s own statement and an identical name as exact', function (): void {
    $team = Team::factory()->create();
    $lead = accountLead($team, 'Acme Engineering Ltd');

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
        ]);

        // The team said "this name is that lead" — a trading name their own record does not carry.
        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Acme Engineering',
            'lead_id' => $lead->getKey(),
        ]);
    });

    $matcher = app(SignalLeadMatcher::class);
    $teamId = (int) $team->getKey();

    $declared = app(TeamContext::class)->run($team, fn () => $matcher->match($teamId, 'Acme Engineering'));
    $identical = app(TeamContext::class)->run($team, fn () => $matcher->match($teamId, 'ACME  ENGINEERING LTD'));

    expect($declared?->confidence)->toBe(SignalMatchConfidence::Exact)
        ->and($declared?->lead->getKey())->toBe($lead->getKey())
        ->and($identical?->confidence)->toBe(SignalMatchConfidence::Exact);
});

it('calls a name that agrees apart from the legal form likely, not exact', function (): void {
    $team = Team::factory()->create();
    $lead = accountLead($team, 'Acme Engineering Ltd');

    $teamId = (int) $team->getKey();
    $match = app(TeamContext::class)->run($team, fn () => app(SignalLeadMatcher::class)->match($teamId, 'Acme Engineering'));

    expect($match?->lead->getKey())->toBe($lead->getKey())
        ->and($match?->confidence)->toBe(SignalMatchConfidence::Likely)
        // Shown to the member, because the way this fails is an event pinned to the wrong company.
        ->and($match?->confidence->warrantsConfirmation())->toBeTrue();
});

it('refuses a substring, a person’s name alone, and an erased lead', function (): void {
    $team = Team::factory()->create();

    accountLead($team, 'Acme Holdings Ltd');
    accountLead($team, 'Erased Ltd', ['anonymised_at' => now()]);

    app(TeamContext::class)->run($team, function () use ($team): void {
        $lead = Lead::factory()->create(['team_id' => $team->getKey(), 'company_name' => 'Peoplewatch Ltd']);

        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Jane Founder',
        ]);
    });

    app(TeamContext::class)->run($team, function () use ($team): void {
        $matcher = app(SignalLeadMatcher::class);
        $teamId = (int) $team->getKey();

        expect($matcher->match($teamId, 'Acme'))->toBeNull()
            ->and($matcher->match($teamId, 'Acmetech'))->toBeNull()
            // A contact called Jane Founder at an unrelated lead is a coincidence.
            ->and($matcher->match($teamId, null, null, 'Jane Founder'))->toBeNull()
            // Compliance deleted the name; matching on it would put it back in front of somebody.
            ->and($matcher->match($teamId, 'Erased Ltd'))->toBeNull();
    });
});

it('tries the counterparty only when the subject matched nothing', function (): void {
    $team = Team::factory()->create();
    $buyer = accountLead($team, 'Borealis Trading LLC');

    app(TeamContext::class)->run($team, function () use ($team, $buyer): void {
        $matcher = app(SignalLeadMatcher::class);

        // "Our lead bought somebody" is news about our lead.
        expect($matcher->match((int) $team->getKey(), 'Someone Else Ltd', 'Borealis Trading LLC')?->lead->getKey())
            ->toBe($buyer->getKey());
    });
});

// --- Pointing a watch at a list -----------------------------------------------------------------

it('lets a member point a watch at a list, and refuses another team’s', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $list = app(TeamContext::class)->run($team, function () use ($team): SignalWatchlist {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
            'name' => 'Accounts I care about',
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Acme Engineering Ltd',
        ]);

        $list->forceFill(['entries_count' => 1])->save();

        return $list;
    });

    // The editor is offered it, with what it holds — a picker that offered an empty list would be
    // offering a watch that searches nothing.
    $this->actingAs($user)
        ->get(route('signal-watches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('options.watchlists', 1)
            ->where('options.watchlists.0.id', (int) $list->getKey())
            ->where('options.watchlists.0.entries_count', 1));

    $payload = [
        'name' => 'My accounts',
        'locations' => ['Dubai'],
        'regions' => ['ae'],
        'signal_definition_ids' => signalDefinitionIds($team),
        'cadence' => 'daily',
        'is_active' => true,
        'signal_watchlist_id' => $list->getKey(),
    ];

    $this->actingAs($user)
        ->post(route('signal-watches.store'), $payload)
        ->assertRedirect(route('signal-watches.index'));

    $watch = app(TeamContext::class)->run($team, fn (): ?SignalWatch => SignalWatch::query()->latest('id')->first());

    expect((int) $watch?->signal_watchlist_id)->toBe((int) $list->getKey());

    // The screen says which list it follows rather than the places it is not searching.
    $this->actingAs($user)
        ->get(route('signal-watches.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('watches.0.watchlist_name', 'Accounts I care about'));

    // Another team's list is **rejected**, not dropped: a watch silently pointed at nothing would
    // look configured and quietly search places instead.
    $other = createUserWithTeam();
    $theirList = app(TeamContext::class)->run($other->currentTeam()->firstOrFail(), fn (): SignalWatchlist => SignalWatchlist::factory()->create([
        'team_id' => $other->currentTeamId(),
        'source_kind' => WatchlistSourceKind::Manual,
    ]));

    $this->actingAs($user)
        ->post(route('signal-watches.store'), [...$payload, 'signal_watchlist_id' => $theirList->getKey()])
        ->assertSessionHasErrors('signal_watchlist_id');
});

it('takes as many accounts as the run can afford, not as many as the window allows', function (): void {
    // Two regions means two queries per account, so a budget of 4 covers two accounts — and the
    // cursor must stop at two. Advancing by "entities taken" would step past accounts the trim
    // dropped, losing them on every lap: the exact failure the ring exists to prevent.
    config()->set('signals.max_queries_per_run', 4);
    config()->set('signals.watchlist.window', 40);

    $team = Team::factory()->create();
    $watch = accountWatch($team, [
        ['First Ltd', null],
        ['Second Ltd', null],
        ['Third Ltd', null],
        ['Fourth Ltd', null],
    ], ['regions' => ['ae', 'gb']]);

    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);
    runAccountWatch($team, $watch);

    $asked = array_map(static fn (SignalQuery $q): string => $q->q, $this->fakeNews->queries());

    expect(array_values(array_unique($asked)))->toBe(['First Ltd', 'Second Ltd'])
        ->and(count($asked))->toBe(4)
        ->and((int) $watch->refresh()->entity_cursor)->toBe(2);

    // And the next run picks up at the third rather than the fifth.
    $this->fakeNews->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);
    runAccountWatch($team, $watch);

    expect(array_values(array_unique(array_slice(array_map(static fn (SignalQuery $q): string => $q->q, $this->fakeNews->queries()), 4))))
        ->toBe(['Third Ltd', 'Fourth Ltd']);
});

it('never matches another team’s lead, even with no team context set', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    accountLead($theirs, 'Acme DMCC');

    // No ambient context at all: `BelongsToTeam` applies no scope when there is none, so a matcher
    // that relied on it would match every tenant's leads and write another team's id.
    app(TeamContext::class)->forget();

    $match = app(SignalLeadMatcher::class)->match((int) $mine->getKey(), 'Acme DMCC');

    expect($match)->toBeNull();
});

it('calls a watchlist name that needed its legal form set aside “likely”, not exact', function (): void {
    $team = Team::factory()->create();
    $lead = accountLead($team, 'Delta Freight Ltd');

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'source_kind' => WatchlistSourceKind::Manual,
        ]);

        SignalWatchlistEntry::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watchlist_id' => $list->getKey(),
            'name' => 'Delta Freight',
            'lead_id' => $lead->getKey(),
        ]);
    });

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $matcher = app(SignalLeadMatcher::class);

        // The team's own statement about the exact name is Exact...
        expect($matcher->match((int) $team->getKey(), 'Delta Freight')?->confidence)
            ->toBe(SignalMatchConfidence::Exact);

        // ...but "Delta Freight Inc" in an article only agrees once the legal form is dropped, and
        // labelling that Exact would suppress the confirmation prompt the weaker case exists for.
        $looser = $matcher->match((int) $team->getKey(), 'Delta Freight Inc');

        expect($looser?->lead->getKey())->toBe($lead->getKey())
            ->and($looser?->confidence)->toBe(SignalMatchConfidence::Likely);
    });
});

it('refuses to choose between two leads that agree only without their legal forms', function (): void {
    $team = Team::factory()->create();

    accountLead($team, 'Acme Ltd');
    accountLead($team, 'Acme Inc');

    // Two different companies, or two records nobody has merged. Either way it is a question, not
    // an answer, so the matcher says nothing rather than picking whichever row came back first.
    app(TeamContext::class)->run($team, function () use ($team): void {
        expect(app(SignalLeadMatcher::class)->match((int) $team->getKey(), 'Acme'))->toBeNull();
    });
});

// --- What a match does --------------------------------------------------------------------------

it('matches a run’s signals to the team’s leads and writes one timeline entry each', function (): void {
    Event::fake([AccountSignalsDetected::class]);

    $team = Team::factory()->create();
    $lead = accountLead($team, 'Acme DMCC');
    $watch = accountWatch($team, [['Acme DMCC', $lead]]);

    $this->fakeNews->willReturn([
        $this->fakeNews->item('Acme closes $40m Series A', 'https://gulfbusiness.com/acme'),
    ]);
    $this->fakeAi->queueStructured(['events' => [eventAbout('Acme DMCC')]]);

    $run = runAccountWatch($team, $watch);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($run->signals_created)->toBe(1)
        ->and($signal?->matched_lead_id)->toBe($lead->getKey())
        ->and($signal?->match_confidence)->toBe(SignalMatchConfidence::Exact)
        // Matched, not converted: nothing was created, and the member still decides.
        ->and($signal?->status)->toBe(SignalStatus::New)
        ->and($signal?->lead_id)->toBeNull();

    $entries = app(TeamContext::class)->run($team, fn (): int => LeadActivity::query()
        ->where('lead_id', $lead->getKey())
        ->where('type', LeadActivityType::SignalMatched)
        ->count());

    expect($entries)->toBe(1);

    Event::assertDispatched(
        AccountSignalsDetected::class,
        fn (AccountSignalsDetected $e): bool => $e->count === 1 && $e->teamId === (int) $team->getKey(),
    );
});

it('writes no second timeline entry when the same article comes back tomorrow', function (): void {
    $team = Team::factory()->create();
    $lead = accountLead($team, 'Acme DMCC');
    $watch = accountWatch($team, [['Acme DMCC', $lead]]);

    $article = $this->fakeNews->item('Acme closes $40m Series A', 'https://gulfbusiness.com/acme');

    $this->fakeNews->willReturn([$article]);
    $this->fakeAi->queueStructured(['events' => [eventAbout('Acme DMCC')]]);
    runAccountWatch($team, $watch);

    $this->fakeNews->willReturn([$article]);
    $this->fakeAi->queueStructured(['events' => [eventAbout('Acme DMCC')]]);
    runAccountWatch($team, $watch->refresh());

    // Idempotent for free: a re-run inserts no signal, so there is nothing new to record and
    // nobody to tell twice.
    $counts = app(TeamContext::class)->run($team, fn (): array => [
        'signals' => Signal::query()->count(),
        'entries' => LeadActivity::query()
            ->where('lead_id', $lead->getKey())
            ->where('type', LeadActivityType::SignalMatched)
            ->count(),
    ]);

    expect($counts)->toBe(['signals' => 1, 'entries' => 1]);
});

it('tells the member working the account, not the whole team', function (): void {
    [$owner, $team] = Notifications::team();
    $bystander = Notifications::member($team);

    $lead = accountLead($team, 'Acme DMCC');

    app(TeamContext::class)->run($team, function () use ($team, $lead, $owner): void {
        LeadAssignment::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'user_id' => $owner->getKey(),
        ]);
    });

    $run = app(TeamContext::class)->run($team, function () use ($team, $lead): SignalRun {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        // Two matched signals on the one lead, which is what aggregation has to survive.
        Signal::factory()->count(2)->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Exact,
        ]);

        return $run;
    });

    app(AnnounceAccountSignals::class)->handle(
        new AccountSignalsDetected((int) $team->getKey(), (int) $run->getKey(), 2)
    );

    $rows = Notification::query()
        ->where('type', 'alima.'.NotificationType::SignalOnAccount->value)
        ->get(['notifiable_id', 'data'])
        ->all();

    expect($rows)->toHaveCount(1)
        ->and((int) $rows[0]->notifiable_id)->toBe((int) $owner->getKey())
        // One notification, not two: a daily watch is otherwise a feature somebody switches off
        // inside a week. The count is **accounts** — two events at one company is news about one
        // account — with the event count carried beside it.
        ->and($rows[0]->params()['count'] ?? null)->toBe(1)
        ->and($rows[0]->params()['events'] ?? null)->toBe(2)
        // Keys and params, never prose: the wording is composed at render time (EN/AR).
        ->and($rows[0]->data)->not->toHaveKey('headline');

    expect(Notification::query()->where('notifiable_id', $bystander->getKey())->count())->toBe(0);
});

it('falls back to the team when nobody owns the lead', function (): void {
    [$member, $team] = Notifications::team();

    $lead = accountLead($team, 'Acme DMCC');

    $run = app(TeamContext::class)->run($team, function () use ($team, $lead): SignalRun {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Exact,
        ]);

        return $run;
    });

    app(AnnounceAccountSignals::class)->handle(
        new AccountSignalsDetected((int) $team->getKey(), (int) $run->getKey(), 1)
    );

    // An unassigned lead with an acquisition against it is exactly the lead somebody should pick
    // up; saying nothing because it has no owner would be the wrong silence.
    expect(Notification::query()
        ->where('notifiable_id', $member->getKey())
        ->where('type', 'alima.'.NotificationType::SignalOnAccount->value)
        ->count())->toBe(1);
});

// --- Add to the lead ----------------------------------------------------------------------------

it('adds a matched event to the lead without creating a second one', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    // A record with a gap: the article knows the website, the team's row does not.
    $lead = accountLead($team, 'Acme DMCC', ['website' => null]);

    $signal = app(TeamContext::class)->run($team, function () use ($team, $lead): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'subject_company_name' => 'Acme DMCC',
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Exact,
        ]);
    });

    $this->actingAs($user)
        ->post(route('signals.attach', ['signal' => $signal->getKey()]))
        ->assertRedirect(route('leads.show', ['lead' => $lead->getKey()]));

    $state = app(TeamContext::class)->run($team, fn (): array => [
        'leads' => Lead::query()->count(),
        'company' => Lead::query()->whereKey($lead->getKey())->value('company_name'),
        'status' => Signal::query()->whereKey($signal->getKey())->value('status')?->value,
        'lead_id' => (int) Signal::query()->whereKey($signal->getKey())->value('lead_id'),
    ]);

    expect($state['leads'])->toBe(1)
        // The lead's own name wins: the article's spelling of it is not an improvement on the
        // record the team has been working.
        ->and($state['company'])->toBe('Acme DMCC')
        ->and($state['status'])->toBe(SignalStatus::Converted->value)
        ->and($state['lead_id'])->toBe((int) $lead->getKey());

    $timeline = app(TeamContext::class)->run($team, fn (): int => LeadActivity::query()
        ->where('lead_id', $lead->getKey())
        ->where('type', LeadActivityType::SignalConverted)
        ->count());

    expect($timeline)->toBe(1);
});

it('refuses to add an event that is not about any lead', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'matched_lead_id' => null,
        ]);
    });

    // The match was shown and the lead was deleted in another tab, or somebody posted the route
    // by hand. Either way there is nothing to attach to, and no lead is invented.
    $this->actingAs($user)
        ->post(route('signals.attach', ['signal' => $signal->getKey()]))
        ->assertRedirect();

    expect(app(TeamContext::class)->run($team, fn (): int => Lead::query()->count()))->toBe(0);
});

it('shows the warm half of the feed on its own', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $lead = accountLead($team, 'Acme DMCC');

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'headline' => 'Acme closes $40m Series A',
            'matched_lead_id' => $lead->getKey(),
            'match_confidence' => SignalMatchConfidence::Likely,
        ]);

        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'headline' => 'A stranger came into money',
            'matched_lead_id' => null,
        ]);
    });

    $this->actingAs($user)
        ->get('/signals?scope=accounts')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/Index')
            ->has('signals.data', 1)
            ->where('signals.data.0.headline', 'Acme closes $40m Series A')
            ->where('signals.data.0.matched_lead.company_name', 'Acme DMCC')
            // The confidence travels with the match, because the member is owed it.
            ->where('signals.data.0.matched_lead.needs_confirmation', true)
            // The tab counts answer the same question the tab does.
            ->where('counts.new', 1)
            ->where('filters.scope', 'accounts')
            ->has('options.scopes', 2));

    $this->actingAs($user)
        ->get('/signals')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('signals.data', 2)->where('counts.new', 2));
});
