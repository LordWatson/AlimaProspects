<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Contracts\AiClient;
use App\Modules\AiPlatform\Contracts\AiTransport;
use App\Modules\AiPlatform\Testing\FakeAiTransport;
use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Enums\PlanKey;
use App\Modules\Billing\Services\PlanSignalQuotaSource;
use App\Modules\Billing\Services\UsageLedger;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Signals\Actions\IngestSignalEventAction;
use App\Modules\Signals\Actions\RotateSignalWebhookSecretAction;
use App\Modules\Signals\Actions\RunSignalPlaybooksAction;
use App\Modules\Signals\Actions\RunSignalWatchAction;
use App\Modules\Signals\Actions\SaveSignalPlaybookAction;
use App\Modules\Signals\Actions\SyncPipelineWatchlistAction;
use App\Modules\Signals\Actions\TriggerSignalWatchAction;
use App\Modules\Signals\Contracts\SignalQuotaSource;
use App\Modules\Signals\Data\SignalPlaybookConditions;
use App\Modules\Signals\Data\SignalPlaybookData;
use App\Modules\Signals\Data\SignalPlaybookStep;
use App\Modules\Signals\Enums\PlaybookActionKind;
use App\Modules\Signals\Enums\PlaybookMode;
use App\Modules\Signals\Enums\SignalRunStatus;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Enums\WatchlistSourceKind;
use App\Modules\Signals\Exceptions\SignalAllowanceExceeded;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalPlaybook;
use App\Modules\Signals\Models\SignalPreview;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use App\Modules\Signals\Models\SignalWatchlist;
use App\Modules\Signals\Models\SignalWatchlistEntry;
use App\Modules\Signals\Models\SignalWebhookSecret;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Services\SignalSpendGuard;
use App\Modules\Signals\Services\SignalUsageCounter;
use App\Modules\Signals\Services\StubSignalQuotaSource;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use App\Modules\Signals\Support\SignalSourceBreakdown;
use App\Modules\Signals\Testing\FakeSignalSource;
use Database\Seeders\AiPromptTemplateSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BillingFixtures as Billing;

uses(RefreshDatabase::class);

/**
 * Phase S16, tasks 1–3: nothing in Signals can spend unbounded money.
 *
 * The three things being asserted here are the three the phase exists for, and they are separate
 * claims: the **seam** is shaped like the other three Billing fills in, the **guard** refuses at the
 * point of spend rather than in middleware, and the **meter** is derived from the ledgers rather
 * than incremented.
 */
beforeEach(function (): void {
    $this->seed(AiPromptTemplateSeeder::class);

    $this->fakeNews = new FakeSignalSource;
    app(SignalSourceRegistry::class)->register($this->fakeNews);

    $this->fakeAi = new FakeAiTransport;
    app()->instance(AiTransport::class, $this->fakeAi);
    app()->forgetInstance(AiClient::class);
});

function governedWatch(Team $team): SignalWatch
{
    return app(TeamContext::class)->run($team, fn (): SignalWatch => SignalWatch::factory()
        ->watchingPresets([SignalPresetCatalogue::FUNDING_ROUND])
        ->create([
            'team_id' => $team->getKey(),
            'locations' => ['Dubai'],
        ]));
}

/**
 * A finished run with a known number of paid queries behind it — the ledger the monthly allowance
 * is measured over.
 */
function spentQueries(Team $team, SignalWatch $watch, int $queries, ?int $daysAgo = null): SignalRun
{
    return app(TeamContext::class)->run($team, function () use ($team, $watch, $queries, $daysAgo): SignalRun {
        $run = SignalRun::query()->create([
            'team_id' => $team->getKey(),
            'signal_watch_id' => $watch->getKey(),
            'status' => SignalRunStatus::Completed,
            'queries_count' => $queries,
            // **The figure the allowance is metered on**, which is not `queries_count`: three of
            // the five sources are free, so coverage and cost are two columns (S16 review). These
            // fixtures are all news queries, so the two are equal.
            'paid_queries_count' => $queries,
            'results_count' => 0,
            'signals_created' => 0,
            'cost_micros' => $queries * 1_000,
            'started_at' => now(),
            'finished_at' => $daysAgo === null ? now() : now()->subDays($daysAgo),
        ]);

        return $run->refresh();
    });
}

