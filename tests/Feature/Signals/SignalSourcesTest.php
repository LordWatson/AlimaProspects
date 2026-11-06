<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalCostEstimator;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Support\SignalSourceBreakdown;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Signal sources as a registry (S13a, ADR 0023).
 *
 * A definition stopped meaning "a Serper /news query", and this file is where that claim is held to:
 * which sources a type runs on, what happens when one of them refuses a query, what the run records
 * about where the money went, and what a signal remembers about what found it.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->news = new FakeSignalSource(SignalSourceKind::News);
    $this->web = new FakeSignalSource(SignalSourceKind::WebSearch);
    app(SignalSourceRegistry::class)->register($this->news);
    app(SignalSourceRegistry::class)->register($this->web);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);

    config()->set('signals.sources.news.cost_micros_per_request', 1_000);
    config()->set('signals.sources.web_search.cost_micros_per_request', 1_500);
});

/** A definition that runs on the given sources. */
function definitionOn(Team $team, array $kinds, string $key = SignalPresetCatalogue::FUNDING_ROUND): SignalDefinition
{
    $definition = signalDefinition($team, $key);

    return app(TeamContext::class)->run($team, function () use ($definition, $kinds): SignalDefinition {
        $definition->forceFill([
            'source_keys' => array_map(static fn (SignalSourceKind $kind): string => $kind->value, $kinds),
        ])->save();

        return $definition->refresh();
    });
}

function watchOver(Team $team, array $definitions): SignalWatch
{
    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watching($definitions)
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai']]));
}

function runWatch(Team $team, SignalWatch $watch): SignalRun
{
    return app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));
}

// --- Which sources a definition asks for ------------------------------------------------------

it('runs a pre-S13 definition on the news and nothing else', function (): void {
    $team = Team::factory()->create();
    $definition = signalDefinition($team);

    // The migration backfilled every existing row, and the accessor falls back for anything a
    // later code path writes as null — a type that searched nowhere would complete a run
    // successfully having found nothing, which is the worst failure this feature could have.
    app(TeamContext::class)->run($team, fn () => $definition->forceFill(['source_keys' => null])->save());

    expect($definition->refresh()->sourceKinds())->toBe([SignalSourceKind::News]);

    $this->news->willReturn([$this->news->item('Acme raises', 'https://gulfbusiness.com/a')]);
    $this->fakeAi->queueStructured(['events' => []]);

    runWatch($team, watchOver($team, [$definition]));

    expect($this->news->queries())->not->toBeEmpty()
        ->and($this->web->queries())->toBeEmpty();
});

it('asks every template of every source the definition names', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    $this->news->willReturn([]);
    $this->web->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    runWatch($team, watchOver($team, [$definition]));

    $templates = count($definition->query_templates);

    // One query per template per source — the same text asked of two places is two searches, which
    // is the cost consequence the type form has to show.
    expect($this->news->queries())->toHaveCount($templates)
        ->and($this->web->queries())->toHaveCount($templates);

    foreach ($this->web->queries() as $query) {
        expect($query->kind)->toBe(SignalSourceKind::WebSearch);
    }
});

it('skips a stored source key that has no provider rather than failing the run', function (): void {
    $team = Team::factory()->create();

    // A source withdrawn between deploys, or one whose credential was pulled. A scheduled watch
    // must not die at three in the morning because a source went away.
    $definition = definitionOn($team, [SignalSourceKind::News]);
    app(TeamContext::class)->run($team, fn () => $definition
        ->forceFill(['source_keys' => ['news', 'companies_house']])
        ->save());

    $this->news->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition->refresh()]));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($this->news->queries())->not->toBeEmpty();
});

it('contributes no queries from a source that declines the definition', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    // `supports()` is a decline, not a failure: a source asked for a definition it cannot serve
    // answers false, and turning that into a failed run would make a misconfiguration fatal.
    $this->web->willDecline();
    $this->news->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($this->web->queries())->toBeEmpty()
        ->and($this->news->queries())->not->toBeEmpty();
});

// --- What a signal remembers about what found it ----------------------------------------------

it('records which source found a signal and which template did', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::WebSearch]);

    $this->web->willReturn([
        $this->web->item('Acme opens a London office', 'https://example.co.uk/acme', source: 'example.co.uk'),
    ]);
    $this->fakeAi->queueStructured(['events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND)]]);

    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->signals_created)->toBe(1);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($signal->source_kind)->toBe(SignalSourceKind::WebSearch)
        // The query that found it — the column S12 recorded as missing from its explanation panel.
        ->and($signal->matched_template)->not->toBeNull()
        ->and($signal->matched_template)->toBe($this->web->queries()[0]->q);
});

