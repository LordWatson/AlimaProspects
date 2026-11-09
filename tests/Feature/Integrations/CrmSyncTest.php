<?php

declare(strict_types=1);

use App\Modules\Billing\Enums\BillingMetric;
use App\Modules\Billing\Services\UsageAllowance;
use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Integrations\Actions\ConnectIntegrationAction;
use App\Modules\Integrations\Actions\DisconnectIntegrationAction;
use App\Modules\Integrations\Actions\SyncLeadToCrmAction;
use App\Modules\Integrations\Contracts\CrmRowAllowance;
use App\Modules\Integrations\Enums\CrmProvider;
use App\Modules\Integrations\Enums\IntegrationStatus;
use App\Modules\Integrations\Enums\SyncOutcome;
use App\Modules\Integrations\Enums\SyncTarget;
use App\Modules\Integrations\Jobs\PushLeadToCrmJob;
use App\Modules\Integrations\Listeners\AutoSyncScoredLead;
use App\Modules\Integrations\Models\Integration;
use App\Modules\Integrations\Models\IntegrationLink;
use App\Modules\Integrations\Models\IntegrationSyncLog;
use App\Modules\Integrations\Services\CrmConnectorRegistry;
use App\Modules\Integrations\Services\UnmeteredCrmRows;
use App\Modules\LeadSourcing\Models\Lead;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Scoring\Enums\ScoreGrade;
use App\Modules\Scoring\Events\LeadScored;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Pennant\Feature;
use Tests\Support\BillingFixtures;
use Tests\Support\FakeCrmConnector;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * PL7's CRM framework, exercised through a fake connector.
 *
 * No real connector ships in PL7 — Pipedrive is PL8 and HubSpot is PL9 — and that is exactly why
 * this file matters: everything that makes a push *safe* happens around the connector, and a
 * framework whose guards were never run would hand PL8 a set of untested promises.
 */
function crmTeam(): array
{
    [$user, $team, $icp] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Webhooks->value);

    return [$user, $team, $icp];
}

function connectCrm(Team $team, ?FakeCrmConnector $connector = null, array $settings = []): array
{
    $connector ??= new FakeCrmConnector;

    app(CrmConnectorRegistry::class)->register($connector);

    $integration = new Integration([
        'team_id' => $team->getKey(),
        'provider' => CrmProvider::Pipedrive->value,
        'status' => IntegrationStatus::Connected->value,
        'settings' => $settings,
    ]);

    $integration->forceFill([
        'access_token' => 'access-1',
        'refresh_token' => 'refresh-1',
        'expires_at' => now()->addHour(),
        'external_account_id' => 'acct-1',
        'external_account_name' => 'Acme CRM',
    ])->save();

    return [$integration, $connector];
}

function sync(Integration $integration, Lead $lead): SyncOutcome
{
    return app(SyncLeadToCrmAction::class)->handle($integration, $lead->fresh() ?? $lead);
}

function logsFor(Integration $integration): array
{
    return IntegrationSyncLog::query()
        ->withoutGlobalScopes()
        ->where('integration_id', $integration->getKey())
        ->orderBy('id')
        ->get()
        ->all();
}

beforeEach(function (): void {
    // Generous enough that metering is never what a behaviour test trips over; the two tests about
    // the allowance set their own figure.
    BillingFixtures::capAllowance(BillingMetric::CrmSync, 10_000);
});

// --- The happy path -------------------------------------------------------------------------