/**
 * Cap the month's searches at a figure that is also the **hard stop**.
 *
 * `monthly_source_queries` is a `BillingMetric`, so on a plan with overage the guard enforces the
 * plan's *ceiling* — the allowance times `overage.multiplier` — exactly as it does for leads. That
 * is the behaviour, and there is a test for it below; the three tests that want to meet a stop say
 * so by flattening the multiplier rather than by doing the arithmetic in their heads.
 */
function capSearchesHard(int $queries): void
{
    config()->set('billing.overage.multiplier', 1.0);
    Billing::capSignalAllowance('monthly_source_queries', $queries);
}

// --- Task 1: the seam -------------------------------------------------------------------------

it('ships the module’s own floors as its default allowance', function (): void {
    // Not `SignalAllowance::unlimited()`, and that is the deviation this phase made deliberately:
    // the three standing ceilings are cost guards S6, S7 and S10 shipped, so a deployment with no
    // Billing has to keep them. What the stub *is* permissive about is the two monthly allowances,
    // which did not exist before plans did.
    $team = Team::factory()->create();
    $allowance = (new StubSignalQuotaSource)->allowanceFor($team);

    expect($allowance->maxDefinitions)->toBe((int) config('signals.max_definitions_per_team'))
        ->and($allowance->maxWatches)->toBe((int) config('signals.max_watches_per_team'))
        ->and($allowance->maxWatchlistEntries)->toBe((int) config('signals.watchlist.max_entries'))
        ->and($allowance->monthlySourceQueries)->toBeNull()
        ->and($allowance->monthlyPreviewQueries)->toBeNull()
        ->and($allowance->playbooksEnabled)->toBeTrue()
        ->and($allowance->webhookSourceEnabled)->toBeTrue();
});

it('lets Billing’s binding win over the module default', function (): void {
    // The seam's whole purpose: the module ships a default, Billing is registered last, and nothing
    // in Signals imports it.
    expect(app(SignalQuotaSource::class))->toBeInstanceOf(PlanSignalQuotaSource::class);

    [, $team] = Billing::team();
    Billing::moveToPlan($team, PlanKey::Team);

    expect(app(SignalSpendGuard::class)->allowanceFor($team)->maxWatches)
        ->toBe((int) config('billing.plans.team.signals.max_watches'));
});

it('falls through to the module floor for a figure the plan does not set', function (): void {
    // Per-figure fall-through, not wholesale replacement — a half-written plan definition must not
    // silently uncap four dimensions. The same reading `PlanCapabilityGate` gives a plan with no
    // `capabilities` key.
    [, $team] = Billing::team();
    Billing::moveToPlan($team, PlanKey::Team);
    // **Forgotten, not nulled.** A null is a plan declaring "unlimited" and wins; only an absent
    // key is a plan with no position, and only that one reaches the module's floor.
    Billing::forgetSignalAllowance('max_watchlist_entries');
    config()->set('signals.watchlist.max_entries', 7);

    expect(app(SignalSpendGuard::class)->maxWatchlistEntries($team))->toBe(7);
});

it('reports an unlimited plan as unlimited', function (): void {
    [, $team] = Billing::team();
    Billing::moveToPlan($team, PlanKey::Enterprise);

    $allowance = app(SignalSpendGuard::class)->allowanceFor($team);

    expect($allowance->maxWatches)->toBeNull()
        ->and($allowance->monthlySourceQueries)->toBeNull()
        ->and(app(SignalSpendGuard::class)->remainingSourceQueries($team))->toBeNull();
});