it('defaults a signal to news, so every pre-S13 row answers the question truthfully', function (): void {
    $team = Team::factory()->create();
    $definition = signalDefinition($team);

    $this->news->willReturn([$this->news->item('Acme raises', 'https://gulfbusiness.com/a', source: 'Gulf Business')]);
    $this->fakeAi->queueStructured(['events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND)]]);

    runWatch($team, watchOver($team, [$definition]));

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($signal->source_kind)->toBe(SignalSourceKind::News);
});

it('keeps one signal when two sources return the same article', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    // The news and the wider web routinely return the same story. It is one event that happens to
    // have two ways in, and the first writer — news, by enum order — is the attribution, because a
    // news item carries an outlet name and a /search hit carries a hostname.
    $this->news->willReturn([$this->news->item('Acme raises', 'https://gulfbusiness.com/acme', source: 'Gulf Business')]);
    $this->web->willReturn([$this->web->item('Acme raises', 'https://gulfbusiness.com/acme', source: 'gulfbusiness.com')]);
    $this->fakeAi->queueStructured(['events' => [aiEventFor(SignalPresetCatalogue::FUNDING_ROUND)]]);

    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->signals_created)->toBe(1);

    $signal = app(TeamContext::class)->run($team, fn (): ?Signal => Signal::query()->first());

    expect($signal->source_kind)->toBe(SignalSourceKind::News);
});

// --- A refused query is not a failed run ------------------------------------------------------

it('skips a query the source refuses and completes the run', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);
    $watch = watchOver($team, [$definition]);

    $this->news->willReturn([$this->news->item('Acme raises', 'https://gulfbusiness.com/a', source: 'Gulf Business')]);
    $this->fakeAi->queueStructured(['events' => []]);

    // Before S13 this raised a **retryable** failure, so one template a member had typed with a
    // quote in it failed their whole watch, three times over, re-paying for every other query in
    // the plan each time — and reported that we couldn't reach the news service.
    $firstTemplate = app(TeamContext::class)->run($team, function () use ($watch, $definition): string {
        $plan = app(SignalQueryBuilder::class)->build($watch, [$definition]);

        return $plan->queries[0]->q;
    });

    $this->news->willFailQuery($firstTemplate, SignalsException::sourceQueryRejected('news'));

    $run = runWatch($team, $watch);

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->error)->toBeNull()
        // Counted apart from the queries that ran, because it never ran: folding it in would make
        // cost-per-query wrong on the admin screen.
        ->and($run->source_breakdown['news']['rejected'])->toBe(1);
});

it('fails the run when a source refuses every query, rather than completing green', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);

    $this->news->willFail(SignalsException::sourceQueryRejected('news'));

    // **The review's first finding, and the one that changed a decision.** ADR 0023 §4 originally
    // said a run whose every query was refused completes having found nothing, because nothing is
    // broken. That holds when *some* queries are refused — but a source that answers nothing at all
    // is either a member whose every template is unusable, or something systemic arriving as a 400:
    // an exhausted account, a parameter we send wrongly, a provider that changed its rules. A green
    // run with "0 signals" and no explanation would hide all of those, silently, every run, with
    // the breaker deliberately shut.
    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->status)->toBe(SignalRunStatus::Failed)
        // And it is **not retryable**, so the job does not re-pay to be told the same thing.
        ->and($run->error)->toContain('wouldn’t accept')
        ->and($run->signals_created)->toBe(0)
        // The spend is still recorded honestly: nothing was billed, because nothing was answered.
        ->and($run->cost_micros)->toBe(0)
        ->and($run->source_breakdown['news']['rejected'])->toBe(count($definition->query_templates))
        ->and($run->source_breakdown['news']['queries'])->toBe(0);
});

