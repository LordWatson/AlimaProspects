<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\ListSignalsAction;
use App\Modules\Signals\Data\SignalFilters;
use App\Modules\Signals\Enums\SignalScoreComponent;
use App\Modules\Signals\Http\Resources\SignalResource;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Billing;

/*
 * The ranked feed and the panel that explains it (S12).
 *
 * Two things this file is really asserting. That the **order** is the member's — heat by default,
 * date kept, both shareable in a URL — and that an **unscored** signal sorts last rather than
 * first, which is a difference between databases and so the kind of thing nobody notices until
 * production.
 */

uses(RefreshDatabase::class);

/** A scored signal, saved. */
function rankedSignal(Team $team, ?int $heat, array $attributes = []): Signal
{
    return app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create(array_merge([
        'team_id' => $team->getKey(),
        'heat' => $heat,
    ], $attributes)));
}

function rankedFeed(Team $team, array $filters = []): array
{
    return app(ListSignalsAction::class)
        ->handle($team, SignalFilters::fromArray($filters))
        ->items();
}

// --- The order ----------------------------------------------------------------------------------

it('orders the feed by heat by default', function (): void {
    $team = Team::factory()->create();

    $cold = rankedSignal($team, 20, ['published_at' => now()]);
    $hot = rankedSignal($team, 90, ['published_at' => now()->subDays(5)]);

    // Heat beats recency, which is the whole claim of the default order: the best thing for this
    // team is not necessarily the newest thing.
    expect(array_map(static fn (Signal $s): int => (int) $s->getKey(), rankedFeed($team)))
        ->toBe([(int) $hot->getKey(), (int) $cold->getKey()]);
});

it('keeps "most recent" as a real choice', function (): void {
    $team = Team::factory()->create();

    $cold = rankedSignal($team, 20, ['published_at' => now()]);
    $hot = rankedSignal($team, 90, ['published_at' => now()->subDays(5)]);

    // A ranking nobody has learned to trust yet needs the order they already understand to fall
    // back to. Taking that away would be the fastest way to lose their trust in the score.
    expect(array_map(static fn (Signal $s): int => (int) $s->getKey(), rankedFeed($team, ['sort' => 'published'])))
        ->toBe([(int) $cold->getKey(), (int) $hot->getKey()]);
});

it('sorts an unscored signal last, not first', function (): void {
    $team = Team::factory()->create();

    $unscored = rankedSignal($team, null, ['published_at' => now()]);
    $cold = rankedSignal($team, 5, ['published_at' => now()->subDay()]);

    // `ORDER BY heat DESC` puts nulls first on MySQL and last on SQLite. One of those is a feed
    // that opens on its oldest unscored rows, and which one you get depends on the database — so
    // the enum sorts on an explicit key instead. "We have not judged this" is not "this is the
    // best thing we found".
    expect(array_map(static fn (Signal $s): int => (int) $s->getKey(), rankedFeed($team)))
        ->toBe([(int) $cold->getKey(), (int) $unscored->getKey()]);
});

it('falls back to the default order for a sort it does not recognise', function (): void {
    $team = Team::factory()->create();
    rankedSignal($team, 50);

    // A stale bookmark should open a feed, not a 404 — the same forgiveness an unknown status and
    // an unknown scope already get.
    expect(SignalFilters::fromArray(['sort' => 'by-vibes'])->sort->value)->toBe('heat');
});

it('carries the sort in the URL so a shared link opens the same feed', function (): void {
    [$user, $team] = Billing::team();

    app(TeamContext::class)->run($team, fn () => SignalWatch::factory()->create(['team_id' => $team->getKey()]));
    rankedSignal($team, 50);

    $this->actingAs($user)
        ->get('/signals?sort=published')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('filters.sort', 'published')
            // And the control is built from the enum rather than typed into the component, so a
            // third order could never ship without a label.
            ->has('options.sorts', 2)
            ->where('options.sorts.0.value', 'heat'));
});

// --- The explanation ----------------------------------------------------------------------------

