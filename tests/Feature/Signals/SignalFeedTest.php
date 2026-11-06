<?php

declare(strict_types=1);

use App\Modules\Auth\Support\TeamMembership;
use App\Modules\Core\Enums\Market;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Jobs\EnrichLeadJob;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Signals\Enums\SignalDismissReason;
use App\Modules\Signals\Enums\SignalStatus;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Event::fake();
});

/**
 * A signal in the team's feed, of one of the team's own signal types.
 *
 * Since S7 the type is a row the team owns (ADR 0018), so a fixture has to give them one - both
 * because the card reads its name and because the feed's filters are narrowed against the team's
 * library (`SignalFilters::onlyKnownKeys()`).
 *
 * @param  array<string, mixed>  $attributes
 */
function feedSignal(Team $team, array $attributes = []): Signal
{
    $key = is_string($attributes['event_key'] ?? null)
        ? $attributes['event_key']
        : SignalPresetCatalogue::FUNDING_ROUND;

    $definition = signalDefinition($team, $key);

    return app(TeamContext::class)->run($team, function () use ($team, $definition, $attributes): Signal {
        $watch = SignalWatch::query()->where('team_id', $team->getKey())->first()
            ?? SignalWatch::factory()->create(['team_id' => $team->getKey(), 'name' => 'Dubai watch']);

        return Signal::factory()
            ->forDefinition($definition)
            ->create([
                'team_id' => $team->getKey(),
                'signal_watch_id' => $watch->getKey(),
                ...$attributes,
            ]);
    });
}

/**
 * Give the team the whole library, for the tests that assert on the filter options.
 */
function feedDefinitions(Team $team): void
{
    // The UAE three, which is what these fixtures mean by "the library" — S8 added a UK pack, and
    // counting all nine here would be asserting the catalogue's size rather than the feed's.
    // The starters rather than the whole market: since S9 the library is thirty presets, and this
    // fixture wants a realistic watch, not every type Alima ships.
    foreach (SignalPresetCatalogue::starterKeys(Market::Uae) as $key) {
        signalDefinition($team, $key);
    }
}

// --- The feed -----------------------------------------------------------------------------------

it('renders the feed with the new signals, tab counts and filter options', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedDefinitions($team);

    feedSignal($team);
    feedSignal($team, ['status' => SignalStatus::Dismissed, 'dismiss_reason' => SignalDismissReason::NotRelevant]);
    feedSignal($team, ['status' => SignalStatus::Converted]);

    $this->actingAs($user)
        ->get(route('signals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('signals/Index')
            // A plain list, not a `{ data: [...] }` wrapper nested inside the paginator's own —
            // the screen reads `signals.data`.
            ->has('signals.data', 1)
            ->where('signals.data.0.status', 'new')
            // No contact field of any kind reaches the client, because there is none on the row.
            ->missing('signals.data.0.email')
            ->missing('signals.data.0.phone')
            // `raw` is the clamped Serper payload and stays on the server.
            ->missing('signals.data.0.raw')
            ->where('counts.new', 1)
            ->where('counts.dismissed', 1)
            ->where('counts.converted', 1)
            ->has('options.definitions', 3)
            ->has('options.dismiss_reasons', 3)
            ->has('stages')
            ->where('watchCount', 1)
            ->where('can.action', true));
});

it('sends money as minor units and a code, never a formatted string', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedSignal($team);

    $this->actingAs($user)
        ->get(route('signals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            // CLAUDE.md money rule: the client formats it in the team's locale.
            ->where('signals.data.0.amount_minor', 4_000_000_00)
            ->where('signals.data.0.currency', 'USD'));
});

it('shows each tab only its own signals', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedSignal($team, ['status' => SignalStatus::Dismissed, 'dismiss_reason' => SignalDismissReason::NotAnEvent]);

    $this->actingAs($user)
        ->get(route('signals.index', ['status' => 'dismissed']))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signals.data', 1)
            ->where('filters.status', 'dismissed')
            ->where('signals.data.0.dismiss_reason', 'not_an_event'));
});

