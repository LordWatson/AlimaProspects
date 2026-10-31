<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Contracts\NewsSearchClient;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Data\ExtractedSignal;
use App\Modules\Signals\Data\SignalQuery;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalItemNormaliser;
use App\Modules\Signals\Services\SignalQueryBuilder;
use App\Modules\Signals\Services\SignalsPersonalDataExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/*
 * The findings from the S6 module and security reviews, each pinned by a test so it can't come
 * back. Grouped here rather than scattered, because what they have in common is that every one of
 * them was a promise the code made somewhere and didn't keep.
 */

beforeEach(function (): void {
    Queue::fake();
    Event::fake();
    Http::preventStrayRequests();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function reviewSignal(Team $team, SignalWatch $watch, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
        ...$attributes,
    ]));
}

// --- Module review 1: a watch deletion must not destroy a converted lead's origin --------------

it('keeps a converted signal when its watch is deleted', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    [$watch, $signal] = app(TeamContext::class)->run($team, function () use ($team): array {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $run = SignalRun::factory()->completed()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
        ]);

        // Plus one the team never acted on, which *should* go with the watch.
        Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'signal_run_id' => $run->getKey(),
        ]);

        return [$watch, $signal];
    });

    $lead = app(TeamContext::class)->run($team, fn (): Lead => app(ConvertSignalToLeadAction::class)
        ->handle($signal, $user, ConvertSignalData::prefillFrom($signal)));

    $this->actingAs($user)
        ->delete(route('signal-watches.destroy', ['watch' => $watch->getKey()]))
        ->assertRedirect();

    $signal->refresh();

    // The converted signal survives with its attribution detached, because it is the provenance of
    // a lead someone is working — and `compliance.md` §5a promises it lives as long as that lead.
    expect($signal->exists)->toBeTrue()
        ->and($signal->signal_watch_id)->toBeNull()
        ->and($signal->signal_run_id)->toBeNull()
        ->and($signal->status)->toBe(SignalStatus::Converted)
        ->and($signal->lead_id)->toBe($lead->id)
        // …and the un-converted one went with the watch.
        ->and(Signal::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(1);

    // The lead's origin panel still resolves, which is the whole point.
    $origin = app(TeamContext::class)->run($team, fn (): ?object => app(LeadOriginProvider::class)
        ->originFor($lead->refresh()));

    expect($origin)->not->toBeNull()
        ->and($origin->headline)->toBe($signal->headline);
});

// --- Module review 2: the prune command's delete must carry its own guards ----------------------

it('does not prune a signal that was converted after the ids were read', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    $signal = reviewSignal($team, $watch, ['status' => SignalStatus::Dismissed]);
    $signal->forceFill(['created_at' => now()->subDays(200)])->saveQuietly();

    // Stand in for a member converting it between the sweep's read and its delete. `DB::listen`
    // fires immediately after each query, so flipping the status on the id-pluck lands in exactly
    // that window. The delete re-applies the status guard, so the row survives — otherwise a
    // brand-new lead would be left pointing at nothing.
    $flipped = false;

    DB::listen(function ($query) use ($signal, &$flipped): void {
        if ($flipped || ! str_contains($query->sql, 'select') || ! str_contains($query->sql, 'signals')) {
            return;
        }

        if (! str_contains($query->sql, 'limit')) {
            return;
        }

        $flipped = true;

        DB::table('signals')
            ->where('id', $signal->getKey())
            ->update(['status' => SignalStatus::Converted->value]);
    });

    $this->artisan('signals:prune')->assertSuccessful();

    expect($flipped)->toBeTrue();

    expect(Signal::withoutGlobalScopes()->whereKey($signal->getKey())->exists())->toBeTrue();
});

// --- Module review 3: a team can't create watches without limit --------------------------------