it('turns a sub-feature off for a plan that says so', function (): void {
    [, $team] = Billing::team();
    Billing::capSignalAllowance('playbooks', false);
    Billing::capSignalAllowance('webhook_source', false);

    $guard = app(SignalSpendGuard::class);

    expect($guard->allowsPlaybooks($team))->toBeFalse()
        ->and($guard->allowsWebhookSource($team))->toBeFalse();

    expect(fn () => $guard->assertPlaybooksAllowed($team))
        ->toThrow(SignalAllowanceExceeded::class);
});

// --- Task 2: the guard refuses at the point of spend ------------------------------------------

it('records a clean refusal on the run when the month’s searches are gone', function (): void {
    // **The thing this phase is for.** A scheduled run has no request behind it, so there is no
    // middleware to refuse it — and the refusal has to land somewhere a team can see, which is the
    // run. Not a half-run, and not a failure that reads as the news provider being down.
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    capSearchesHard(10);
    spentQueries($team, $watch, 10);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($run->status)->toBe(SignalRunStatus::Failed)
        ->and($run->error)->toContain('signal searches')
        // Nothing was spent and nothing was asked for: the refusal is before any external work.
        ->and($run->queries_count)->toBe(0)
        ->and($run->cost_micros)->toBe(0)
        ->and($this->fakeNews->queries())->toBe([]);
});

it('refuses “run now” without spending the throttle token', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    capSearchesHard(5);
    spentQueries($team, $watch, 5);

    expect(fn () => app(TriggerSignalWatchAction::class)->handle($watch))
        ->toThrow(SignalAllowanceExceeded::class);

    // No queued run was opened, so nothing on screen claims a run is coming.
    expect(SignalRun::withoutGlobalScopes()->where('status', SignalRunStatus::Queued)->count())->toBe(0);
});

it('clamps a run to what is left rather than refusing it', function (): void {
    // A partial allowance is not a refusal: a team with twelve searches left gets a twelve-search
    // run that says it was trimmed, which is the same split `LeadQuota::clamp()` draws.
    [, $team] = Billing::team();

    capSearchesHard(100);
    config()->set('signals.max_queries_per_run', 40);

    $watch = governedWatch($team);

    expect(app(SignalSpendGuard::class)->maxQueriesPerRun($team))->toBe(40);

    spentQueries($team, $watch, 88);

    expect(app(SignalSpendGuard::class)->maxQueriesPerRun($team))->toBe(12);
});

it('counts a failed run’s searches, because they were paid for', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    app(TeamContext::class)->run($team, fn () => SignalRun::query()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
        'status' => SignalRunStatus::Failed,
        'queries_count' => 6,
        'paid_queries_count' => 6,
        'results_count' => 0,
        'signals_created' => 0,
        'cost_micros' => 6_000,
        'started_at' => now(),
        'finished_at' => now(),
    ]));

    expect(app(SignalUsageCounter::class)->sourceQueriesThisMonth($team))->toBe(6);
});

it('does not count a run that has not finished', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    app(TeamContext::class)->run($team, fn () => SignalRun::query()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
        'status' => SignalRunStatus::Running,
        'queries_count' => 9,
        'paid_queries_count' => 9,
        'results_count' => 0,
        'signals_created' => 0,
        'cost_micros' => 0,
        'started_at' => now(),
    ]));

    expect(app(SignalUsageCounter::class)->sourceQueriesThisMonth($team))->toBe(0);
});

it('measures the allowance over the calendar month in UTC', function (): void {
    // The window Billing's allowances run on (ADR 0012), so a team reading their bill and a team
    // meeting a ceiling are talking about the same period.
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    spentQueries($team, $watch, 30, daysAgo: 45);
    spentQueries($team, $watch, 4);

    expect(app(SignalUsageCounter::class)->sourceQueriesThisMonth($team))->toBe(4);
});

it('keeps one team’s spend out of another’s allowance', function (): void {
    [, $a] = Billing::team();
    [, $b] = Billing::team();

    spentQueries($a, governedWatch($a), 25);

    expect(app(SignalUsageCounter::class)->sourceQueriesThisMonth($b))->toBe(0);
});