it('explains a scored card from its stored breakdown', function (): void {
    $team = Team::factory()->create();

    $signal = rankedSignal($team, 72, [
        'score_breakdown' => [
            ['component' => 'relevance', 'value' => 70, 'weight' => 30, 'contribution' => 25, 'evidence' => '70/100'],
            ['component' => 'amount', 'value' => null, 'weight' => 10, 'contribution' => 0, 'evidence' => 'No amount given.'],
        ],
    ]);

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make(
            Signal::query()->with(['definition', 'watch'])->findOrFail($signal->getKey()),
        )->resolve(request()),
    );

    expect($payload['heat'])->toBe(72)
        ->and($payload['explanation']['heat'])->toBe(72)
        ->and($payload['explanation']['components'])->toHaveCount(2)
        ->and($payload['explanation']['components'][0]['key'])->toBe(SignalScoreComponent::Relevance->value)
        ->and($payload['explanation']['components'][0]['assessed'])->toBeTrue()
        // The unjudged row is **kept**, with its reason: "no amount given" tells a member the
        // article was thin, not that the deal was small, and dropping it would turn half the
        // explanation into a shorter list.
        ->and($payload['explanation']['components'][1]['assessed'])->toBeFalse()
        ->and($payload['explanation']['components'][1]['evidence'])->toBe('No amount given.')
        // Which type decided this, and which version of it — so somebody who retuned a type
        // yesterday can tell whether this card predates the change.
        ->and($payload['explanation']['definition']['key'])->toBe($signal->event_key)
        // Snippet-only until the article is read, and the panel says which.
        ->and($payload['explanation']['read_full_article'])->toBeFalse();
});

it('sends no panel for a card that has never been scored', function (): void {
    $team = Team::factory()->create();

    $signal = rankedSignal($team, null, ['score_breakdown' => null]);

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make(
            Signal::query()->with(['definition', 'watch'])->findOrFail($signal->getKey()),
        )->resolve(request()),
    );

    // An explanation with nothing in it is worse than the honest absence of one.
    expect($payload['heat'])->toBeNull()->and($payload['explanation'])->toBeNull();
});

it('explains the score it was ranked by, not what today’s weights would say', function (): void {
    $team = Team::factory()->create();

    $signal = rankedSignal($team, 72, [
        'score_breakdown' => [
            ['component' => 'relevance', 'value' => 70, 'weight' => 30, 'contribution' => 25],
        ],
    ]);

    // Somebody retunes the weights. The stored breakdown — and so the panel — is unchanged,
    // because it is a record of a decision rather than a derivation of the current config.
    config()->set('signals.scoring.weights.relevance', 1);

    $payload = app(TeamContext::class)->run(
        $team,
        fn (): array => SignalResource::make(
            Signal::query()->with(['definition', 'watch'])->findOrFail($signal->getKey()),
        )->resolve(request()),
    );

    expect($payload['explanation']['components'][0]['weight'])->toBe(30);
});

// --- The rescore backfill -----------------------------------------------------------------------

it('scores existing signals so an old feed can be ranked', function (): void {
    $team = Team::factory()->create();

    rankedSignal($team, null);
    rankedSignal($team, null);

    $this->artisan('signals:rescore')
        ->expectsOutputToContain('Scored 2 signal(s)')
        ->assertSuccessful();

    expect(Signal::query()->withoutGlobalScopes()->whereNull('heat')->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->whereNull('score_breakdown')->count())->toBe(0);
});

it('leaves already-scored signals alone unless asked', function (): void {
    $team = Team::factory()->create();

    rankedSignal($team, 42);

    $this->artisan('signals:rescore')
        ->expectsOutputToContain('Scored 0 signal(s)')
        ->assertSuccessful();

    expect((int) Signal::query()->withoutGlobalScopes()->firstOrFail()->heat)->toBe(42);

    // `--all` is the deliberate act an operator takes after retuning the weights. A nightly
    // re-score would silently re-rank a team's feed every morning for reasons nobody could see.
    $this->artisan('signals:rescore', ['--all' => true])
        ->expectsOutputToContain('Scored 1 signal(s)')
        ->assertSuccessful();

    expect((int) Signal::query()->withoutGlobalScopes()->firstOrFail()->heat)->not->toBe(42);
});

it('writes nothing on a dry run', function (): void {
    $team = Team::factory()->create();
    rankedSignal($team, null);

    $this->artisan('signals:rescore', ['--dry-run' => true])
        ->expectsOutputToContain('Would score 1 signal(s)')
        ->assertSuccessful();

    expect(Signal::query()->withoutGlobalScopes()->whereNull('heat')->count())->toBe(1);
});

it('never scores one team’s signals while asked for another’s', function (): void {
    $mine = Team::factory()->create();
    $theirs = Team::factory()->create();

    rankedSignal($mine, null);
    rankedSignal($theirs, null);

    $this->artisan('signals:rescore', ['--team' => (string) $mine->getKey()])->assertSuccessful();

    expect(Signal::query()->withoutGlobalScopes()->where('team_id', $mine->getKey())->whereNull('heat')->count())->toBe(0)
        ->and(Signal::query()->withoutGlobalScopes()->where('team_id', $theirs->getKey())->whereNull('heat')->count())->toBe(1);
});