// **One fetch per feed per run, whatever the watch is pointed at.** Found while writing the test
// above rather than by either reviewer: `push()` was handed every source a definition names and
// emitted a query for each, so a feed got one per template per place per region — the exact "forty
// fetches of one identical document" its own docblock says the definition-addressed shape exists to
// prevent. The entity path filtered through `queryBased()`; the topical loop did not. The poll floor
// hid the requests, so what it actually cost was the per-run query ceiling: junk feed queries
// trimming real news ones out of the window.
it('asks a feed once per run, not once per template and place', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::Feed]);

    $feed = new FakeSignalSource(SignalSourceKind::Feed);
    app(SignalSourceRegistry::class)->register($feed);
    $feed->willReturn([]);

    $this->news->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    // Three places and the watch's region: the news multiplies out, the feed must not.
    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watching([$definition])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai', 'Abu Dhabi', 'Sharjah']]));

    runWatch($team, $watch);

    expect($feed->queries())->toHaveCount(1)
        ->and($feed->queries()[0]->q)->toBe('')
        // ...while the news did multiply out across the places. Not asserted as an exact product:
        // a template with no `{location}` placeholder composes to the same string in all three and
        // is deduped by `push()`'s key, which is deliberate.
        ->and(count($this->news->queries()))->toBeGreaterThan(count($definition->query_templates));
});

// **The S13b review's finding 2.** A feed gets exactly one query per definition, so for it
// "refused a query" and "refused every query" are the same event — and the rule above would
// therefore fail the whole run, news results included and already paid for, every single time a
// publisher moved a feed. The rule is right for an exhausted Serper account and wrong for a 404
// that will never change, which is why a missing *address* is its own outcome.
it('completes a run when a feed address is dead but the news worked', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::Feed]);

    $feed = new FakeSignalSource(SignalSourceKind::Feed);
    app(SignalSourceRegistry::class)->register($feed);
    $feed->willFail(SignalsException::sourceAddressNotFound('feed'));

    $this->news->willReturn([$this->news->item('Acme raises', 'https://gulfbusiness.com/a', source: 'Gulf Business')]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->error)->toBeNull()
        // Counted under `skipped` rather than `rejected`, because the two carry different advice:
        // one is a template to rewrite, the other an address with nothing at it.
        ->and($run->source_breakdown['feed']['skipped'])->toBe(1)
        ->and($run->source_breakdown['feed']['rejected'])->toBe(0)
        ->and($run->source_breakdown['news']['queries'])->toBe(count($definition->query_templates));
});

// The same rule one level up, and the reason a skip is kept out of `sourcesThatAnsweredNothing()`:
// a watchlist run whose every account is off the register has found nothing and is **not** broken.
// Reading those skips as refusals is what let one stale `leads.company_number` stop a 250-account
// watchlist on every run, for ever, with the entity cursor never advancing past the dead one.
it('completes a run in which every addressed account was missing', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);

    $this->news->willFail(SignalsException::sourceAddressNotFound('news'));
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition]));

    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->error)->toBeNull()
        ->and($run->signals_created)->toBe(0)
        ->and($run->source_breakdown['news']['skipped'])->toBe(count($definition->query_templates))
        ->and($run->source_breakdown['news']['queries'])->toBe(0)
        // A skipped query **was** made — a request went out and came back 404 — so it counts here
        // for the same reason a rejected one does.
        ->and($run->queries_count)->toBe(count($definition->query_templates));
});

it('keeps the spend of the queries that ran when a later source fails', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    // **Review finding 2.** The cost used to be recorded in a loop *after* every query, so a
    // non-retryable failure left `search()` before any of it was kept — and the run was written
    // with `cost_micros: 0` having already paid for the news queries, while the code's own comment
    // claimed the spend was recorded. It is now recorded in a `finally`.
    $this->news->willReturn([]);
    $this->web->willFail(SignalsException::sourceMisconfigured('web_search'));

    $run = runWatch($team, watchOver($team, [$definition]));

    $news = $run->source_breakdown['news'];

    // Asserted against the breakdown rather than a fixed count, because the plan **interleaves**
    // the sources (one query per source per template), so how many news queries get in before the
    // web-search one fails is a property of the builder's nesting rather than of this fix. What
    // matters is that whatever ran is paid for and recorded.
    expect($run->status)->toBe(SignalRunStatus::Failed)
        ->and($news['queries'])->toBeGreaterThan(0)
        ->and($news['cost_micros'])->toBe($news['queries'] * 1_000)
        // The figure the rest of the product reads, and it is no longer zero.
        ->and($run->cost_micros)->toBe($news['cost_micros'])
        ->and($run->cost_micros)->toBeGreaterThan(0);
});

