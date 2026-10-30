<?php

declare(strict_types=1);

use App\Modules\AiInsights\Enums\InsightSeverity;
use App\Modules\AiInsights\Enums\InsightStatus;
use App\Modules\AiInsights\Enums\InsightType;
use App\Modules\AiInsights\Jobs\GeneratePeriodicInsightsJob;
use App\Modules\AiInsights\Models\Insight;
use App\Modules\AiInsights\Models\InsightRun;
use App\Modules\AiInsights\Services\InsightGenerator;
use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Exceptions\AiTransportException;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Core\Enums\OutreachRestriction;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadsWorkspace\Enums\StageOutcome;
use App\Modules\LeadsWorkspace\Models\PipelineStage;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Scoring\Enums\ScoreGrade;
use Carbon\CarbonImmutable;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\InsightFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    // External AI is always faked (CLAUDE.md test rules). The whole AI Platform pipeline still
    // runs: registry, rendering, schema validation, cost logging.
    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);

    // The digest is off unless a test is about it. The two deterministic insights are the ones
    // that must work whatever the model is doing, and a test of the stale-lead rule that also
    // needed a queued AI response would be testing two things and failing for the wrong one.
    config()->set('ai-insights.ai.enabled', false);
});

/**
 * Switch the model back on for the tests that are about it, with one response queued.
 *
 * @param  list<array<string, mixed>>  $items
 */
function withAiDigest(FakeAiTransport $fake, array $items = []): void
{
    config()->set('ai-insights.ai.enabled', true);
    $fake->queueStructured(InsightFixtures::aiDigest($items));
}

function generateFor(Team $team, ?string $now = null, bool $force = false): array
{
    return app(InsightGenerator::class)->forTeam(
        $team,
        $now === null ? null : CarbonImmutable::parse($now),
        force: $force,
    );
}

/**
 * @return Collection<int, Insight>
 */
function storedInsights(Team $team): Collection
{
    return app(TeamContext::class)->run($team, fn () => Insight::query()
        ->where('team_id', $team->getKey())
        ->orderBy('id')
        ->get());
}

// --- The two deterministic insights -------------------------------------------------------------

it('alerts on leads the team started and went quiet on, naming the most neglected first', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $oldest = InsightFixtures::staleLead($team, $icp, daysQuiet: 40, grade: ScoreGrade::A);
    $newer = InsightFixtures::staleLead($team, $icp, daysQuiet: 20);
    // Inside the window: started, but touched last week.
    InsightFixtures::staleLead($team, $icp, daysQuiet: 3);

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::StaleLeads);

    expect($insight)->not->toBeNull()
        ->and($insight->severity)->toBe(InsightSeverity::Warning)
        ->and($insight->payload['count'])->toBe(2)
        ->and($insight->payload['days'])->toBe(14)
        ->and(array_column($insight->payload['leads'], 'id'))
        ->toBe([(int) $oldest->getKey(), (int) $newer->getKey()])
        ->and($insight->payload['leads'][0]['grade'])->toBe('A');
});

it('reads the staleness cutoff as a boundary, not a rough age', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    config()->set('ai-insights.stale.after_days', 14);

    // One hour either side of a fortnight. The threshold is a product promise ("nothing for a
    // fortnight"), so it should be exactly where it says it is.
    $justInside = InsightFixtures::staleLeadQuietSince($team, $icp, now()->subDays(14)->subHour());
    InsightFixtures::staleLeadQuietSince($team, $icp, now()->subDays(14)->addHour());

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::StaleLeads);

    expect($insight->payload['count'])->toBe(1)
        ->and(array_column($insight->payload['leads'], 'id'))->toBe([(int) $justInside->getKey()]);
});

it('names a handful but counts them all', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    config()->set('ai-insights.stale.max_items', 2);

    foreach ([40, 35, 30, 25] as $days) {
        InsightFixtures::staleLead($team, $icp, daysQuiet: $days);
    }

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::StaleLeads);

    // A member with four needs the number, not four cards — and the number must be the real one,
    // or the card quietly understates the problem.
    expect($insight->payload['count'])->toBe(4)
        ->and($insight->payload['leads'])->toHaveCount(2);
});