it('filters by event type, relevance floor and date range', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedSignal($team, ['event_key' => SignalPresetCatalogue::FUNDING_ROUND, 'relevance' => 90, 'published_at' => now()->subDay()]);
    feedSignal($team, ['event_key' => SignalPresetCatalogue::ACQUISITION, 'relevance' => 20, 'published_at' => now()->subDays(40)]);

    $this->actingAs($user)
        ->get(route('signals.index', ['event_keys' => [SignalPresetCatalogue::FUNDING_ROUND]]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('signals.data', 1)
            ->where('signals.data.0.event_key', SignalPresetCatalogue::FUNDING_ROUND));

    $this->actingAs($user)
        ->get(route('signals.index', ['min_relevance' => 50]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('signals.data', 1)
            ->where('signals.data.0.relevance', 90));

    $this->actingAs($user)
        ->get(route('signals.index', ['from' => now()->subWeek()->toDateString()]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->has('signals.data', 1));
});

it('opens the feed rather than erroring on a filter value that no longer exists', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedSignal($team);

    // A link someone shared last month still **opens** - no 404, no 422 - and the unparseable
    // `min_relevance` is still dropped. What changed in S7 is the event key: it is honoured rather
    // than dropped, so a key matching nothing narrows to nothing. That is the honest answer now
    // that a key is not necessarily meaningless (see the next test); an earlier version dropped it
    // and answered a filtered request with the whole feed.
    $this->actingAs($user)
        ->get(route('signals.index', ['event_keys' => ['ipo'], 'min_relevance' => 0]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signals.data', 0)
            ->where('filters.event_keys', ['ipo'])
            ->where('filters.min_relevance', null)
            // Which is what lets the screen say "nothing matches these filters" with a way to clear
            // them, rather than showing everything and explaining nothing.
            ->where('counts.new', 1));
});

it('still filters by the key of a signal type the team has deleted', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    $custom = app(TeamContext::class)->run($team, fn (): SignalDefinition => SignalDefinition::factory()
        ->custom('hiring_surge', 'Hiring surge')
        ->create(['team_id' => $team->getKey()]));

    feedSignal($team, ['event_key' => 'hiring_surge']);
    feedSignal($team);

    app(TeamContext::class)->run($team, fn () => $custom->delete());

    // The signals it found keep the key and stay in the feed (ADR 0018 section 4), so filtering by
    // it is a real question with a real answer. This is the case that makes "drop an unknown key"
    // wrong: it would have made these signals impossible to isolate.
    $this->actingAs($user)
        ->get(route('signals.index', ['event_keys' => ['hiring_surge']]))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signals.data', 1)
            ->where('signals.data.0.event_key', 'hiring_surge')
            // And it still names itself, because the relation is `withTrashed()`.
            ->where('signals.data.0.event_label', 'Hiring surge'));
});

it('orders the feed by when the event happened, newest first', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();

    feedSignal($team, ['headline' => 'Older event', 'published_at' => now()->subDays(5)]);
    feedSignal($team, ['headline' => 'Newer event', 'published_at' => now()->subDay()]);

    $this->actingAs($user)
        ->get(route('signals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('signals.data.0.headline', 'Newer event')
            ->where('signals.data.1.headline', 'Older event'));
});

it('never shows another team’s signals in the feed', function (): void {
    $other = createUserWithTeam();
    feedSignal($other->currentTeam()->firstOrFail(), ['headline' => 'Someone else’s signal']);

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->get(route('signals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signals.data', 0)
            ->where('counts.new', 0)
            ->where('watchCount', 0));
});

it('tells a read-only member they can look but not act', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    feedSignal($team);

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->switchTeam($team);

    $this->actingAs($readOnly)
        ->get(route('signals.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('signals.data', 1)
            ->where('can.action', false)
            ->where('can.manageWatches', false));
});

it('requires a signed-in member', function (): void {
    $this->get(route('signals.index'))->assertRedirect(route('login'));
});

// --- Convert ------------------------------------------------------------------------------------

it('converts a signal and sends the member to the lead', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    $this->actingAs($user)
        ->post(route('signals.convert', ['signal' => $signal->getKey()]), [
            'company_name' => 'Acme DMCC',
            'person_name' => 'Jane Founder',
            'person_role' => 'Founder & CEO',
            'locality' => 'Dubai',
            'country' => 'AE',
        ])
        ->assertRedirect();

    $lead = Lead::withoutGlobalScopes()->where('team_id', $team->getKey())->sole();

    expect($lead->source)->toBe(LeadSource::Signal)
        ->and($signal->refresh()->status)->toBe(SignalStatus::Converted)
        ->and($signal->lead_id)->toBe($lead->id);

    Queue::assertPushed(EnrichLeadJob::class);
});