it('creates the company, links it, writes the note and logs what it did', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team, settings: ['create_deals' => true]);

    $lead = Fixtures::lead($team, [
        'company_name' => 'Bright Bean Coffee',
        'website' => 'brightbean.test',
        'why_now_summary' => 'They opened a second site last month.',
    ]);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Created);

    expect($connector->companies)->toHaveCount(1)
        ->and($connector->companies[0]['name'])->toBe('Bright Bean Coffee')
        ->and($connector->deals)->toBe(1)
        // The why-now sentence travels in the note, because that is the one thing a prospect's own
        // account manager reads about why we are in their CRM.
        ->and($connector->notes[0])->toContain('They opened a second site last month.')
        ->and($connector->notes[0])->toContain('/leads/'.$lead->getKey());

    $link = IntegrationLink::query()->withoutGlobalScopes()->firstOrFail();

    expect($link->local_type)->toBe(SyncTarget::Lead)
        ->and((int) $link->local_id)->toBe((int) $lead->getKey())
        ->and($link->external_type)->toBe('organization')
        ->and($link->external_id)->toBe('org-'.$lead->getKey())
        ->and((int) $link->team_id)->toBe((int) $team->getKey());

    expect(logsFor($integration))->toHaveCount(1)
        ->and(logsFor($integration)[0]->outcome)->toBe(SyncOutcome::Created)
        ->and($integration->fresh()?->last_synced_at)->not->toBeNull();
});

it('skips a second push when nothing a CRM can see has changed', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    sync($integration, $lead);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Skipped)
        // The whole point of `synced_hash`: without it an auto-sync rule firing on every re-score
        // would re-push every organisation a team owns, nightly.
        ->and($connector->companies)->toHaveCount(1);

    // A private note moving `updated_at` is not a reason to push.
    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['readiness' => 70])->save());

    expect(sync($integration, $lead->fresh()))->toBe(SyncOutcome::Skipped);

    // A name change is.
    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['company_name' => 'Bright Bean Ltd'])->save());

    expect(sync($integration, $lead->fresh()))->toBe(SyncOutcome::Updated)
        ->and($connector->companies)->toHaveCount(2);
});

// --- Suppression ----------------------------------------------------------------------------

it('refuses a company on the do-not-contact list, and says so in the log', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    app(SuppressionList::class)->suppress(
        SuppressionType::Domain,
        'brightbean.test',
        SuppressionScope::Team,
        SuppressionReason::Manual,
        (int) $team->getKey(),
    );

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'website' => 'brightbean.test']);

    // A CRM is a place a record goes in order to be contacted from, and once it is in somebody's
    // Pipedrive no later opt-out can reach it — ADR 0033's argument for the export, same answer.
    expect(sync($integration, $lead))->toBe(SyncOutcome::Refused)
        ->and($connector->companies)->toBeEmpty()
        // A logged row rather than a silence: "why is this company not in my CRM" needs an answer.
        ->and(logsFor($integration)[0]->outcome)->toBe(SyncOutcome::Refused)
        ->and(logsFor($integration)[0]->error)->toContain('do-not-contact');

    // And nothing was charged for a refusal.
    expect(app(UsageAllowance::class)->used($team, BillingMetric::CrmSync))->toBe(0);
});

it('pushes a company with no website rather than refusing one it cannot ask about', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'No Site Ltd', 'website' => null]);

    // Refusing would mean a company nobody has found a site for can never be synced, which is a
    // great many perfectly contactable small businesses.
    expect(sync($integration, $lead))->toBe(SyncOutcome::Created)
        ->and($connector->companies)->toHaveCount(1);
});

// --- Contacts -------------------------------------------------------------------------------

it('never hands a connector a value nobody has paid to reveal', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'website' => 'brightbean.test']);

    app(TeamContext::class)->run($team, function () use ($team, $lead): void {
        // Free-tier: read off the company's own page, so nothing to reveal (ADR 0029).
        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Hello Desk',
            'email' => 'hello@brightbean.test',
            'email_source' => null,
            'is_role_based' => true,
        ]);

        // A vendor was paid and nobody has spent to see it. A masked address in a CRM is worse
        // than a blank, because something there will eventually try to send to it.
        LeadContact::factory()->create([
            'team_id' => $team->getKey(),
            'lead_id' => $lead->getKey(),
            'name' => 'Dana Whitlow',
            'email' => 'dana@brightbean.test',
            'email_source' => 'fake-vendor',
            'revealed_email_at' => null,
            'phone' => null,
        ]);
    });

    sync($integration, $lead);

    // Only the free-tier contact travelled: the paid-but-unrevealed one has nothing sendable, so
    // it is skipped entirely rather than pushed as a name with no way to reach them.
    expect($connector->people)->toHaveCount(1)
        ->and($connector->people[0]['email'])->toBe('hello@brightbean.test');

    foreach ($connector->people as $person) {
        expect($person['email'])->not->toContain('***');
    }
});

