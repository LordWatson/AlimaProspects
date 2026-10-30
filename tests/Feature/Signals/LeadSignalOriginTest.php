<?php

declare(strict_types=1);

use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Contracts\LeadOriginProvider;
use App\Modules\Signals\Actions\ConvertSignalToLeadAction;
use App\Modules\Signals\Data\ConvertSignalData;
use App\Modules\Signals\Enums\SignalEventType;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Services\SignalLeadOriginProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Queue::fake();
    Event::fake();
});

it('binds the Signals origin provider over the workspace default', function (): void {
    // The dependency points Signals → Leads Workspace, never the other way: the workspace binds a
    // provider that answers "no origin", and Signals replaces it.
    expect(app(LeadOriginProvider::class))->toBeInstanceOf(SignalLeadOriginProvider::class);
});

it('describes the event a converted lead came from', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->acquisition()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'source_name' => 'Gulf Business',
        ]);
    });

    $origin = app(TeamContext::class)->run($team, function () use ($signal, $actor): ?object {
        $lead = app(ConvertSignalToLeadAction::class)->handle($signal, $actor, ConvertSignalData::prefillFrom($signal));

        return app(LeadOriginProvider::class)->originFor($lead);
    });

    expect($origin)->not->toBeNull()
        ->and($origin->kind)->toBe('signal')
        ->and($origin->label)->toBe(LeadSource::Signal->label())
        ->and($origin->headline)->toBe($signal->headline)
        ->and($origin->detail)->toBe(SignalEventType::Acquisition->label())
        ->and($origin->url)->toBe($signal->source_url)
        ->and($origin->sourceName)->toBe('Gulf Business')
        ->and($origin->occurredAt)->not->toBeNull()
        // The Converted tab, where the signal now lives — not a `?signal=` filter the feed
        // doesn't have.
        ->and($origin->href)->toBe('/signals?status=converted');
});

it('shows the event on a lead the signal was attached to', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $origin = app(TeamContext::class)->run($team, function () use ($team, $actor): ?object {
        // The company was already on file from the registry, so the conversion attaches to that
        // lead and leaves its stronger attribution — `source` stays Companies House.
        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
            'company_name' => 'Acme DMCC',
            'dedupe_key' => 'name:signal:acme-dmcc:'.substr(sha1('acme-dmcc'), 0, 12),
        ]);

        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        $lead = app(ConvertSignalToLeadAction::class)->handle($signal, $actor, ConvertSignalData::prefillFrom($signal));

        return app(LeadOriginProvider::class)->originFor($lead);
    });

    // The event is still where this lead came to the team's attention, so the panel keys off the
    // `raw.signal_id` pointer rather than the lead's source.
    expect($origin)->not->toBeNull()
        ->and($origin->kind)->toBe('signal')
        ->and($origin->headline)->not->toBeNull();
});

it('reports no origin for an ordinary discovered lead', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $origin = app(TeamContext::class)->run($team, function () use ($team): ?object {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::CompaniesHouse,
        ]);

        return app(LeadOriginProvider::class)->originFor($lead);
    });

    expect($origin)->toBeNull();
});

it('reports no origin once the signal has been swept away', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $origin = app(TeamContext::class)->run($team, function () use ($team, $actor): ?object {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);
        $signal = Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);

        $lead = app(ConvertSignalToLeadAction::class)->handle($signal, $actor, ConvertSignalData::prefillFrom($signal));

        // The retention sweep (S6) removes the signal; the lead and its `source` attribution live on.
        $signal->delete();

        return app(LeadOriginProvider::class)->originFor($lead->refresh());
    });

    expect($origin)->toBeNull();
});

it('never resolves another team’s signal from a tampered payload', function (): void {
    $other = createUserWithTeam();
    $otherTeam = $other->currentTeam()->firstOrFail();

    $foreign = app(TeamContext::class)->run($otherTeam, function () use ($otherTeam): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $otherTeam->getKey()]);

        return Signal::factory()->create([
            'team_id' => $otherTeam->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'headline' => 'Someone else’s private signal',
        ]);
    });

    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $origin = app(TeamContext::class)->run($team, function () use ($team, $foreign): ?object {
        $lead = Lead::factory()->create([
            'team_id' => $team->getKey(),
            'source' => LeadSource::Signal,
            'raw' => ['signal_id' => $foreign->getKey()],
        ]);

        return app(LeadOriginProvider::class)->originFor($lead);
    });

    expect($origin)->toBeNull();
});

it('sends the origin and the outreach restriction to the lead detail screen', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $signal = app(TeamContext::class)->run($team, function () use ($team): Signal {
        $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

        return Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
        ]);
    });

    $lead = app(TeamContext::class)->run($team, fn (): Lead => app(ConvertSignalToLeadAction::class)
        ->handle($signal, $actor, ConvertSignalData::prefillFrom($signal)));

    $this->actingAs($actor)
        ->get(route('leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('origin.kind', 'signal')
            ->where('origin.headline', $signal->headline)
            ->where('lead.outreach_restriction.value', OutreachRestriction::UaePriorConsent->value)
            ->where('lead.outreach_restriction.allows_direct_marketing', false));
});

it('sends no origin for a lead that was simply discovered', function (): void {
    $actor = createUserWithTeam();
    $team = $actor->currentTeam()->firstOrFail();

    $lead = app(TeamContext::class)->run($team, fn (): Lead => Lead::factory()->create([
        'team_id' => $team->getKey(),
        'source' => LeadSource::GooglePlaces,
        'raw' => null,
    ]));

    $this->actingAs($actor)
        ->get(route('leads.show', $lead))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('origin', null)
            ->where('lead.outreach_restriction', null));
});
