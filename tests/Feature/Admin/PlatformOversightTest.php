<?php

declare(strict_types=1);

use App\Modules\Admin\Enums\AdminRole;
use App\Modules\Admin\Enums\AuditCategory;
use App\Modules\Admin\Resources\ResourceRegistry;
use App\Modules\Admin\Services\AdminAudit;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Outreach\Enums\OutreachBlockReason;
use App\Modules\Outreach\Enums\OutreachChannel;
use App\Modules\Outreach\Enums\SuppressionReason;
use App\Modules\Outreach\Enums\SuppressionScope;
use App\Modules\Outreach\Enums\SuppressionType;
use App\Modules\Outreach\Models\OutreachSuppression;
use App\Modules\Outreach\Services\OutreachGuard;
use App\Modules\Outreach\Services\SuppressionList;
use App\Modules\Signals\Models\SignalRun;
use App\Modules\Signals\Models\SignalWatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\AdminFixtures as Fixtures;

uses(RefreshDatabase::class);

/*
 * The operator views three earlier phases deferred to Phase 19c, and the platform suppression.
 *
 * The single most important property here is a **negative** one: three of these views are read-only
 * and the protection is the absence of a route, not a permission somebody could grant. So it is
 * asserted over the whole route table rather than screen by screen — the same reasoning
 * `AdminRouteContractTest` is built on.
 */

/**
 * @return list<string>
 */
function routesUnder(string $prefix): array
{
    return collect(Route::getRoutes()->getRoutes())
        ->filter(fn (RoutingRoute $route): bool => Str::startsWith($route->uri(), $prefix))
        ->filter(fn (RoutingRoute $route): bool => $route->methods() !== ['HEAD'])
        ->map(fn (RoutingRoute $route): string => implode('|', $route->methods()).' '.$route->uri())
        ->values()
        ->all();
}

it('exposes the three deferred views as reads and nothing else', function (): void {
    // `signal_runs`: a run spends real money at Serper and on the AI, and an operator must not
    // spend a tenant's allowance for them.
    expect(routesUnder('admin/signal-runs'))->toBe(['GET|HEAD admin/signal-runs']);

    // Compliance activity: an erasure, an export and a retention policy are the tenant's decisions
    // as data controller, not ours.
    expect(routesUnder('admin/compliance'))->toBe(['GET|HEAD admin/compliance']);

    // The audit trail: append-only, so there is nothing to write.
    expect(routesUnder('admin/audit'))->toBe(['GET|HEAD admin/audit']);
});

it('keeps the registry descriptors behind those views read-only too', function (): void {
    // The second lock. The bespoke screens have no write route, and the generic registry must not
    // offer one round the back — `/admin/resources/signal-runs` is a real path.
    $registry = app(ResourceRegistry::class);

    foreach (['signal-runs', 'retention-policies', 'lia-records', 'subscriptions', 'audit-logs'] as $key) {
        $resource = $registry->find($key);

        expect($resource->allowsCreate())->toBeFalse("{$key} permits create")
            ->and($resource->allowsUpdate())->toBeFalse("{$key} permits update")
            ->and($resource->allowsDelete())->toBeFalse("{$key} permits delete");
    }
});

it('shows signal runs across tenants with cost, failures and durations', function (): void {
    [, $teamA] = Fixtures::tenant('Alpha');
    [, $teamB] = Fixtures::tenant('Beta');

    foreach ([$teamA, $teamB] as $team) {
        app(TeamContext::class)->run($team, function () use ($team): void {
            $watch = SignalWatch::factory()->create(['team_id' => $team->getKey()]);

            SignalRun::factory()->create([
                'team_id' => $team->getKey(),
                'signal_watch_id' => $watch->getKey(),
                'cost_micros' => 4_000,
                // Five minutes ago, not "now". The window is half-open with `now` as its exclusive
                // upper bound (`DateRange`), and a row written in the same second as the request
                // falls outside it once both are truncated to seconds by the binding. Real data is
                // never that close to the read; a test is.
                'created_at' => now()->subMinutes(5),
                'started_at' => now()->subMinutes(5),
                'finished_at' => now()->subMinutes(5)->addSeconds(3),
            ]);
        });
    }

    $this->actingAs(Fixtures::operator(AdminRole::Support));

    $this->get('/admin/signal-runs')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/signals/Runs')
            // Both tenants, which is the whole point of the view.
            ->has('runs', 2)
            ->where('summary.cost_micros', 8_000)
            ->where('runs.0.duration_ms', fn (?int $ms): bool => $ms !== null && $ms >= 2_000));
});