it('refuses a conversion with no company name', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    // A relocation signal often names a person and no company; the form has to ask rather than
    // invent a holding entity.
    $this->actingAs($user)
        ->post(route('signals.convert', ['signal' => $signal->getKey()]), ['company_name' => ''])
        ->assertSessionHasErrors('company_name');

    expect($signal->refresh()->status)->toBe(SignalStatus::New)
        ->and(Lead::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a pipeline stage from another team', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();
    $foreign = app(TeamContext::class)->run($otherTeam, fn () => PipelineStage::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'name' => 'Theirs',
        'slug' => 'theirs',
    ]));

    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    // Refused at the request, not silently dropped: the member didn't choose this, so the honest
    // answer is that the payload is wrong.
    $this->actingAs($user)
        ->post(route('signals.convert', ['signal' => $signal->getKey()]), [
            'company_name' => 'Acme DMCC',
            'pipeline_stage_id' => $foreign->getKey(),
        ])
        ->assertSessionHasErrors('pipeline_stage_id');
});

it('stops a read-only member converting', function (): void {
    $owner = createUserWithTeam();
    $team = $owner->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    $readOnly = createUserWithTeam();
    app(TeamMembership::class)->add($readOnly, $team, TeamRole::ReadOnly);
    $readOnly->switchTeam($team);

    $this->actingAs($readOnly)
        ->post(route('signals.convert', ['signal' => $signal->getKey()]), ['company_name' => 'Acme DMCC'])
        ->assertForbidden();

    expect($signal->refresh()->status)->toBe(SignalStatus::New);
});

it('404s on another team’s signal rather than admitting it exists', function (): void {
    $other = createUserWithTeam();
    $signal = feedSignal($other->currentTeam()->firstOrFail());

    $user = createUserWithTeam();

    $this->actingAs($user)
        ->post(route('signals.convert', ['signal' => $signal->getKey()]), ['company_name' => 'Acme DMCC'])
        ->assertNotFound();

    $this->actingAs($user)
        ->post(route('signals.dismiss', ['signal' => $signal->getKey()]))
        ->assertNotFound();
});

// --- Dismiss / restore --------------------------------------------------------------------------

it('dismisses a signal with an optional reason and brings it back', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    $this->actingAs($user)
        ->from(route('signals.index'))
        ->post(route('signals.dismiss', ['signal' => $signal->getKey()]), ['reason' => 'not_an_event'])
        ->assertRedirect(route('signals.index'));

    expect($signal->refresh()->status)->toBe(SignalStatus::Dismissed)
        ->and($signal->dismiss_reason)->toBe(SignalDismissReason::NotAnEvent);

    $this->actingAs($user)
        ->from(route('signals.index', ['status' => 'dismissed']))
        ->post(route('signals.restore', ['signal' => $signal->getKey()]))
        ->assertRedirect(route('signals.index', ['status' => 'dismissed']));

    expect($signal->refresh()->status)->toBe(SignalStatus::New)
        ->and($signal->dismiss_reason)->toBeNull();
});

it('rejects a dismiss reason it doesn’t recognise', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team);

    // Stored reasons have to stay usable for tuning, so an unknown one is an error rather than a
    // silently-dropped value.
    $this->actingAs($user)
        ->post(route('signals.dismiss', ['signal' => $signal->getKey()]), ['reason' => 'because'])
        ->assertSessionHasErrors('reason');
});

it('refuses to dismiss a signal that has already become a lead', function (): void {
    $user = createUserWithTeam();
    $team = $user->currentTeam()->firstOrFail();
    $signal = feedSignal($team, ['status' => SignalStatus::Converted]);

    // A web request gets the friendly message as a toast and comes back to the feed (the JSON
    // envelope with its 409 is the API's shape — see bootstrap/app.php and the API test).
    $this->actingAs($user)
        ->from(route('signals.index'))
        ->post(route('signals.dismiss', ['signal' => $signal->getKey()]))
        ->assertRedirect(route('signals.index'))
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');

    expect($signal->refresh()->status)->toBe(SignalStatus::Converted);
});