it('counts a preview’s searches separately from a run’s', function (): void {
    // Two ledgers and two ceilings, deliberately: a run is a standing instruction a team
    // configured and a preview is a button somebody is pressing now. One total would let an
    // afternoon of iterating eat a month of scheduled coverage.
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    spentQueries($team, $watch, 10);

    app(TeamContext::class)->run($team, fn () => SignalPreview::query()->create([
        'team_id' => $team->getKey(),
        'created_by' => null,
        'draft_hash' => str_repeat('a', 40),
        'queries_count' => 3,
        'results_count' => 0,
        'kept_count' => 0,
        'cost_micros' => 3_000,
    ]));

    $usage = app(SignalUsageCounter::class);

    expect($usage->sourceQueriesThisMonth($team))->toBe(10)
        ->and($usage->previewQueriesThisMonth($team))->toBe(3);
});

it('refuses a preview once the month’s preview searches are gone', function (): void {
    [, $team] = Billing::team();

    // Not a `BillingMetric`, so no multiplier: the plan's figure *is* the stop.
    Billing::capSignalAllowance('monthly_preview_queries', 3);

    app(TeamContext::class)->run($team, fn () => SignalPreview::query()->create([
        'team_id' => $team->getKey(),
        'created_by' => null,
        'draft_hash' => str_repeat('b', 40),
        'queries_count' => 3,
        'results_count' => 0,
        'kept_count' => 0,
        'cost_micros' => 3_000,
    ]));

    expect(fn () => app(SignalSpendGuard::class)->assertPreviewQueriesRemain($team))
        ->toThrow(SignalAllowanceExceeded::class);
});

// --- Task 3: the meter is derived, not incremented --------------------------------------------

it('derives the usage meter from the run ledger', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    spentQueries($team, $watch, 17);

    $usage = app(TeamContext::class)->run(
        $team,
        fn () => app(UsageLedger::class)->usage($team, BillingMetric::SignalQueries),
    );

    expect($usage->used)->toBe(17);
});

it('writes a meter row that matches the ledger, and re-syncing changes nothing', function (): void {
    // Idempotent by construction: `sync()` writes absolute values read from the ledger rather than
    // adding to what is there, which is what makes it a record and not a counter (ADR 0012).
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    spentQueries($team, $watch, 11);

    $sync = fn (): array => app(TeamContext::class)->run($team, fn () => app(UsageLedger::class)->sync($team));

    $first = $sync();
    $second = $sync();

    expect($first[BillingMetric::SignalQueries->value]->used)->toBe(11)
        ->and($second[BillingMetric::SignalQueries->value]->used)->toBe(11);
});

it('meters the plan ceiling rather than the included allowance on a metered plan', function (): void {
    // `monthly_source_queries` is the one of the eight that is also a `BillingMetric`, so the figure
    // the guard enforces is the plan's ceiling — allowance times the overage multiplier — exactly as
    // it is for leads. The meter and the refusal cannot disagree, because they are one computation.
    [, $team] = Billing::team();
    Billing::moveToPlan($team, PlanKey::Team);
    Billing::capSignalAllowance('monthly_source_queries', 100);
    config()->set('billing.overage.multiplier', 2.0);

    expect(app(SignalSpendGuard::class)->allowanceFor($team)->monthlySourceQueries)->toBe(200);
});

// --- The switches, through the Actions that enforce them (S16 review: test gaps) -------------

it('refuses a playbook the plan does not include, and still lets one be paused', function (): void {
    [$user, $team] = Billing::team();

    $playbook = app(TeamContext::class)->run($team, fn (): SignalPlaybook => SignalPlaybook::factory()
        ->create(['team_id' => $team->getKey(), 'is_active' => true, 'mode' => PlaybookMode::Live]));

    Billing::capSignalAllowance('playbooks', false);

    $live = fn (bool $active, PlaybookMode $mode): SignalPlaybookData => new SignalPlaybookData(
        name: 'Handle funding rounds',
        conditions: new SignalPlaybookConditions,
        steps: [new SignalPlaybookStep(kind: PlaybookActionKind::AddTag, params: ['tags' => ['hot']])],
        mode: $mode,
        isActive: $active,
    );

    $save = fn (SignalPlaybookData $data): SignalPlaybook => app(SaveSignalPlaybookAction::class)
        ->handle($team, $user, $data, $playbook);

    // Creating one, or leaving one running, is refused.
    expect(fn () => $save($live(true, PlaybookMode::Live)))->toThrow(SignalAllowanceExceeded::class);

    // **But pausing it is not.** Refusing every update trapped a downgraded team: the evaluator
    // skips the rule, and the only way to stop it being *listed* as live was to delete it.
    $paused = $save($live(false, PlaybookMode::Live));

    expect($paused->is_active)->toBeFalse();
});