// --- Metering -------------------------------------------------------------------------------

it('spends one record on a push and gives the hold back on a skip', function (): void {
    [, $team] = crmTeam();
    [$integration] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    sync($integration, $lead);

    $allowance = app(UsageAllowance::class);

    expect($allowance->used($team, BillingMetric::CrmSync))->toBe(1)
        ->and($allowance->held($team, BillingMetric::CrmSync))->toBe(0);

    sync($integration, $lead);

    // Charging for the `synced_hash` optimisation would make being careful cost a team money.
    expect($allowance->used($team, BillingMetric::CrmSync))->toBe(1);
});

it('refuses before calling the CRM when the allowance is spent', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    BillingFixtures::capAllowance(BillingMetric::CrmSync, 1);

    sync($integration, Fixtures::lead($team, ['company_name' => 'One Ltd', 'dedupe_key' => 'no:one']));

    $second = Fixtures::lead($team, ['company_name' => 'Two Ltd', 'dedupe_key' => 'no:two']);

    expect(sync($integration, $second))->toBe(SyncOutcome::Refused)
        ->and($connector->companies)->toHaveCount(1)
        ->and(logsFor($integration)[1]->error)->toContain('allowance');
});

it('gives the hold back when the push throws', function (): void {
    [, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    // An unauthorized that the refresh cannot fix: the first attempt refreshes, the refresh fails,
    // and the whole thing gives up.
    $connector->expireOnce = true;
    $connector->refreshFails = true;

    [$integration] = connectCrm($team, $connector);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Failed);

    $allowance = app(UsageAllowance::class);

    // A hold nobody settles sits against the allowance until the month turns over, and the retry
    // holds again — a handful of failures would lock a team out for weeks (ADR 0033 §10's lesson).
    expect($allowance->held($team, BillingMetric::CrmSync))->toBe(0)
        ->and($allowance->used($team, BillingMetric::CrmSync))->toBe(0);
});

// --- Tokens ---------------------------------------------------------------------------------

it('refreshes a stale token before calling, and once more after a refusal', function (): void {
    [, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    $connector->expireOnce = true;

    [$integration] = connectCrm($team, $connector);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Created)
        // Once, and only once: the second refusal after a successful refresh means something other
        // than a stale token, and retrying it again is a loop against their rate limit.
        ->and($connector->refreshes)->toBe(1)
        ->and($integration->fresh()?->access_token)->toBe('access-fresh')
        // A provider that rotates only the access token must not lose its refresh token.
        ->and($integration->fresh()?->refresh_token)->toBe('refresh-fresh');
});

it('marks needs_reauth and tells the admins when the refresh itself fails', function (): void {
    [$user, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    $connector->refreshFails = true;

    [$integration] = connectCrm($team, $connector);

    // Stale, so the refresh is attempted before anything else.
    $integration->forceFill(['expires_at' => now()->subMinute()])->save();

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Failed)
        ->and($integration->fresh()?->status)->toBe(IntegrationStatus::NeedsReauth)
        // The token is dropped: a dead connection holding a live credential is a credential we
        // have been asked to stop having.
        ->and($integration->fresh()?->access_token)->toBeNull()
        // The exception class, never a provider's 401 body — which has been known to echo the
        // token back, and this column is rendered on a settings screen.
        ->and($integration->fresh()?->last_error)->toBe(RuntimeException::class);

    $notification = Notification::query()->withoutGlobalScopes()
        ->where('notifiable_id', $user->getKey())
        ->first();

    expect($notification?->data['type'])->toBe(NotificationType::IntegrationNeedsReauth->value);
});