it('does not call a lead stale when the team never started it', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    // Sourced a year ago, graded, and never picked up: a backlog, not a follow-up problem.
    InsightFixtures::uncontactedStrongLead($team, $icp, attributes: [
        'created_at' => now()->subYear(),
    ]);

    generateFor($team);

    expect(storedInsights($team)->firstWhere('type', InsightType::StaleLeads))->toBeNull();
});

it('leaves a closed lead out of the stale alert', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $lead = InsightFixtures::staleLead($team, $icp, daysQuiet: 40);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        $won = PipelineStage::factory()->create([
            'team_id' => $team->getKey(),
            'outcome' => StageOutcome::Won,
            'is_default' => false,
        ]);

        $lead->forceFill(['pipeline_stage_id' => $won->getKey()])->save();
    });

    generateFor($team);

    expect(storedInsights($team)->firstWhere('type', InsightType::StaleLeads))->toBeNull();
});

it('lists the strongest uncontacted leads to approach today, best fit first', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $weaker = InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::B, 70);
    $stronger = InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);
    // A weak fit is not on anybody's call list.
    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::D, 20);

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::ContactToday);

    expect($insight)->not->toBeNull()
        ->and($insight->severity)->toBe(InsightSeverity::Action)
        ->and($insight->payload['count'])->toBe(2)
        ->and(array_column($insight->payload['leads'], 'id'))
        ->toBe([(int) $stronger->getKey(), (int) $weaker->getKey()]);
});

it('keeps a prior-consent lead on the list but marks it introduction only', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $restricted = InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'raw' => [OutreachRestriction::RAW_KEY => 'uae_prior_consent'],
    ]);

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::ContactToday);
    $entry = collect($insight->payload['leads'])->firstWhere('id', (int) $restricted->getKey());

    // Email is closed for this lead, so the card is told to offer an introduction instead of
    // silently suggesting a message the Outreach guard would refuse.
    expect($entry)->not->toBeNull()
        ->and($entry['restricted'])->toBeTrue()
        ->and($entry['channel'])->not->toBe('email');
});

it('offers an introduction rather than an email when the company is suppressed', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $lead = InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'website' => 'https://www.acme-suppressed.test',
    ]);

    app(SuppressionList::class)->suppress(
        SuppressionType::Domain,
        'acme-suppressed.test',
        SuppressionScope::Team,
        SuppressionReason::Manual,
        (int) $team->getKey(),
    );

    generateFor($team);

    $insight = storedInsights($team)->firstWhere('type', InsightType::ContactToday);
    $entry = collect($insight->payload['leads'])->firstWhere('id', (int) $lead->getKey());

    // The guard's answer is taken as it comes rather than second-guessed: a suppression closes
    // every channel Alima would transmit on, and leaves the member's own LinkedIn introduction
    // open (docs/modules/outreach.md). So the company stays on the list with email off it — which
    // is the suggestion the rule actually supports.
    expect($entry['channel'])->not->toBe('email')
        ->and($entry['restricted'])->toBeTrue();
});

// --- The AI digest ------------------------------------------------------------------------------

it('stores the model’s read of the week alongside the deterministic insights', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    // Yesterday, so the lead falls inside the snapshot's seven-day window: Reporting's window is
    // half-open and excludes *now*, so a lead created in the same second as the report would not
    // be counted (see `DateRange`).
    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    withAiDigest($this->fakeAi);

    generateFor($team);

    $digest = storedInsights($team)->firstWhere('type', InsightType::PeriodicDigest);

    expect($digest)->not->toBeNull()
        ->and($digest->headline)->toBe('LinkedIn is replying and email is not')
        ->and($digest->severity)->toBe(InsightSeverity::Action)
        ->and($digest->payload['topic'])->toBe('outreach')
        // The trace columns a paid call leaves behind.
        ->and($digest->prompt_key)->toBe('insights.periodic_digest')
        ->and($digest->prompt_version)->toBe(1)
        ->and($digest->cost_micros)->toBeGreaterThan(0)
        // Numbers only in the prompt: no company name may reach the provider.
        ->and($this->fakeAi->lastRequest()->prompt)->not->toContain('Acme');
});