it('counts the queries it made, so the column and the breakdown reconcile', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);
    $watch = watchOver($team, [$definition]);

    $firstTemplate = app(TeamContext::class)->run($team, function () use ($watch, $definition): string {
        return app(SignalQueryBuilder::class)->build($watch, [$definition])->queries[0]->q;
    });

    $this->news->willReturn([]);
    $this->news->willFailQuery($firstTemplate, SignalsException::sourceQueryRejected('news'));
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, $watch);

    $breakdown = $run->source_breakdown['news'];

    // **Review finding 3.** `queries_count` was the *planned* figure, so it counted the refused
    // query alongside the ones that ran while the breakdown counted only the answered ones — and
    // the admin screen showed both without them summing. A refused query **was** made; one skipped
    // for an unregistered source was not, and is counted nowhere.
    expect($run->queries_count)->toBe($breakdown['queries'] + $breakdown['rejected'])
        ->and($breakdown['rejected'])->toBe(1)
        // `queries_requested` stays the separate "what the plan wanted before the ceiling" figure.
        ->and($run->queries_requested)->toBe(count($definition->query_templates));
});

it('still bubbles a genuinely unwell source so the job retries', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);

    $this->news->willFail(SignalsException::sourceUnavailable('news'));

    expect(fn (): SignalRun => runWatch($team, watchOver($team, [$definition])))
        ->toThrow(SignalsException::class);
});

// --- Per-source cost on the run ---------------------------------------------------------------

it('records what each source cost, at that source’s own price', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    $this->news->willReturn([]);
    $this->web->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition]));

    $templates = count($definition->query_templates);

    expect($run->source_breakdown)->toHaveKeys(['news', 'web_search'])
        ->and($run->source_breakdown['news']['queries'])->toBe($templates)
        ->and($run->source_breakdown['news']['cost_micros'])->toBe($templates * 1_000)
        // Its own price, not the module-wide one: one of the declared sources is free, so a flat
        // multiplier would misquote the moment it ships.
        ->and($run->source_breakdown['web_search']['cost_micros'])->toBe($templates * 1_500);

    // And the breakdown reconciles with the total the rest of the product reads. The AI's share is
    // on top of it; the search half is this.
    $searchCost = $run->source_breakdown['news']['cost_micros'] + $run->source_breakdown['web_search']['cost_micros'];

    expect($run->cost_micros)->toBeGreaterThanOrEqual($searchCost);
});

it('stores no breakdown for a run that searched nothing', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News]);

    app(TeamContext::class)->run($team, fn () => $definition->forceFill(['is_active' => false])->save());

    $run = runWatch($team, watchOver($team, [$definition]));

    // Null rather than `{}`: an empty object reads as "we looked and spent nothing", and "we did
    // not look" is a different sentence.
    expect($run->status)->toBe(SignalRunStatus::Completed)
        ->and($run->source_breakdown)->toBeNull();
});

// --- The registry -----------------------------------------------------------------------------

it('refuses an unknown source key', function (): void {
    $registry = new SignalSourceRegistry;

    expect(fn () => $registry->for(SignalSourceKind::Feed))
        ->toThrow(SignalsException::class);

    expect($registry->has(SignalSourceKind::Feed))->toBeFalse()
        // The run's question, and it is a different one: a withdrawn source is skipped, not raised.
        ->and($registry->find(SignalSourceKind::Feed))->toBeNull();
});

it('registers the real sources, and only the ones with an implementation', function (): void {
    // A fresh container, so this file's fakes do not answer for the app's own wiring.
    $registered = app(SignalSourceRegistry::class)->registered();

    expect($registered)->toContain(SignalSourceKind::News)
        ->and($registered)->toContain(SignalSourceKind::WebSearch);

    foreach (SignalSourceKind::cases() as $kind) {
        // The enum's promise and the container's wiring must agree, or a type can be saved against
        // a source that silently contributes nothing.
        expect($kind->isAvailable())->toBe(in_array($kind, $registered, strict: true));
    }
});

it('falls back to news for an unreadable stored source list', function (): void {
    expect(SignalSourceKind::parseList(null))->toBe([SignalSourceKind::News])
        ->and(SignalSourceKind::parseList([]))->toBe([SignalSourceKind::News])
        ->and(SignalSourceKind::parseList(['nonsense']))->toBe([SignalSourceKind::News])
        ->and(SignalSourceKind::parseList(['web_search', 'web_search']))->toBe([SignalSourceKind::WebSearch]);
});

// --- Saving a definition ----------------------------------------------------------------------

it('saves the sources a member chose', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => ['news', 'web_search']]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($definition->sourceKinds())->toBe([SignalSourceKind::News, SignalSourceKind::WebSearch]);
});