it('refuses a new watch once the team is at its ceiling', function (): void {
    config(['signals.max_watches_per_team' => 2]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    foreach (['One', 'Two'] as $name) {
        $this->actingAs($user)->post(route('signal-watches.store'), [
            'name' => $name, 'location' => 'Dubai', 'event_types' => ['acquisition'], 'cadence' => 'daily',
        ])->assertRedirect();
    }

    // A watch is a standing instruction to spend money, so the ceiling is on creating them as well
    // as on running them.
    $this->actingAs($user)
        ->from(route('signal-watches.index'))
        ->post(route('signal-watches.store'), [
            'name' => 'Three', 'location' => 'Dubai', 'event_types' => ['acquisition'], 'cadence' => 'daily',
        ])
        ->assertRedirect(route('signal-watches.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect(SignalWatch::withoutGlobalScopes()->where('team_id', $team->getKey())->count())->toBe(2);
});

it('still lets an existing watch be edited at the ceiling', function (): void {
    config(['signals.max_watches_per_team' => 1]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    // The cap is on create: an existing watch is already counted, so editing it must not fail.
    $this->actingAs($user)
        ->patch(route('signal-watches.update', ['watch' => $watch->getKey()]), [
            'name' => 'Renamed', 'location' => 'Dubai', 'event_types' => ['relocation'], 'cadence' => 'weekly',
        ])
        ->assertRedirect(route('signal-watches.index'));

    expect($watch->refresh()->name)->toBe('Renamed');
});

it('reports the ceiling through the API envelope', function (): void {
    config(['signals.max_watches_per_team' => 1]);

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    Sanctum::actingAs($user, ['*']);

    $this->postJson(route('api.v1.signal-watches.store'), [
        'name' => 'Nope', 'location' => 'Dubai', 'event_types' => ['acquisition'], 'cadence' => 'daily',
    ])
        ->assertStatus(422)
        ->assertJsonPath('error.code', 'signals.too_many_watches');
});

// --- Security review M1/M2: host tricks must not defeat the ignore list ------------------------

it('rejects a URL whose host hides a control character', function (): void {
    $normaliser = app(SignalItemNormaliser::class);

    // Every browser strips tab/newline/CR before navigating, but `parse_url` turns them into an
    // underscore — so this would canonicalise to `bayut_.com`, miss the ignore list, and hand us a
    // brand-new `url_hash` for the same article every single run.
    expect($normaliser->canonicalUrl("https://bayut\t.com/listing"))->toBe('https://bayut.com/listing')
        ->and($normaliser->canonicalUrl("https://bayut\n.com/listing"))->toBe('https://bayut.com/listing');
});

it('treats a trailing-dot FQDN as the same host', function (): void {
    $normaliser = app(SignalItemNormaliser::class);

    // `bayut.com.` is a valid absolute name resolving to the same place, but a different string.
    expect($normaliser->host('bayut.com.'))->toBe('bayut.com')
        ->and($normaliser->host('www.Bayut.com.'))->toBe('bayut.com')
        ->and($normaliser->canonicalUrl('https://bayut.com./listing'))->toBe('https://bayut.com/listing');
});

it('refuses a host that isn’t one', function (): void {
    $normaliser = app(SignalItemNormaliser::class);

    expect($normaliser->host('not a host'))->toBeNull()
        ->and($normaliser->host('bayut_.com'))->toBeNull()
        ->and($normaliser->host('localhost'))->toBeNull()
        ->and($normaliser->canonicalUrl('javascript:alert(1)//https://x.com'))->toBeNull();
});

it('drops an ignored source however the host is dressed up', function (): void {
    config()->set('signals.serper.provider', 'serper');
    config()->set('signals.serper.api_key', 'test-key');
    config()->set('signals.serper.base_url', 'https://google.serper.dev');
    config()->set('signals.ignore_domains', ['bayut.com']);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [
                ['title' => 'Trailing dot', 'link' => 'https://bayut.com./a', 'snippet' => 'x', 'source' => 'B'],
                ['title' => 'Tab in host', 'link' => "https://bayut\t.com/b", 'snippet' => 'x', 'source' => 'B'],
                ['title' => 'Sub-domain', 'link' => 'https://news.bayut.com/c', 'snippet' => 'x', 'source' => 'B'],
                ['title' => 'Genuine', 'link' => 'https://gulfbusiness.com/real', 'snippet' => 'x', 'source' => 'G'],
            ],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(new SignalQuery(
        eventType: SignalEventType::FundingRound,
        q: 'Dubai startup raises funding',
        gl: 'ae', hl: 'en', tbs: 'qdr:w', num: 20,
    ));

    expect($items)->toHaveCount(1)
        ->and($items[0]->link)->toBe('https://gulfbusiness.com/real');
});

it('stores the canonical link, so userinfo can’t dress up the destination', function (): void {
    config()->set('signals.serper.provider', 'serper');
    config()->set('signals.serper.api_key', 'test-key');
    config()->set('signals.serper.base_url', 'https://google.serper.dev');
    config()->set('signals.ignore_domains', []);

    Http::fake([
        'google.serper.dev/*' => Http::response([
            'news' => [[
                'title' => 'Looks like Gulf News',
                // The real host is `evil.ae`; a member reading the raw link would see a publisher
                // they trust at the front of it.
                'link' => 'https://gulfnews.com@evil.ae/article',
                'snippet' => 'x',
                'source' => 'Gulf News',
            ]],
        ]),
    ]);

    $items = app(NewsSearchClient::class)->search(new SignalQuery(
        eventType: SignalEventType::FundingRound,
        q: 'Dubai startup raises funding',
        gl: 'ae', hl: 'en', tbs: 'qdr:w', num: 20,
    ));

    expect($items)->toHaveCount(1)
        ->and($items[0]->link)->toBe('https://evil.ae/article');
});

it('normalises a team’s own ignored domain written with a trailing dot', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
        'location' => 'Dubai',
        'event_types' => ['funding_round'],
        'ignore_domains' => ['Rival.ae.', 'https://www.other.ae/'],
    ]));

    $queries = app(SignalQueryBuilder::class)->build($watch);

    expect($queries[0]->ignoreDomains)->toBe(['rival.ae', 'other.ae']);
});

// --- Security review L3: the LIKE escape must work on the test driver too ----------------------

it('searches for a literal underscore in a name rather than any character', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $watch = app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()->create([
        'team_id' => $team->getKey(),
    ]));

    reviewSignal($team, $watch, ['subject_person_name' => 'Jane Founder']);

    // Without an explicit `ESCAPE` clause this passes on MySQL and fails on SQLite, so the test
    // would be validating behaviour that differs from production.
    expect(app(SignalsPersonalDataExporter::class)->export($team, 'Jane_Founder'))->toBe([])
        ->and(app(SignalsPersonalDataExporter::class)->export($team, 'Jane Founder'))->toHaveCount(1);
});

// --- Security review L4: the approach line must not carry a destination ------------------------

it('replaces a suggested approach that smuggles in a link', function (): void {
    foreach ([
        'Visit https://evil.ae for an intro',
        'Message them on t.me/handle',
        'See www.evil.ae',
        'Email them at once',
    ] as $hostile) {
        $extracted = ExtractedSignal::fromAiItem([
            'item_index' => 0,
            'is_event' => true,
            'event_type' => 'funding_round',
            'confidence' => 90,
            'relevance' => 80,
            'summary' => 'A company raised a round.',
            'suggested_approach' => $hostile,
        ]);

        // The approach line is read by a salesperson, and an article is attacker-controlled text.
        expect($extracted->suggestedApproach)->not->toContain('evil.ae')
            ->and($extracted->suggestedApproach)->not->toContain('t.me');
    }
});