it('still stores the deterministic insights when the AI call fails', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    config()->set('ai-insights.ai.enabled', true);
    $this->fakeAi->queueFailure(AiTransportException::from(new RuntimeException('provider down')));

    generateFor($team);

    $insights = storedInsights($team);

    // A bad morning at the provider must not be the reason a team never hears who to call.
    expect($insights->pluck('type')->all())->toBe([InsightType::ContactToday])
        ->and($insights)->toHaveCount(1);
});

it('does not pay for a reading of a week in which nothing happened', function (): void {
    [, $team] = InsightFixtures::team();

    config()->set('ai-insights.ai.enabled', true);

    generateFor($team);

    expect($this->fakeAi->callCount())->toBe(0)
        ->and(storedInsights($team))->toHaveCount(0);
});

it('does not call the model at all when the digest is switched off', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    config()->set('ai-insights.ai.enabled', false);
    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);

    generateFor($team);

    expect($this->fakeAi->callCount())->toBe(0)
        ->and(storedInsights($team)->pluck('type')->all())->toBe([InsightType::ContactToday]);
});

it('keeps at most the configured number of the model’s points', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    config()->set('ai-insights.ai.max_items', 1);
    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);

    withAiDigest($this->fakeAi, [
        ['topic' => 'outreach', 'title' => 'First point', 'body' => 'One.', 'severity' => 'info'],
        ['topic' => 'pipeline', 'title' => 'Second point', 'body' => 'Two.', 'severity' => 'info'],
    ]);

    generateFor($team);

    expect(storedInsights($team)->where('type', InsightType::PeriodicDigest))->toHaveCount(1);
});

// --- Idempotence --------------------------------------------------------------------------------

it('creates nothing on a second run the same day', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    withAiDigest($this->fakeAi);

    $first = generateFor($team, '2026-10-30 07:00:00');
    $this->fakeAi->queueStructured(InsightFixtures::aiDigest());
    $second = generateFor($team, '2026-10-30 11:00:00');

    expect($first)->toHaveCount(2)
        ->and($second)->toHaveCount(0)
        ->and(storedInsights($team))->toHaveCount(2);
});

it('does not bring back an insight the team dismissed earlier the same day', function (): void {
    [$user, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);

    generateFor($team, '2026-10-30 07:00:00');

    $this->actingAs($user)
        ->post(route('insights.dismiss', ['insight' => storedInsights($team)->first()->getKey()]))
        ->assertRedirect();

    // Forced, so the day's run marker doesn't short-circuit it: this test is about the dedupe
    // key, which is the layer that has to hold when a run does happen twice.
    generateFor($team, '2026-10-30 12:00:00', force: true);

    // One row, still dismissed. `firstOrCreate` rather than `updateOrCreate` is what guarantees
    // this: a member who clears a card must not watch it reappear an hour later.
    expect(storedInsights($team))->toHaveCount(1)
        ->and(storedInsights($team)->first()->status)->toBe(InsightStatus::Dismissed);
});

it('cuts the day in the team’s own timezone, not the server’s', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    $team->forceFill(['timezone' => 'Asia/Dubai'])->save();
    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);

    // 20:30 UTC is already the 31st in Dubai, so these are two different days for this team — and
    // one day for a team in London.
    generateFor($team, '2026-10-30 20:30:00');
    generateFor($team, '2026-10-30 21:30:00');

    expect(storedInsights($team)->pluck('dedupe_key')->all())
        ->toBe(['contact_today:2026-10-31']);
});

// --- The notification ---------------------------------------------------------------------------