it('refuses a source this build has never heard of', function (): void {
    $user = createUserWithTeam();

    // **S13b completed the set**, so there is no longer a declared-but-unoffered case to point this
    // at: all five of `SignalSourceKind`'s cases now have a provider. `isAvailable()` stays, and
    // stays the thing the rule is built from, because it is the mechanism that lets the *next*
    // source be declared before it is wired — the same way `SearchRegion::isOffered()` holds five
    // regions that are ready in code and not offered (ADR 0019 §2a).
    //
    // What is still testable, and is the part that matters, is that the rule is built from the
    // enum rather than from a hand-written list: a key nothing knows about is refused.
    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => ['pigeon_post']]))
        ->assertSessionHasErrors('source_keys.0');
});

it('offers exactly the sources that have a provider registered', function (): void {
    // The enum's promise and the container's wiring have to agree, or a type can be saved against a
    // source that silently contributes nothing. After S13b that is all five.
    $registered = app(SignalSourceRegistry::class)->registered();

    foreach (SignalSourceKind::cases() as $kind) {
        expect($kind->isAvailable())->toBe(in_array($kind, $registered, strict: true), $kind->value);
    }

    expect(SignalSourceKind::availableValues())
        ->toBe(['news', 'web_search', 'companies_house', 'feed', 'webhook']);
});

it('stores news for a caller that does not mention sources', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    // **The backwards-compatibility case, and it is the API's.** `/api/v1` has had this endpoint
    // since Phase 17 and no existing client sends `source_keys`; omitting it has to keep meaning
    // what it has always meant, which is the news. Hence `nullable` on the rule and the news
    // fallback in `SignalDefinitionData`.
    $payload = definitionPayload();
    unset($payload['source_keys']);

    $this->actingAs($user)->post('/signals/types', $payload)->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // **Left null rather than written as news**, which is the shape that makes review finding 5's
    // fix possible: `toAttributes()` omits the key entirely when the caller did not mention
    // sources, so an *update* that does not mention them cannot silently downgrade a web-search
    // type. `sourceKinds()` reads null as news, so the type runs exactly where a pre-S13 one did.
    expect($definition->sourceKinds())->toBe([SignalSourceKind::News])
        ->and($definition->source_keys)->toBeNull();
});

it('refuses an explicitly empty source list', function (): void {
    $user = createUserWithTeam();

    // Omitting the field means "the default"; sending `[]` means "nowhere", which is not a type
    // anybody wants and is the one state that would make a run complete having searched nothing.
    // The two are different requests and only one of them is ambiguous.
    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => []]))
        ->assertSessionHasErrors('source_keys');
});

// --- Filtering the feed by source -------------------------------------------------------------

it('narrows the feed to one source', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;
    $definition = signalDefinition($team);

    app(TeamContext::class)->run($team, function () use ($team, $definition): void {
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
            'event_key' => $definition->key,
            'source_kind' => SignalSourceKind::News->value,
            'headline' => 'From the news',
        ]);
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
            'event_key' => $definition->key,
            'source_kind' => SignalSourceKind::WebSearch->value,
            'headline' => 'From the web',
        ]);
    });

    $this->actingAs($user)
        ->get('/signals?source_kinds[]=web_search')
        ->assertInertia(fn ($page) => $page
            ->where('signals.meta.total', 1)
            ->where('filters.source_kinds', ['web_search'])
            ->where('signals.data.0.headline', 'From the web'));

    // And unfiltered shows both, which is what the feed has always shown.
    $this->actingAs($user)
        ->get('/signals')
        ->assertInertia(fn ($page) => $page->where('signals.meta.total', 2));
});

it('refuses a source filter this build does not know', function (): void {
    $user = createUserWithTeam();

    // Validated with `in:`, like `scope` and `sort` and unlike `event_keys` — because a source is an
    // enum case and an event key is not. A key outlives the type that made it, so filtering by a
    // deleted type's key is a real question; a source this build has never heard of is a malformed
    // request, and answering it with a silently unfiltered feed is what the S9 review called out
    // about this very screen.
    $this->actingAs($user)
        ->get('/signals?source_kinds[]=nonsense')
        ->assertSessionHasErrors('source_kinds.0');
});