it('will not push at all while a connection needs reconnecting', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    $integration->forceFill(['status' => IntegrationStatus::NeedsReauth])->save();

    expect(sync($integration, Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee'])))
        ->toBe(SyncOutcome::Refused)
        ->and($connector->companies)->toBeEmpty();
});

// --- The optional half ----------------------------------------------------------------------

it('keeps the company when the deal fails', function (): void {
    [, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    $connector->dealFails = true;

    [$integration] = connectCrm($team, $connector, ['create_deals' => true]);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    // Losing the organisation because the pipeline id in the team's settings was stale is the
    // wrong trade.
    expect(sync($integration, $lead))->toBe(SyncOutcome::Created)
        ->and($connector->companies)->toHaveCount(1)
        ->and($connector->deals)->toBe(0);
});

// --- Auto-sync ------------------------------------------------------------------------------

it('pushes a newly scored lead only when it clears both thresholds', function (): void {
    Queue::fake();

    [, $team] = crmTeam();
    [$integration] = connectCrm($team, settings: [
        'auto_sync' => ['enabled' => true, 'min_fit' => 80, 'min_confidence' => 60],
    ]);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['confidence' => 75])->save());

    // The listener is called rather than the event fired: it is queued, so `Queue::fake()` would
    // hold the listener itself and the job under test would never be reached.
    $listener = app(AutoSyncScoredLead::class);

    // Fit below the floor.
    $listener->handle(new LeadScored((int) $team->getKey(), (int) $lead->getKey(), 1, ScoreGrade::C->value, 50));
    Queue::assertNotPushed(PushLeadToCrmJob::class);

    // Both cleared.
    $listener->handle(new LeadScored((int) $team->getKey(), (int) $lead->getKey(), 1, ScoreGrade::A->value, 90));
    Queue::assertPushed(PushLeadToCrmJob::class, 1);
});

it('never pushes unattended while auto-sync is off, which is the default', function (): void {
    Queue::fake();

    [, $team] = crmTeam();
    [$integration] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['confidence' => 99])->save());

    // Switching it on means Alima starts writing into somebody's CRM with no person present, and
    // nobody should discover that as a default.
    expect($integration->autoSync()['enabled'])->toBeFalse();

    app(AutoSyncScoredLead::class)
        ->handle(new LeadScored((int) $team->getKey(), (int) $lead->getKey(), 1, ScoreGrade::A->value, 95));

    Queue::assertNotPushed(PushLeadToCrmJob::class);
});

it('treats an unjudged confidence as failing a floor, not passing it', function (): void {
    Queue::fake();

    [, $team] = crmTeam();
    [$integration] = connectCrm($team, settings: [
        'auto_sync' => ['enabled' => true, 'min_confidence' => 50],
    ]);

    // Nulled **after** creation on purpose: `LeadProvenanceObserver` records a provenance item on
    // every new lead and the recalculation that follows gives it a confidence, so a factory
    // attribute would be overwritten — and whether it landed above or below the floor depended on
    // the factory's random source, which is how this test was flaky before it was pinned.
    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    app(TeamContext::class)->run($team, fn () => $lead->forceFill(['confidence' => null])->save());

    expect($lead->fresh()?->confidence)->toBeNull();

    // Null until PL1's evidence has been weighed, and null is not zero (ADR 0028). Failing toward
    // not pushing is the safe direction: a record in somebody else's CRM cannot be undone.

    app(AutoSyncScoredLead::class)
        ->handle(new LeadScored((int) $team->getKey(), (int) $lead->getKey(), 1, ScoreGrade::A->value, 95));

    Queue::assertNotPushed(PushLeadToCrmJob::class);
});

// --- Tenancy --------------------------------------------------------------------------------