it('shows compliance activity across tenants, read from the audit trail', function (): void {
    [, $team] = Fixtures::tenant('Careful Ltd');

    app(TeamContext::class)->run($team, function () use ($team): void {
        $entry = app(AuditLog::class)->newQuery()->create([
            'team_id' => $team->getKey(),
            'action' => 'compliance.retention_swept',
            'properties' => ['deleted' => 12, 'anonymised' => 3],
        ]);

        // See the note on the signal-run fixture: the window's upper bound is `now`, exclusive.
        $entry->forceFill(['created_at' => now()->subMinutes(5)])->save();
    });

    $this->actingAs(Fixtures::operator(AdminRole::Support));

    $this->get('/admin/compliance')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/compliance/Index')
            ->has('events', 1)
            ->where('events.0.action', 'compliance.retention_swept')
            // Nobody behind a scheduled sweep, which the screen shows as "system" rather than
            // leaving blank.
            ->where('events.0.actor', null)
            ->where('events.0.properties.deleted', 12));
});

/*
 * The cross-tenant audit viewer.
 */

it('finds an impersonation session without knowing the action string', function (): void {
    [, $team] = Fixtures::tenant('Target Ltd');
    $operator = Fixtures::operator(AdminRole::Support);

    // The entry is written directly rather than by impersonating for real: starting a session
    // makes `Auth::user()` the **customer**, and the panel then 404s for the rest of the request —
    // which is correct, and is `ImpersonationTest`'s subject. What is under test here is that the
    // viewer surfaces the entry **by category**, without anybody knowing the action string.
    app(TeamContext::class)->run($team, function () use ($team, $operator): void {
        app(AuditLog::class)->newQuery()->create([
            'team_id' => $team->getKey(),
            'user_id' => $operator->getKey(),
            'action' => 'admin.impersonation.started',
        ])->forceFill(['created_at' => now()->subMinutes(5)])->save();
    });

    // `audit_logs` holds every tenant's ordinary activity, and these are the four rows in ten
    // thousand that matter when reviewing operator behaviour.
    $this->actingAs($operator)
        ->get('/admin/audit?category='.AuditCategory::Impersonation->value)
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/audit/Index')
            ->has('entries', 1)
            ->where('entries.0.action', 'admin.impersonation.started')
            ->where('entries.0.category', AuditCategory::Impersonation->value));
});

it('separates an operator read from an operator write, though both begin "admin."', function (): void {
    expect(AuditCategory::of(AdminAudit::RESOURCE_VIEWED))->toBe(AuditCategory::AdminRead)
        ->and(AuditCategory::of(AdminAudit::RECORD_VIEWED))->toBe(AuditCategory::AdminRead)
        ->and(AuditCategory::of('admin.impersonation.started'))->toBe(AuditCategory::Impersonation)
        ->and(AuditCategory::of(AdminAudit::RECORD_UPDATED))->toBe(AuditCategory::AdminWrite)
        ->and(AuditCategory::of('compliance.retention_swept'))->toBe(AuditCategory::Compliance)
        // An action nobody has classified is shown as uncategorised rather than filed under
        // something that sounds close.
        ->and(AuditCategory::of('weather.changed'))->toBe(AuditCategory::Other);
});

it('narrows the audit viewer to one tenant', function (): void {
    [, $mine] = Fixtures::tenant('Mine');
    [, $theirs] = Fixtures::tenant('Theirs');

    foreach ([$mine, $theirs] as $team) {
        app(TeamContext::class)->run($team, function () use ($team): void {
            app(AuditLog::class)->newQuery()->create([
                'team_id' => $team->getKey(),
                'action' => 'leads.note_added',
            ])->forceFill(['created_at' => now()->subMinutes(5)])->save();
        });
    }

    // The action is pinned as well as the tenant, so the assertion is about the **tenant**
    // filter: creating a team writes its own `team.created` entry, and whether that lands inside
    // the window depends on which second the request runs in.
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->get('/admin/audit?action=leads.note_added&team_id='.$mine->getKey())
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('entries', 1)
            ->where('entries.0.team_id', (int) $mine->getKey()));
});

/*
 * Outreach oversight and the global do-not-contact list.
 */

it('blocks a send in every tenant once an address is suppressed platform-wide', function (): void {
    [, $teamA] = Fixtures::tenant('Alpha');
    [, $teamB] = Fixtures::tenant('Beta');

    $address = 'regulator@example.test';
    $list = app(SuppressionList::class);

    // Nobody is suppressed to begin with.
    expect($list->isEmailSuppressed($address, (int) $teamA->getKey()))->toBeFalse()
        ->and($list->isEmailSuppressed($address, (int) $teamB->getKey()))->toBeFalse();

    $operator = Fixtures::operator(AdminRole::Support);

    $this->actingAs($operator)
        ->post('/admin/outreach/suppressions', [
            'type' => SuppressionType::Email->value,
            'value' => $address,
            'reason' => SuppressionReason::Complaint->value,
            'note' => 'Complained to us directly, ticket 4812.',
        ])
        ->assertRedirect('/admin/outreach');

    // **Every** tenant, which is the property under test: the entry is `global`-scoped with a null
    // `team_id`, and `SuppressionList` includes the global scope in every send-time check.
    expect($list->isEmailSuppressed($address, (int) $teamA->getKey()))->toBeTrue()
        ->and($list->isEmailSuppressed($address, (int) $teamB->getKey()))->toBeTrue();

    $entry = OutreachSuppression::query()->sole();

    expect($entry->scope)->toBe(SuppressionScope::Global)
        ->and($entry->team_id)->toBeNull()
        // A hash and a mask, never the address: a register of people who want nothing to do with
        // us is not somewhere to keep their details in the clear.
        ->and($entry->value_preview)->toBe('r***@example.test')
        ->and($entry->getAttributes()['value_hash'])->not->toContain('@')
        // And it may never be lifted.
        ->and($entry->isRemovable())->toBeFalse();

    expect(AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::PLATFORM_SUPPRESSED)
        ->sole()
        ->properties['value_preview'] ?? null)->toBe('r***@example.test');
});