it('drops an unreadable source from the filter state, as a second line of defence', function (): void {
    // The HTTP layer refuses it; `SignalFilters` still has to cope, because it is also built from a
    // validated API payload and from `toFormState()` round-trips. Dropped rather than honoured: a
    // case this build does not know cannot be on any row, so keeping it would answer a filtered
    // request with an empty page for a reason nobody could work out.
    $filters = SignalFilters::fromArray(['source_kinds' => ['nonsense', 'web_search', 'web_search']]);

    expect($filters->sourceKinds)->toBe([SignalSourceKind::WebSearch])
        ->and($filters->toFormState()['source_kinds'])->toBe(['web_search'])
        ->and($filters->isNarrowed())->toBeTrue();
});

/**
 * A valid definition payload, with the given overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function definitionPayload(array $overrides = []): array
{
    return [
        'name' => 'Office moves',
        'description' => 'A company takes new premises.',
        'category' => 'growth',
        'icon' => 'satellite',
        'query_templates' => ['{location} company opens office'],
        'match_keywords' => [],
        'includes' => [],
        'excludes' => [],
        'min_confidence' => null,
        'min_relevance' => null,
        'suggested_approach_hint' => null,
        'is_active' => true,
        ...$overrides,
    ];
}

// --- What a source does to the cost estimate --------------------------------------------------

it('costs two sources as two searches per template, at each source’s own price', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watching([signalDefinition($team)])
        ->create(['team_id' => $team->getKey(), 'locations' => ['Dubai'], 'cadence' => 'daily']));

    $newsOnly = app(SignalCostEstimator::class)->estimate($team, 2, [SignalSourceKind::News]);
    $both = app(SignalCostEstimator::class)->estimate($team, 2, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    // A source is a multiplier on the query count...
    expect($both->queriesPerRun)->toBe($newsOnly->queriesPerRun * 2)
        // ...and the money follows each source's own price rather than a flat figure: news is
        // configured at 1,000 micros here and web search at 1,500, so the pair is 2.5x the news.
        ->and($both->costMicrosPerMonth)->toBe((int) ($newsOnly->costMicrosPerMonth * 2.5));
});

it('costs an empty source choice as the news, which is what it will be stored as', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    // The form and the estimate must not disagree mid-edit: an empty choice saves as news, so it
    // has to be priced as news.
    expect(app(SignalCostEstimator::class)->estimate($team, 2, [])->costMicrosPerMonth)
        ->toBe(app(SignalCostEstimator::class)->estimate($team, 2, [SignalSourceKind::News])->costMicrosPerMonth);
});

// --- What operations can see ------------------------------------------------------------------

it('shows an operator where a run’s money went, per source', function (): void {
    $team = Team::factory()->create();
    $definition = definitionOn($team, [SignalSourceKind::News, SignalSourceKind::WebSearch]);

    $this->news->willReturn([]);
    $this->web->willReturn([]);
    $this->fakeAi->queueStructured(['events' => []]);

    $run = runWatch($team, watchOver($team, [$definition]));

    $rows = SignalSourceBreakdown::fromRun($run->refresh());

    expect($rows)->toHaveCount(2)
        ->and(array_column($rows, 'source'))->toBe(['news', 'web_search'])
        // Labelled from the enum, so one wording serves the form, the card and this screen.
        ->and($rows[1]['label'])->toBe(SignalSourceKind::WebSearch->label())
        ->and($rows[1]['cost_micros'])->toBeGreaterThan(0);
});

it('labels a source key this build no longer knows as itself', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => SignalRun::factory()->create([
        'team_id' => $team->getKey(),
        // A historical run from a build that had a source since withdrawn. An operator needs to see
        // that *something* spent money there, not a blank row or a crash.
        'source_breakdown' => ['pigeon_post' => ['queries' => 3, 'results' => 1, 'rejected' => 0, 'cost_micros' => 42]],
    ]));

    $rows = SignalSourceBreakdown::fromRun($run);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['label'])->toBe('pigeon_post')
        ->and($rows[0]['cost_micros'])->toBe(42);
});

it('reads a run from before S13 as having no breakdown rather than crashing', function (): void {
    $team = Team::factory()->create();

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => SignalRun::factory()->create([
        'team_id' => $team->getKey(),
        'source_breakdown' => null,
    ]));

    expect(SignalSourceBreakdown::fromRun($run))->toBe([]);
});

// --- An update must not silently downgrade a type (review finding 5) --------------------------

it('leaves the stored sources alone when an update does not mention them', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => ['news', 'web_search']]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // An older client — the mobile app, a customer's integration written before S13a — PATCHes the
    // name and knows nothing about sources. Collapsing "absent" and "empty" meant this downgraded a
    // web-search type to news: it changed what the type cost and what it found, with no error and
    // nothing on screen. `toAttributes()` now omits the key entirely when the caller did not
    // mention it.
    $payload = definitionPayload(['name' => 'Office moves, renamed']);
    unset($payload['source_keys']);

    $this->actingAs($user)
        ->patch('/signals/types/'.$definition->getKey(), $payload)
        ->assertRedirect();

    $definition->refresh();

    expect($definition->name)->toBe('Office moves, renamed')
        ->and($definition->sourceKinds())->toBe([SignalSourceKind::News, SignalSourceKind::WebSearch]);
});

it('changes the stored sources when an update does mention them', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => ['news', 'web_search']]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // The other half: a member who unticks web search must have it unticked. "Absent means leave
    // alone" is only safe because "present means this is the whole list".
    $this->actingAs($user)
        ->patch('/signals/types/'.$definition->getKey(), definitionPayload(['source_keys' => ['news']]))
        ->assertRedirect();

    expect($definition->refresh()->sourceKinds())->toBe([SignalSourceKind::News]);
});

// --- The migration's compatibility guarantee -------------------------------------------------

it('leaves every seeded platform preset running on the news', function (): void {
    // **The backfill, asserted.** `signal_definitions.source_keys` was added by S13a and every
    // existing row set to `["news"]`, which is the whole compatibility story: that is what each of
    // them was already doing. The seeded presets are the rows the backfill actually runs against —
    // their seed migration runs before it — so this is the one place the guarantee is checkable
    // without hand-writing the row the assertion expects.
    //
    // Verified against real MySQL 8.0 as well as the suite's SQLite, because a json column's
    // round-trip is exactly the kind of thing the two disagree about.
    $presets = SignalDefinition::withoutGlobalScopes()->whereNull('team_id')->get();

    expect($presets)->not->toBeEmpty();

    foreach ($presets as $preset) {
        expect($preset->sourceKinds())->toBe([SignalSourceKind::News])
            ->and($preset->usesSource(SignalSourceKind::WebSearch))->toBeFalse();
    }
});

// --- A feed is configuration, and configuration is bounded (S13b) -----------------------------

it('saves a feed URL against a type', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'source_keys' => ['feed'],
            'source_config' => ['feed' => ['url' => 'example.com/feed.xml']],
        ]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // Stored **normalised**, so the poll-interval floor and the conditional-GET cache — both keyed
    // on the URL — agree about what "the same feed" is whether somebody typed the scheme or not.
    expect($definition->sourceConfig(SignalSourceKind::Feed)['url'])->toBe('https://example.com/feed.xml');
});

// **A ticked feed with no address is a type that searches nowhere** (brand review, finding 7). It
// used to save: `source_config.feed.url` was nullable, `FeedSignalSource::supports()` then declined
// the definition, the builder emitted no query, and the member's screen showed the feed as on while
// it did nothing. That is the failure this module keeps legislating against, and the form asks for
// the address the moment the box is ticked — this is what makes that truthful.
it('refuses a type that names a feed without an address', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'source_keys' => ['news', 'feed'],
        ]))
        ->assertSessionHasErrors('source_config.feed.url');

    expect(SignalDefinition::withoutGlobalScopes()->whereNotNull('team_id')->count())->toBe(0);
});

it('accepts a type that names a feed with one', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'source_keys' => ['news', 'feed'],
            'source_config' => ['feed' => ['url' => 'https://example.com/feed.xml']],
        ]))
        ->assertRedirect();

    expect(SignalDefinition::withoutGlobalScopes()->whereNotNull('team_id')->count())->toBe(1);
});

// ...and the rule is about the feed only: a type naming no feed is unaffected by it.
it('does not ask for a feed address from a type that names no feed', function (): void {
    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload(['source_keys' => ['news']]))
        ->assertRedirect();
});

it('refuses a feed URL this application may not fetch', function (): void {
    $user = createUserWithTeam();

    // Validated at the earliest useful moment: a member deserves this beside the field, not a
    // source that silently finds nothing and a warning in a log nobody reads. The source still
    // re-checks before every request, because DNS can change under a name that passed here.
    foreach (['http://127.0.0.1/feed.xml', 'http://169.254.169.254/latest/meta-data/', 'file:///etc/passwd'] as $url) {
        $this->actingAs($user)
            ->post('/signals/types', definitionPayload([
                'source_keys' => ['feed'],
                'source_config' => ['feed' => ['url' => $url]],
            ]))
            ->assertSessionHasErrors('source_config.feed.url');
    }
});

it('refuses more feeds than the team is allowed', function (): void {
    config()->set('signals.sources.feed.max_per_team', 1);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'name' => 'First feed',
            'source_keys' => ['feed'],
            'source_config' => ['feed' => ['url' => 'https://one.example.com/feed.xml']],
        ]))
        ->assertRedirect();

    // Each feed is a standing outbound request from our infrastructure to an address a customer
    // chose, which is the same reason watches and types have ceilings. The poll floor bounds how
    // *often* one is fetched; this bounds how many exist.
    //
    // A domain refusal rather than a validation error, so it lands as an error toast — the same way
    // the type ceiling and `definition_in_use` do on this screen.
    $this->actingAs($user)
        ->from(route('signal-definitions.index'))
        ->post('/signals/types', definitionPayload([
            'name' => 'Second feed',
            'source_keys' => ['feed'],
            'source_config' => ['feed' => ['url' => 'https://two.example.com/feed.xml']],
        ]))
        ->assertRedirect(route('signal-definitions.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
});

it('lets a team edit the type that already holds their only allowed feed', function (): void {
    config()->set('signals.sources.feed.max_per_team', 1);

    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'source_keys' => ['feed'],
            'source_config' => ['feed' => ['url' => 'https://one.example.com/feed.xml']],
        ]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // Counted over the team's **other** types, so editing a type that already has a feed does not
    // fail because of its own.
    $this->actingAs($user)
        ->patch('/signals/types/'.$definition->getKey(), definitionPayload([
            'name' => 'Renamed',
            'source_keys' => ['feed'],
            'source_config' => ['feed' => ['url' => 'https://one.example.com/other.xml']],
        ]))
        ->assertRedirect();

    expect($definition->refresh()->sourceConfig(SignalSourceKind::Feed)['url'])
        ->toBe('https://one.example.com/other.xml');
});

it('stores only the per-source keys a source actually reads', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $this->actingAs($user)
        ->post('/signals/types', definitionPayload([
            'source_keys' => ['companies_house'],
            'source_config' => [
                'companies_house' => ['filings' => ['AP01', 'ap01', ' TM01 ']],
                // Not a source this build knows, and not storage for a caller's own data.
                'pigeon_post' => ['secret' => 'hunter2'],
            ],
        ]))
        ->assertRedirect();

    $definition = SignalDefinition::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    // An allowlist, not a pass-through: this column is json written from a request body, so
    // anything not named is dropped rather than stored. Lower-cased and de-duplicated, so the row
    // reads the way the filing map is keyed.
    expect($definition->source_config)->toBe(['companies_house' => ['filings' => ['ap01', 'tm01']]]);
});

// **A string the server sends for the client to translate is a translation key, and nothing
// enforces that but this.** The three new source descriptions shipped with keys written against an
// earlier draft of the enum, so `t()` found no key and an Arabic member read English prose in a
// form that was otherwise fully translated. It is invisible in English and invisible in review,
// because the fallback *is* the English text. The guard is module-wide rather than about
// `SignalSourceKind`, since the next enum to drift will not be this one: it found four older
// labels (two dismiss reasons, two cadences) already going out untranslated.
it('keeps every Signals enum label and description in the translation bundles', function (): void {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $ar = json_decode((string) file_get_contents(lang_path('ar.json')), true);
    $missing = [];

    foreach (glob(app_path('Modules/Signals/Enums/*.php')) ?: [] as $file) {
        /** @var class-string $class */
        $class = 'App\Modules\Signals\Enums\\'.basename($file, '.php');

        if (! enum_exists($class)) {
            continue;
        }

        foreach ($class::cases() as $case) {
            foreach (['label', 'description'] as $method) {
                if (! method_exists($case, $method)) {
                    continue;
                }

                $text = $case->{$method}();

                if (! is_string($text) || $text === '') {
                    continue;
                }

                foreach (['en' => $en, 'ar' => $ar] as $locale => $bundle) {
                    if (! array_key_exists($text, (array) $bundle)) {
                        $missing[] = $locale.': '.class_basename($class).'::'.$case->name.'->'.$method.'()';
                    }
                }
            }
        }
    }

    expect($missing)->toBe([]);
});