it('stops evaluating playbooks for a team whose plan dropped them', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    app(TeamContext::class)->run($team, fn (): SignalPlaybook => SignalPlaybook::factory()
        ->create(['team_id' => $team->getKey(), 'is_active' => true, 'mode' => PlaybookMode::Live]));

    $signal = app(TeamContext::class)->run($team, fn (): Signal => Signal::factory()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
    ]));

    Billing::capSignalAllowance('playbooks', false);

    // **Quietly, not by throwing.** The caller is a listener on an after-commit event and therefore
    // runs inside the watch's own job: a throw would fail the run and the retry would re-bill the
    // Serper search (the S14 review's finding). Nothing is deferred either — there is nothing to
    // come back for.
    $result = app(RunSignalPlaybooksAction::class)->handle($team, [(int) $signal->getKey()]);

    expect($result)->toBe(['considered' => 0, 'fired' => 0, 'deferred' => []]);
});

it('refuses to mint an events-API secret the plan does not include', function (): void {
    [$user, $team] = Billing::team();

    $definition = signalDefinition($team);

    Billing::capSignalAllowance('webhook_source', false);

    // A credential for a source the plan does not include is one nothing will ever accept, so the
    // screen says so before somebody wires an integration against it.
    expect(fn () => app(RotateSignalWebhookSecretAction::class)->handle($team, $user, $definition))
        ->toThrow(SignalAllowanceExceeded::class);
});

it('refuses to accept a pushed event once the plan drops the events API', function (): void {
    [, $team] = Billing::team();

    $definition = signalDefinition($team);

    $secret = app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::factory()
        ->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
        ]));

    Billing::capSignalAllowance('webhook_source', false);

    expect(fn () => app(IngestSignalEventAction::class)->handle(
        definition: $definition,
        idempotencyKey: 'evt-1',
        event: ['title' => 'Acme signed a renewal'],
        payload: ['title' => 'Acme signed a renewal'],
        secret: $secret,
    ))->toThrow(SignalAllowanceExceeded::class);

    // Refused before the insert and outside the transaction, so there is no row and no stamp.
    expect(SignalInboundEvent::withoutGlobalScopes()->count())->toBe(0)
        ->and($secret->refresh()->last_used_at)->toBeNull();
});

it('stops the pipeline sync at the plan\'s watchlist ceiling', function (): void {
    [, $team] = Billing::team();

    Billing::capSignalAllowance('max_watchlist_entries', 2);

    [$list] = app(TeamContext::class)->run($team, function () use ($team): array {
        $list = SignalWatchlist::factory()->create([
            'team_id' => $team->getKey(),
            'name' => 'Mirrored pipeline',
            'source_kind' => WatchlistSourceKind::Pipeline,
        ]);

        foreach (['Alpha Ltd', 'Bravo Ltd', 'Charlie Ltd', 'Delta Ltd'] as $index => $name) {
            Lead::factory()->create([
                'team_id' => $team->getKey(),
                'company_name' => $name,
                'dedupe_key' => 'no:sync-cap-'.$index,
            ]);
        }

        return [$list];
    });

    $result = app(SyncPipelineWatchlistAction::class)->handle($team, $list);

    // **Stops adding rather than refusing.** A mirrored pipeline grows on its own, so a nightly
    // failure would be a cost nobody chose and an error nobody asked for.
    expect($result['added'])->toBe(2)
        ->and(SignalWatchlistEntry::withoutGlobalScopes()->count())->toBe(2);
});

