<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\LeadSourcing\Enums\LeadSource;
use App\Modules\LeadSourcing\Events\DiscoveryRunCompleted;
use App\Modules\LeadSourcing\Models\DiscoveryRun;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\LeadsWorkspace\Actions\RecordLeadActivityAction;
use App\Modules\LeadsWorkspace\Enums\LeadActivityType;
use App\Modules\LeadsWorkspace\Listeners\RecordDiscoveryActivity;
use App\Modules\LeadsWorkspace\Listeners\RecordEnrichmentActivity;
use App\Modules\LeadsWorkspace\Listeners\RecordScoringActivity;
use App\Modules\LeadsWorkspace\Models\LeadActivity;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Events\LeadScored;
use App\Modules\Scoring\Models\LeadScore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

it('opens a timeline for every lead a completed run found', function (): void {
    [, $team] = Fixtures::team();

    $run = app(TeamContext::class)->run($team, function () use ($team): DiscoveryRun {
        $run = DiscoveryRun::factory()->create(['team_id' => $team->getKey()]);

        foreach (range(1, 3) as $i) {
            Lead::factory()->create([
                'team_id' => $team->getKey(),
                'discovery_run_id' => $run->getKey(),
                'dedupe_key' => 'no:found-'.$i,
                'source' => LeadSource::GooglePlaces,
            ]);
        }

        return $run;
    });

    app(RecordDiscoveryActivity::class)->handle(
        new DiscoveryRunCompleted((int) $team->getKey(), (int) $run->getKey()),
    );

    $activities = LeadActivity::query()->withoutGlobalScopes()->get();

    expect($activities)->toHaveCount(3)
        ->and($activities->pluck('type')->unique()->all())->toBe([LeadActivityType::Discovered])
        ->and($activities->first()?->properties)->toBe(['source' => 'google_places'])
        // The platform found them, so no member is credited.
        ->and($activities->pluck('user_id')->unique()->all())->toBe([null]);
});

it('does not double up when the discovery listener runs twice', function (): void {
    [, $team] = Fixtures::team();

    $run = app(TeamContext::class)->run($team, function () use ($team): DiscoveryRun {
        $run = DiscoveryRun::factory()->create(['team_id' => $team->getKey()]);

        Lead::factory()->create([
            'team_id' => $team->getKey(),
            'discovery_run_id' => $run->getKey(),
        ]);

        return $run;
    });

    $event = new DiscoveryRunCompleted((int) $team->getKey(), (int) $run->getKey());

    app(RecordDiscoveryActivity::class)->handle($event);
    app(RecordDiscoveryActivity::class)->handle($event);

    expect(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('shrugs off a run that has been pruned', function (): void {
    [, $team] = Fixtures::team();

    app(RecordDiscoveryActivity::class)->handle(
        new DiscoveryRunCompleted((int) $team->getKey(), 999_999),
    );

    expect(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('records an enrichment with the contacts it found', function (): void {
    [, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, [
        'website' => 'brightbean.test',
        'enriched_at' => now()->subHour(),
    ]);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->count(2)->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
    ]));

    app(RecordEnrichmentActivity::class)->handle(
        new LeadEnriched((int) $team->getKey(), (int) $lead->getKey()),
    );

    $activity = LeadActivity::query()->withoutGlobalScopes()->firstOrFail();

    expect($activity->type)->toBe(LeadActivityType::Enriched)
        ->and($activity->properties)->toBe(['contacts' => 2, 'has_website' => true])
        ->and($activity->occurred_at->toIso8601String())->toBe($lead->enriched_at?->toIso8601String());
});

it('keeps one enrichment entry, moved forward, when a lead is re-enriched', function (): void {
    [, $team] = Fixtures::team();

    $lead = Fixtures::lead($team, ['enriched_at' => now()->subDays(2)]);
    $event = new LeadEnriched((int) $team->getKey(), (int) $lead->getKey());

    app(RecordEnrichmentActivity::class)->handle($event);

    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['enriched_at' => now()])->save());

    app(RecordEnrichmentActivity::class)->handle($event);

    $activities = LeadActivity::query()->withoutGlobalScopes()->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()?->occurred_at->isToday())->toBeTrue();
});

it('records a grade, naming the profile it was measured against', function (): void {
    [, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $score = app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'icp_id' => $icp->getKey(),
        'score' => 88,
        'grade' => ScoreGrade::A,
    ]));

    app(RecordScoringActivity::class)->handle(LeadScored::for($score));

    $activity = LeadActivity::query()->withoutGlobalScopes()->firstOrFail();

    expect($activity->type)->toBe(LeadActivityType::Scored)
        ->and($activity->properties)->toBe(['grade' => 'A', 'score' => 88, 'icp' => $icp->name])
        ->and($activity->subject_id)->toBe((int) $score->getKey());
});

it('updates the grade entry rather than stacking one per re-score', function (): void {
    [, $team, $icp] = Fixtures::team();

    $lead = Fixtures::lead($team);

    $score = app(TeamContext::class)->run($team, fn (): LeadScore => LeadScore::factory()->grade(
        ScoreGrade::C,
        45,
    )->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'icp_id' => $icp->getKey(),
    ]));

    app(RecordScoringActivity::class)->handle(LeadScored::for($score));

    app(TeamContext::class)->run($team, fn () => $score->forceFill([
        'score' => 91,
        'grade' => ScoreGrade::A,
    ])->save());

    app(RecordScoringActivity::class)->handle(LeadScored::for($score->refresh()));

    $activities = LeadActivity::query()->withoutGlobalScopes()->get();

    expect($activities)->toHaveCount(1)
        ->and($activities->first()?->properties['grade'])->toBe('A')
        ->and($activities->first()?->properties['score'])->toBe(91);
});

it('credits the acting member when one is given', function (): void {
    [, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);
    $actor = User::factory()->create();

    app(TeamContext::class)->run($team, fn () => app(RecordLeadActivityAction::class)->handle(
        lead: $lead,
        type: LeadActivityType::Discovered,
        actor: $actor,
    ));

    expect(LeadActivity::query()->withoutGlobalScopes()->firstOrFail()->user_id)
        ->toBe((int) $actor->getKey());
});

it('appends an entry that has no subject, since it is a distinct event', function (): void {
    [, $team] = Fixtures::team();

    $lead = Fixtures::lead($team);

    app(TeamContext::class)->run($team, function () use ($lead): void {
        $record = app(RecordLeadActivityAction::class);

        $record->handle(lead: $lead, type: LeadActivityType::Discovered);
        $record->handle(lead: $lead, type: LeadActivityType::Discovered);
    });

    expect(LeadActivity::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('wires the timeline listeners to the events the other modules fire', function (): void {
    Event::fake();

    Event::assertListening(DiscoveryRunCompleted::class, RecordDiscoveryActivity::class);
    Event::assertListening(LeadEnriched::class, RecordEnrichmentActivity::class);
    Event::assertListening(LeadScored::class, RecordScoringActivity::class);
});