it('tells the team its morning read is ready, with the counts and no prose', function (): void {
    [$user, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);
    InsightFixtures::staleLead($team, $icp, daysQuiet: 40);

    generateFor($team);

    $notification = Notification::query()
        ->where('notifiable_id', $user->getKey())
        ->where('team_id', $team->getKey())
        ->firstOrFail();

    expect($notification->data['type'])->toBe(NotificationType::InsightsReady->value)
        // Keys and params, never a formed sentence (Phase 12's rule).
        ->and($notification->data['params']['contact'])->toBe(1)
        ->and($notification->data['params']['stale'])->toBe(1)
        ->and($notification->data['body'])->toBe(':contact companies are worth approaching today.')
        ->and($notification->data['url'])->toBe('/insights');
});

it('says nothing when it has nothing to say', function (): void {
    [$user, $team] = InsightFixtures::team();

    generateFor($team);

    expect(Notification::query()->where('notifiable_id', $user->getKey())->count())->toBe(0);
});

// --- The once-a-day marker ----------------------------------------------------------------------

it('stamps the day as done even when it found nothing to say', function (): void {
    [, $team] = InsightFixtures::team();

    expect(generateFor($team, '2026-10-30 07:00:00'))->toHaveCount(0);

    $run = InsightRun::query()->withoutGlobalScopes()->firstOrFail();

    // The whole point of the marker: a day that produced no insights is still a day that has been
    // done. Without it the hourly sweep would re-run this team — and re-pay for the aggregates and
    // the AI call — every hour until midnight.
    expect($run->local_date)->toBe('2026-10-30')
        ->and($run->insights_created)->toBe(0)
        ->and($run->ai_called)->toBeFalse();
});

it('does nothing on a second run the same day, without touching the model', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    withAiDigest($this->fakeAi);

    generateFor($team, '2026-10-30 07:00:00');
    $callsAfterFirst = $this->fakeAi->callCount();

    expect(generateFor($team, '2026-10-30 11:00:00'))->toHaveCount(0)
        ->and($this->fakeAi->callCount())->toBe($callsAfterFirst);
});

it('records what the run cost and whether the model was asked', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    withAiDigest($this->fakeAi);

    generateFor($team);

    $run = InsightRun::query()->withoutGlobalScopes()->firstOrFail();

    expect($run->insights_created)->toBe(2)
        ->and($run->ai_called)->toBeTrue()
        ->and($run->cost_micros)->toBeGreaterThan(0);
});

it('does not pay the model twice when a run is forced', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95, [
        'created_at' => now()->subDay(),
    ]);
    withAiDigest($this->fakeAi);

    generateFor($team, '2026-10-30 07:00:00');

    // `insights:generate --team=` runs again on purpose. The day already has its digest, so the
    // model is not asked — otherwise a support request would spend real money, and a longer answer
    // than the first would slip extra cards past the dedupe keys.
    generateFor($team, '2026-10-30 09:00:00', force: true);

    expect($this->fakeAi->callCount())->toBe(1);
});

// --- The job ------------------------------------------------------------------------------------

it('runs the generation inside the team’s context from the job', function (): void {
    [, $team, $icp] = InsightFixtures::team();

    InsightFixtures::uncontactedStrongLead($team, $icp, ScoreGrade::A, 95);

    (new GeneratePeriodicInsightsJob((int) $team->getKey()))
        ->handle(app(InsightGenerator::class), app(TeamContext::class));

    expect(storedInsights($team))->toHaveCount(1)
        // The row was stamped with the team even though nothing had set a team context.
        ->and(storedInsights($team)->first()->team_id)->toBe((int) $team->getKey());
});

it('does nothing when the team has been deleted since the sweep queued it', function (): void {
    [, $team] = InsightFixtures::team();
    $teamId = (int) $team->getKey();

    $team->delete();

    (new GeneratePeriodicInsightsJob($teamId))
        ->handle(app(InsightGenerator::class), app(TeamContext::class));

    expect(Insight::query()->withoutGlobalScopes()->count())->toBe(0);
});