// --- The paid-query figure (S16 review finding 3) ---------------------------------------------

it('counts only the searches we are billed for', function (): void {
    // `queries_count` is a coverage figure across every source and three of the five are free —
    // Companies House shares Lead Sourcing's quota, and a feed and the webhook make no request we
    // pay for. Metering the allowance on coverage charged a team for requests that cost nothing.
    $breakdown = new SignalSourceBreakdown;

    $breakdown->recordQuery(SignalSourceKind::News, results: 3);
    $breakdown->recordQuery(SignalSourceKind::News, results: 0);
    $breakdown->recordQuery(SignalSourceKind::Feed, results: 4);
    $breakdown->recordQuery(SignalSourceKind::Webhook, results: 1);

    expect($breakdown->totalQueriesAttempted())->toBe(4)
        ->and($breakdown->totalPaidQueriesAttempted())->toBe(2);
});

it('meters the allowance on the paid figure, not the coverage figure', function (): void {
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    app(TeamContext::class)->run($team, fn () => SignalRun::query()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
        'status' => SignalRunStatus::Completed,
        'queries_count' => 40,
        'paid_queries_count' => 6,
        'results_count' => 0,
        'signals_created' => 0,
        'cost_micros' => 6_000,
        'started_at' => now(),
        'finished_at' => now(),
    ]));

    expect(app(SignalUsageCounter::class)->sourceQueriesThisMonth($team))->toBe(6);
});

it('tells a quota refusal apart from a provider failure on the run', function (): void {
    // The code travels with the refusal so the platform's failure rate and an operator's alerts can
    // tell "this team is at its ceiling" from "the news provider is down".
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    capSearchesHard(4);
    spentQueries($team, $watch, 4);

    $run = app(TeamContext::class)->run($team, fn (): SignalRun => app(RunSignalWatchAction::class)->handle($watch));

    expect($run->error_code)->toBe('signals.source_quota_spent')
        ->and(SignalRun::refusalCodes())->toContain('signals.source_quota_spent');
});

it('still counts a provider failure that carries no error code', function (): void {
    // **The three-valued-logic trap, pinned.** The first version of this filter was
    // `whereNotIn('error_code', …)`, and `error_code NOT IN ('…')` is `NULL` — so it does not match
    // — for a row whose code *is* null. That is every failure from before the column existed, every
    // run settled by `RunSignalWatchJob::failed()` (worker death, retries exhausted) and the
    // AI-credit branch: the filter added to hide one refusal hid almost every real outage, and a
    // falling failure rate reads as good news (found in the S16 security review).
    [, $team] = Billing::team();
    $watch = governedWatch($team);

    $failed = fn (?string $code): SignalRun => app(TeamContext::class)->run($team, fn (): SignalRun => SignalRun::query()->create([
        'team_id' => $team->getKey(),
        'signal_watch_id' => $watch->getKey(),
        'status' => SignalRunStatus::Failed,
        'error' => 'Something went wrong.',
        'error_code' => $code,
        'queries_count' => 1,
        'paid_queries_count' => 1,
        'results_count' => 0,
        'signals_created' => 0,
        'cost_micros' => 1_000,
        'started_at' => now(),
        'finished_at' => now(),
    ]));

    $noCode = $failed(null);
    $provider = $failed('signals.source_unavailable');
    $refusal = $failed('signals.source_quota_spent');
    // The sibling allowance, which was in the null set until `/code-review` pointed it out: being
    // out of AI credit is the same kind of answer about the same kind of allowance.
    $aiCredit = $failed(SignalRun::AI_CREDIT_EXHAUSTED);

    $counted = SignalRun::query()->withoutGlobalScopes()->providerFailures()->pluck('id')->all();

    expect($counted)->toContain((int) $noCode->getKey())
        ->and($counted)->toContain((int) $provider->getKey())
        ->and($counted)->not->toContain((int) $refusal->getKey())
        ->and($counted)->not->toContain((int) $aiCredit->getKey());
});