it('refuses a platform suppression whose value is not a usable identity', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::Support))
        ->post('/admin/outreach/suppressions', [
            'type' => SuppressionType::Email->value,
            'value' => 'not-an-address',
            'reason' => SuppressionReason::Manual->value,
            'note' => 'Typed wrong.',
        ])
        ->assertRedirect();

    // Nothing stored. `SuppressionList::suppress()` refuses to store a hash of nonsense, which is
    // right and silent — an entry that matched nothing would read, for ever, as a promise kept.
    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('offers only the reasons that mean something at platform scope', function (): void {
    foreach ([SuppressionReason::Bounce, SuppressionReason::Erasure] as $reason) {
        // A bounce is one tenant's technical fact; an erasure belongs to the tenant that received
        // the request. Recording either platform-wide would record something that is not true.
        $this->actingAs(Fixtures::operator(AdminRole::Support))
            ->post('/admin/outreach/suppressions', [
                'type' => SuppressionType::Email->value,
                'value' => 'someone@example.test',
                'reason' => $reason->value,
                'note' => 'Should not be allowed.',
            ])
            ->assertSessionHasErrors('reason');
    }

    expect(OutreachSuppression::query()->count())->toBe(0);
});

it('offers no route to lift a global suppression', function (): void {
    // The missing route *is* the decision. Removing a global entry would overrule a person's stated
    // wish with a button.
    expect(routesUnder('admin/outreach'))->toBe([
        'GET|HEAD admin/outreach',
        'POST admin/outreach/suppressions',
    ]);
});

it('shows the guard refusing a suppressed prospect, not merely the list', function (): void {
    // The end of the chain, and the thing that makes the global list more than a screen:
    // `OutreachGuard` is still the only thing that answers whether a prospect may be approached,
    // and it refuses this one because of a row the panel wrote.
    [, $team] = Fixtures::tenant('Alpha');
    $lead = Fixtures::lead($team, 'Regulated Ltd');

    $contact = app(TeamContext::class)->run($team, fn (): LeadContact => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'email' => 'contact@regulated.test',
    ]));

    $allowed = app(TeamContext::class)->run(
        $team,
        fn () => app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact),
    );

    expect($allowed->allowed)->toBeTrue();

    app(SuppressionList::class)->suppress(
        type: SuppressionType::Email,
        value: 'contact@regulated.test',
        scope: SuppressionScope::Global,
        reason: SuppressionReason::OptOut,
        teamId: null,
    );

    $refused = app(TeamContext::class)->run(
        $team,
        fn () => app(OutreachGuard::class)->decide($team, $lead, OutreachChannel::Email, $contact),
    );

    expect($refused->allowed)->toBeFalse()
        ->and($refused->reasons)->toContain(OutreachBlockReason::Suppressed);
});

it('renders the outreach oversight screen with the global list and the form', function (): void {
    $this->actingAs(Fixtures::operator(AdminRole::Support));

    $this->get('/admin/outreach')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('admin/outreach/Index')
            ->has('deliverability.channels')
            ->has('suppressions')
            ->where('summary.global', 0)
            // Three of the five reasons, because only three mean anything at platform scope.
            ->has('form.reasons', 3));
});

it('audits a cross-tenant read of the signal runs and of compliance activity', function (): void {
    // Both screens show somebody else's records — a tenant's watch names, a tenant's assessments —
    // so they leave a trail, exactly as the resource index does. The **general** audit viewer
    // deliberately does not: auditing a read of the trail writes to the trail.
    $operator = Fixtures::operator(AdminRole::Support);

    $this->actingAs($operator)->get('/admin/signal-runs')->assertOk();
    $this->actingAs($operator)->get('/admin/compliance')->assertOk();
    $this->actingAs($operator)->get('/admin/audit')->assertOk();

    $resources = AuditLog::query()
        ->withoutGlobalScopes()
        ->where('action', AdminAudit::RESOURCE_VIEWED)
        ->pluck('properties')
        ->map(fn (array $properties): string => (string) ($properties['resource'] ?? ''))
        ->all();

    expect($resources)->toContain('signal-runs')
        ->and($resources)->toContain('compliance-activity')
        ->and($resources)->not->toContain('audit');
});