it('refuses to push one team\'s lead through another team\'s connection', function (): void {
    [, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    [$otherTeam, $foreignLead] = Fixtures::foreignLead();

    // The job reads both without the tenancy scope — a worker has no team in context — so this is
    // the one thing it has to prove for itself.
    app(PushLeadToCrmJob::class, [
        'integrationId' => (int) $integration->getKey(),
        'leadId' => (int) $foreignLead->getKey(),
    ])->handle(app(SyncLeadToCrmAction::class));

    expect($connector->companies)->toBeEmpty()
        ->and(IntegrationLink::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(logsFor($integration))->toBeEmpty();
});

it('refuses an ordinary member the configuration routes but lets them push one lead', function (): void {
    [, $team] = crmTeam();
    [$integration] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    $member = Fixtures::member($team, TeamRole::Member);

    Queue::fake();

    // Pushing one company somebody is looking at is working a lead, not configuring an
    // integration — and everything that makes it safe is in the Action, whoever asked.
    $this->actingAs($member)
        ->post(route('integrations.crm.sync', ['integration' => $integration->getKey(), 'lead' => $lead->getKey()]))
        ->assertRedirect();

    Queue::assertPushed(PushLeadToCrmJob::class);

    $this->actingAs($member)
        ->delete(route('integrations.crm.destroy', ['integration' => $integration->getKey()]))
        ->assertForbidden();
});

// --- Connecting -----------------------------------------------------------------------------

it('keeps the links on a reconnect to the same account and clears them on a different one', function (): void {
    [$user, $team] = crmTeam();
    [$integration, $connector] = connectCrm($team);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);
    sync($integration, $lead);

    expect(IntegrationLink::query()->withoutGlobalScopes()->count())->toBe(1);

    $connect = app(ConnectIntegrationAction::class);

    // Same account: a team that disconnects on Friday and reconnects on Monday should update the
    // organisations they already have rather than create every one again.
    $connect->handle($team, $user, CrmProvider::Pipedrive, 'code-1');

    expect(IntegrationLink::query()->withoutGlobalScopes()->count())->toBe(1);

    // A different account: the links point at records inside a company that has never heard of us.
    $connector->accountId = 'acct-2';
    $connect->handle($team, $user, CrmProvider::Pipedrive, 'code-2');

    expect(IntegrationLink::query()->withoutGlobalScopes()->count())->toBe(0)
        ->and(Integration::query()->withoutGlobalScopes()->count())->toBe(1)
        ->and(AuditLog::query()->withoutGlobalScopes()->where('action', 'integration.connected')->count())->toBe(2);
});

it('clears the credentials on disconnect and keeps the links', function (): void {
    [$user, $team] = crmTeam();
    [$integration] = connectCrm($team);

    sync($integration, Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']));

    app(DisconnectIntegrationAction::class)->handle($integration, $user);

    expect($integration->fresh()?->status)->toBe(IntegrationStatus::Disconnected)
        ->and($integration->fresh()?->access_token)->toBeNull()
        ->and($integration->fresh()?->refresh_token)->toBeNull()
        // The links survive, which is the whole reason the row is not deleted.
        ->and(IntegrationLink::query()->withoutGlobalScopes()->count())->toBe(1);
});

// --- The seventh seam -----------------------------------------------------------------------

it('has a permissive default so the module runs with no plan at all', function (): void {
    $team = Team::factory()->create();

    // Tested as the object rather than through the container: the binding that replaces it lives
    // inside `billing.enforce_plans`, which is read when the provider registers — so flipping the
    // config inside a booted test would assert nothing. What matters is the default's own
    // behaviour, and that the empty-string handle is the "nothing booked" sentinel, so a caller
    // settles unconditionally rather than branching (PL6's argument, seventh seam).
    $allowance = new UnmeteredCrmRows;

    expect($allowance)->toBeInstanceOf(CrmRowAllowance::class)
        ->and($allowance->remaining($team))->toBeNull()
        ->and($allowance->reserve($team, 500))->toBe('');

    $allowance->commit('', 500);
    $allowance->release('');
});

// --- What the review found -------------------------------------------------------------------

it('does not call a half-finished push up to date', function (): void {
    [, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    $connector->noteFails = true;

    [$integration] = connectCrm($team, $connector);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    expect(sync($integration, $lead))->toBe(SyncOutcome::Failed);

    // The link exists, so a retry **updates** the organisation rather than creating a second one in
    // somebody's CRM — but its hash is empty, so the retry is a real attempt. Writing the
    // fingerprint before the note left the company in the CRM with no note and no contacts, and
    // every later push answering `Skipped`, silently, for ever.
    $link = IntegrationLink::query()->withoutGlobalScopes()->firstOrFail();

    expect($link->external_id)->toBe('org-'.$lead->getKey())
        ->and($link->synced_hash)->toBe('');

    $connector->noteFails = false;

    expect(sync($integration, $lead))->not->toBe(SyncOutcome::Skipped)
        ->and($connector->notes)->toHaveCount(1)
        ->and(IntegrationLink::query()->withoutGlobalScopes()->firstOrFail()->synced_hash)->not->toBe('');
});

it('keeps a provider\'s own words out of the settings screen', function (): void {
    [, $team] = crmTeam();
    $connector = new FakeCrmConnector;
    $connector->noteFails = true;

    [$integration] = connectCrm($team, $connector);

    sync($integration, Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']));

    // The exception class, never its message. `IntegrationResource` sends this column to every
    // member of the team, and a provider's error body has been known to echo the token back.
    expect($integration->fresh()?->last_error)->toBe(RuntimeException::class)
        ->and($integration->fresh()?->last_error)->not->toContain('note endpoint');
});

it('lets only an owner or admin start and finish a connection', function (): void {
    [, $team] = crmTeam();
    connectCrm($team);

    $member = Fixtures::member($team, TeamRole::Member);

    // Connecting replaces the team's CRM, can repoint auto-sync at an account of the member's own,
    // and can trigger the account-changed link wipe. `viewAny` only asked whether they had a team.
    $this->actingAs($member)
        ->post(route('integrations.crm.connect', ['provider' => 'pipedrive']))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('integrations.crm.callback', ['provider' => 'pipedrive', 'code' => 'c', 'state' => 's']))
        ->assertForbidden();
});

it('audits turning auto-sync on', function (): void {
    [$user, $team] = crmTeam();
    [$integration] = connectCrm($team);

    $this->actingAs($user)
        ->put(route('integrations.crm.update', ['integration' => $integration->getKey()]), [
            'auto_sync' => true,
            'min_confidence' => 60,
            'min_fit' => 80,
            'create_deals' => false,
        ])
        ->assertRedirect();

    $entry = AuditLog::query()->withoutGlobalScopes()
        ->where('action', 'integration.settings_updated')
        ->firstOrFail();

    // The one change in this module that authorises unattended writes into somebody's CRM, so the
    // thresholds are in the trail as well as the switch.
    expect($entry->properties['auto_sync'] ?? null)->toBeTrue()
        ->and($entry->properties['min_fit'] ?? null)->toBe(80)
        ->and($integration->fresh()?->autoSync()['enabled'])->toBeTrue();
});

it('keeps one team out of another team\'s connection and leads', function (): void {
    [$user, $team] = crmTeam();
    [$integration] = connectCrm($team);

    [$otherTeam, $foreignLead] = Fixtures::foreignLead();
    Feature::for($otherTeam)->activate(PrelaunchFeature::Webhooks->value);

    Queue::fake();

    // Their own integration, somebody else's lead. The route binding reads a lead without the
    // tenancy scope, so the controller checks the team explicitly.
    $this->actingAs($user)
        ->post(route('integrations.crm.sync', [
            'integration' => $integration->getKey(),
            'lead' => $foreignLead->getKey(),
        ]))
        ->assertNotFound();

    Queue::assertNothingPushed();
});
