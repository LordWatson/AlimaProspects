<?php

declare(strict_types=1);

use App\Modules\Core\Enums\PrelaunchFeature;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Enrichment\Events\LeadEnriched;
use App\Modules\Enrichment\Models\LeadContact;
use App\Modules\Integrations\Enums\DeliveryState;
use App\Modules\Integrations\Enums\WebhookEvent;
use App\Modules\Integrations\Jobs\DeliverWebhookJob;
use App\Modules\Integrations\Models\WebhookDelivery;
use App\Modules\Integrations\Models\WebhookEndpoint;
use App\Modules\Integrations\Services\WebhookDispatcher;
use App\Modules\Integrations\Services\WebhookFailureCounter;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Models\Notification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Pennant\Feature;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * PL7's outbound webhooks: the signature, the SSRF guard, the retries, the auto-disable, the log.
 *
 * **Endpoints are IP-literal URLs here on purpose.** `SafeUrl::approvedIps()` resolves the host,
 * and the sender refuses an address it cannot pin — so a test using `hooks.example.com` would pass
 * or fail depending on whether the machine running it has DNS. An IP literal resolves to itself,
 * which keeps the suite honest about the guard and independent of the network. The two tests that
 * are *about* the guard use addresses it must refuse.
 *
 * The failure paths call the job directly rather than letting the queue run it. The job throws on a
 * non-2xx on purpose — that is how the queue's own backoff is reached — and the sync driver used in
 * tests re-throws into the caller, which would say nothing about what the row ended up holding.
 */
const PUBLIC_HOOK = 'https://93.184.216.34/hooks/catch';

function webhookTeam(): array
{
    [$user, $team] = Fixtures::team();
    Feature::for($team)->activate(PrelaunchFeature::Webhooks->value);

    return [$user, $team];
}

function endpointFor(Team $team, array $events = ['lead.created'], array $attributes = []): WebhookEndpoint
{
    $endpoint = new WebhookEndpoint([
        'team_id' => $team->getKey(),
        'url' => PUBLIC_HOOK,
        'events' => $events,
        'is_active' => true,
    ]);

    $endpoint->forceFill([...WebhookEndpoint::mint(), ...$attributes])->save();

    return $endpoint;
}

// --- The flag --------------------------------------------------------------------------------

it('hides the integrations screen entirely while the flag is off', function (): void {
    [$user] = Fixtures::team();

    // 404 rather than 403: the flag asks whether the code is finished, not whether this member may
    // configure an integration.
    $this->actingAs($user)->get(route('integrations.index'))->assertNotFound();
    $this->actingAs($user)->post(route('integrations.webhooks.store'), [
        'url' => PUBLIC_HOOK,
        'events' => ['lead.created'],
    ])->assertNotFound();
});

it('queues nothing for a team whose flag is off, even with an endpoint on the row', function (): void {
    [, $team] = Fixtures::team();
    endpointFor($team);

    app(WebhookDispatcher::class)->dispatch($team, WebhookEvent::LeadCreated, ['id' => 1]);

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(0);
});

// --- The screen ------------------------------------------------------------------------------

it('shows an owner the endpoints, the catalogue and the providers', function (): void {
    [$user, $team] = webhookTeam();
    endpointFor($team);

    $this->actingAs($user)->get(route('integrations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('canManage', true)
            ->has('endpoints', 1)
            ->has('eventCatalogue', count(WebhookEvent::cases()))
            // Declared but unwired until PL8 registers a connector, and the card says so rather
            // than offering a Connect button that 404s.
            ->where('providers.0.available', false));
});

it('withholds the endpoint list from an ordinary member', function (): void {
    [, $team] = webhookTeam();
    endpointFor($team);

    $member = Fixtures::member($team, TeamRole::Member);

    // Not merely read-only: knowing which third party the team's data flows to is itself the
    // disclosure (`WebhookEndpointPolicy`).
    $this->actingAs($member)->get(route('integrations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('canManage', false)->has('endpoints', 0));
});

// --- Creating one ----------------------------------------------------------------------------

it('mints a secret, shows it once and stores it encrypted', function (): void {
    [$user, $team] = webhookTeam();

    $this->actingAs($user)
        ->post(route('integrations.webhooks.store'), [
            'url' => PUBLIC_HOOK,
            'events' => ['lead.created', 'contact.enriched'],
        ])
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('newWebhookSecret');

    $endpoint = WebhookEndpoint::query()->withoutGlobalScopes()->firstOrFail();
    $secret = session('newWebhookSecret');

    expect($secret)->toBeString()
        ->and(mb_strlen((string) $secret))->toBe(WebhookEndpoint::SECRET_BYTES * 2)
        // The hint is the *tail*: a leading prefix is the predictable part of a format.
        ->and($endpoint->hint)->toBe(mb_substr((string) $secret, -8))
        // Readable through the cast, ciphertext in the column — a verifier has to recompute an
        // HMAC, so a hash was never an option (see the model).
        ->and($endpoint->secret)->toBe($secret);

    $raw = (string) DB::table('webhook_endpoints')->where('id', $endpoint->getKey())->value('secret');

    expect($raw)->not->toBe($secret);
});

it('refuses an address that is not on the public internet', function (): void {
    [$user] = webhookTeam();

    foreach (['https://127.0.0.1/hook', 'https://10.0.0.5/hook', 'https://169.254.169.254/latest'] as $url) {
        $this->actingAs($user)
            ->post(route('integrations.webhooks.store'), ['url' => $url, 'events' => ['lead.created']])
            ->assertSessionHasErrors('url');
    }

    expect(WebhookEndpoint::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('refuses plain http, although SafeUrl itself allows it', function (): void {
    [$user] = webhookTeam();

    // Stricter than the shared guard on purpose: a body carrying revealed contact details and a
    // signature proving it came from us must not travel in the clear.
    $this->actingAs($user)
        ->post(route('integrations.webhooks.store'), [
            'url' => 'http://93.184.216.34/hook',
            'events' => ['lead.created'],
        ])
        ->assertSessionHasErrors('url');
});

it('refuses an endpoint subscribed to nothing', function (): void {
    [$user] = webhookTeam();

    $this->actingAs($user)
        ->post(route('integrations.webhooks.store'), ['url' => PUBLIC_HOOK, 'events' => []])
        ->assertSessionHasErrors('events');
});

// --- Fan-out ---------------------------------------------------------------------------------

it('queues one delivery per subscribed endpoint and none for the others', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [, $team] = webhookTeam();

    endpointFor($team, ['lead.created']);
    endpointFor($team, ['lead.created', 'signal.detected']);
    endpointFor($team, ['contact.enriched']);
    endpointFor($team, ['lead.created'], ['is_active' => false]);
    endpointFor($team, ['lead.created'], ['disabled_at' => now()]);

    $queued = app(WebhookDispatcher::class)->dispatch($team, WebhookEvent::LeadCreated, ['id' => 7]);

    // Two: the paused one and the auto-disabled one are both out, by different columns.
    expect($queued)->toBe(2)
        ->and(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(2);
});

it('does not reach another team even with an identical subscription', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [, $team] = webhookTeam();
    $other = Team::factory()->create();

    $mine = endpointFor($team, ['lead.created']);
    endpointFor($other, ['lead.created']);

    app(WebhookDispatcher::class)->dispatch($team, WebhookEvent::LeadCreated, ['id' => 7]);

    $deliveries = WebhookDelivery::query()->withoutGlobalScopes()->get();

    expect($deliveries)->toHaveCount(1)
        ->and((int) $deliveries->first()?->webhook_endpoint_id)->toBe((int) $mine->getKey())
        ->and((int) $deliveries->first()?->team_id)->toBe((int) $team->getKey());
});

// --- The signature ---------------------------------------------------------------------------

it('signs the exact bytes it sends, with the timestamp inside the signature', function (): void {
    Http::fake(['*' => Http::response('thanks', 200)]);

    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $delivery = app(WebhookDispatcher::class)
        ->dispatchTo($endpoint, WebhookEvent::LeadCreated, ['company_name' => 'Bright Bean Coffee']);

    $sent = null;

    Http::assertSent(function (Request $request) use (&$sent): bool {
        $sent = $request;

        return true;
    });

    $header = (string) $sent?->header('X-Alima-Signature')[0];

    expect($header)->toMatch('/^t=\d+,v1=[0-9a-f]{64}$/');

    [$t, $v1] = explode(',', $header);
    $timestamp = substr($t, 2);
    $signature = substr($v1, 3);
    $body = (string) $sent?->body();

    // The whole contract a customer implements: HMAC over `timestamp.body`, constant-time compare.
    expect($endpoint->fresh()?->verify($timestamp, $body, $signature))->toBeTrue()
        // The timestamp is in scope, so a captured request cannot be refreshed by editing it.
        ->and($endpoint->fresh()?->verify((string) ((int) $timestamp + 1), $body, $signature))->toBeFalse()
        // And so is the body.
        ->and($endpoint->fresh()?->verify($timestamp, $body.' ', $signature))->toBeFalse();

    $envelope = json_decode($body, true);

    expect($envelope)->toHaveKeys(['id', 'event', 'created_at', 'data'])
        ->and($envelope['event'])->toBe('lead.created')
        ->and($envelope['id'])->toBe((string) $delivery->getKey())
        ->and($envelope['data']['company_name'])->toBe('Bright Bean Coffee')
        // No team id: an endpoint belongs to one team, so the field would carry nothing the
        // receiver does not know (ADR 0034).
        ->and($envelope)->not->toHaveKey('team_id');

    expect($delivery->fresh()?->state)->toBe(DeliveryState::Delivered)
        ->and($delivery->fresh()?->status_code)->toBe(200)
        ->and($endpoint->fresh()?->last_delivered_at)->not->toBeNull();
});

it('rotating the secret changes what a signature verifies against', function (): void {
    [$user, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);
    $before = $endpoint->secret;

    $this->actingAs($user)
        ->post(route('integrations.webhooks.rotate', ['endpoint' => $endpoint->getKey()]))
        ->assertRedirect(route('integrations.index'))
        ->assertSessionHas('newWebhookSecret');

    // Replaced, not added: two live secrets is a grace period nobody asked for and a second thing
    // to find when one leaks.
    expect($endpoint->fresh()?->secret)->not->toBe($before)
        ->and($endpoint->fresh()?->secret)->toBe(session('newWebhookSecret'));
});

// --- The SSRF guard at send time -------------------------------------------------------------

it('refuses at send time an endpoint that now points somewhere private', function (): void {
    Http::fake();

    [, $team] = webhookTeam();

    // Stored before the rule could refuse it — which is the case that matters, because DNS changes
    // after a form is submitted and that is the whole of rebinding.
    $endpoint = endpointFor($team, ['lead.created']);
    $endpoint->forceFill(['url' => 'https://169.254.169.254/latest/meta-data/'])->save();

    $delivery = WebhookDelivery::query()->create([
        'team_id' => $team->getKey(),
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event' => WebhookEvent::LeadCreated->value,
        'payload' => ['id' => 1],
        'state' => DeliveryState::Pending->value,
    ]);

    (new DeliverWebhookJob((int) $delivery->getKey()))->handle(app(WebhookFailureCounter::class));

    Http::assertNothingSent();

    expect($delivery->fresh()?->state)->toBe(DeliveryState::Failed)
        // Permanent, so it counts toward the auto-disable: an endpoint resolving to a private
        // address will never work again.
        ->and($endpoint->fresh()?->failure_count)->toBe(1);
});

// --- Failure, retry and auto-disable ---------------------------------------------------------

it('throws on a non-2xx so the queue retries, and records what came back', function (): void {
    Http::fake(['*' => Http::response('nope', 500)]);

    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $delivery = WebhookDelivery::query()->create([
        'team_id' => $team->getKey(),
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event' => WebhookEvent::LeadCreated->value,
        'payload' => ['id' => 1],
        'state' => DeliveryState::Pending->value,
    ]);

    $job = new DeliverWebhookJob((int) $delivery->getKey());

    expect(fn () => $job->handle(app(WebhookFailureCounter::class)))
        ->toThrow(RuntimeException::class);

    $delivery->refresh();

    expect($delivery->state)->toBe(DeliveryState::Pending)
        ->and($delivery->attempt)->toBe(1)
        ->and($delivery->status_code)->toBe(500)
        ->and($delivery->response_snippet)->toBe('nope')
        // Shown on the screen, read by nothing: the retry itself is the queue's backoff.
        ->and($delivery->next_retry_at)->not->toBeNull()
        // Not counted yet — the attempts are not spent.
        ->and($endpoint->fresh()?->failure_count)->toBe(0);

    $job->failed(new RuntimeException('spent'));

    expect($delivery->fresh()?->state)->toBe(DeliveryState::Failed)
        ->and($endpoint->fresh()?->failure_count)->toBe(1);
});

it('switches an endpoint off after the threshold and tells the admins', function (): void {
    config()->set('integrations.webhooks.max_consecutive_failures', 3);

    [$user, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created'], ['failure_count' => 2]);

    app(WebhookFailureCounter::class)->failed($endpoint);

    expect($endpoint->fresh()?->disabled_at)->not->toBeNull()
        // The member's own switch is left alone, so they can tell who turned it off.
        ->and($endpoint->fresh()?->is_active)->toBeTrue();

    $notification = Notification::query()->withoutGlobalScopes()
        ->where('notifiable_id', $user->getKey())
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification?->data['type'])->toBe(NotificationType::WebhookDisabled->value)
        ->and($notification?->data['params']['count'])->toBe(3);
});

it('forgets the failures as soon as one delivery lands', function (): void {
    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created'], ['failure_count' => 9]);

    app(WebhookFailureCounter::class)->succeeded($endpoint);

    // Consecutive, not cumulative — otherwise every endpoint behind a flaky proxy is eventually
    // switched off.
    expect($endpoint->fresh()?->failure_count)->toBe(0);
});

it('reactivating clears both the count and our own stamp', function (): void {
    [$user, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created'], ['failure_count' => 20, 'disabled_at' => now()]);

    $this->actingAs($user)->put(route('integrations.webhooks.update', ['endpoint' => $endpoint->getKey()]), [
        'url' => PUBLIC_HOOK,
        'events' => ['lead.created'],
        'is_active' => true,
    ])->assertRedirect();

    // Leaving nineteen failures on the row would have us disable it again on the first bad answer.
    expect($endpoint->fresh()?->failure_count)->toBe(0)
        ->and($endpoint->fresh()?->disabled_at)->toBeNull();
});

// --- Test and redeliver ----------------------------------------------------------------------

it('sends a test event carrying no lead data', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [$user, $team] = webhookTeam();
    // Subscribed to nothing this test uses: testing has to work before an event is chosen.
    $endpoint = endpointFor($team, ['lead.sent_to_crm']);

    $this->actingAs($user)
        ->post(route('integrations.webhooks.test', ['endpoint' => $endpoint->getKey()]))
        ->assertRedirect();

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();

    expect($delivery->payload)->toBe([
        'test' => true,
        'message' => 'This is a test event from Alima.',
    ]);
});

it('redelivers the body that was composed then, as a new row', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [$user, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $first = app(WebhookDispatcher::class)
        ->dispatchTo($endpoint, WebhookEvent::LeadCreated, ['company_name' => 'Bright Bean Coffee']);

    $this->actingAs($user)
        ->post(route('integrations.deliveries.redeliver', ['delivery' => $first->getKey()]))
        ->assertRedirect();

    $deliveries = WebhookDelivery::query()->withoutGlobalScopes()->orderBy('id')->get();

    // A new row, because the log must keep saying what the first attempt did — and the same
    // payload, because replaying a webhook means replaying what happened.
    expect($deliveries)->toHaveCount(2)
        ->and($deliveries[1]->payload)->toBe($first->payload)
        ->and($deliveries[1]->getKey())->not->toBe($first->getKey());
});

// --- Tenancy ---------------------------------------------------------------------------------

it('cannot touch another team\'s endpoint', function (): void {
    [$user] = webhookTeam();

    $other = Team::factory()->create();
    Feature::for($other)->activate(PrelaunchFeature::Webhooks->value);
    $foreign = endpointFor($other, ['lead.created']);

    // 404, not 403: the tenancy scope hides the row from the route binding, so the request cannot
    // even learn that the endpoint exists. The policy still checks the team as well as the role
    // (ADR 0006) — a global scope is a convenience, not the guarantee — which is what the
    // `sync` route's explicit `team_id` check and the member test below cover.
    $this->actingAs($user)
        ->post(route('integrations.webhooks.test', ['endpoint' => $foreign->getKey()]))
        ->assertNotFound();

    $this->actingAs($user)
        ->delete(route('integrations.webhooks.destroy', ['endpoint' => $foreign->getKey()]))
        ->assertNotFound();

    expect(WebhookEndpoint::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('refuses an ordinary member the configuration routes', function (): void {
    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);
    $member = Fixtures::member($team, TeamRole::Member);

    $this->actingAs($member)
        ->post(route('integrations.webhooks.store'), ['url' => PUBLIC_HOOK, 'events' => ['lead.created']])
        ->assertForbidden();

    $this->actingAs($member)
        ->post(route('integrations.webhooks.rotate', ['endpoint' => $endpoint->getKey()]))
        ->assertForbidden();
});

// --- Retention -------------------------------------------------------------------------------

it('prunes settled deliveries past the window and leaves pending ones alone', function (): void {
    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $make = function (DeliveryState $state, int $daysAgo) use ($team, $endpoint): WebhookDelivery {
        $delivery = WebhookDelivery::query()->create([
            'team_id' => $team->getKey(),
            'webhook_endpoint_id' => $endpoint->getKey(),
            'event' => WebhookEvent::LeadCreated->value,
            'payload' => ['id' => 1],
            'state' => $state->value,
        ]);

        $delivery->forceFill(['created_at' => now()->subDays($daysAgo)])->save();

        return $delivery;
    };

    $old = $make(DeliveryState::Delivered, 40);
    $alsoOld = $make(DeliveryState::Failed, 40);
    $recent = $make(DeliveryState::Delivered, 2);
    // Live state a worker may be about to claim: a twelve-hour backoff means a row can
    // legitimately sit pending most of a day, and age says nothing about it.
    $pending = $make(DeliveryState::Pending, 40);

    $this->artisan('integrations:prune-deliveries')->assertSuccessful();

    $left = WebhookDelivery::query()->withoutGlobalScopes()->pluck('id')->all();

    expect($left)->not->toContain($old->getKey())
        ->and($left)->not->toContain($alsoOld->getKey())
        ->and($left)->toContain($recent->getKey())
        ->and($left)->toContain($pending->getKey());
});

it('reports without deleting on a dry run', function (): void {
    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    WebhookDelivery::query()->create([
        'team_id' => $team->getKey(),
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event' => WebhookEvent::LeadCreated->value,
        'payload' => ['id' => 1],
        'state' => DeliveryState::Delivered->value,
    ])->forceFill(['created_at' => now()->subDays(90)])->save();

    $this->artisan('integrations:prune-deliveries --dry-run')->assertSuccessful();

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(1);
});

// --- What travels in the body ----------------------------------------------------------------

it('raises lead.created from the observer, with the app\'s own lead resource as the body', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [, $team] = webhookTeam();
    endpointFor($team, ['lead.created']);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'website' => 'brightbean.test']);

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()->firstOrFail();

    // An observer rather than a new domain event in Lead Sourcing: `Lead::observe()` takes more
    // than one, and the guarantee then survives the next creation path (ADR 0028's mechanism).
    expect($delivery->event)->toBe(WebhookEvent::LeadCreated)
        ->and($delivery->payload['id'])->toBe($lead->getKey())
        ->and($delivery->payload['company_name'])->toBe('Bright Bean Coffee')
        // Built by `LeadResource`, so a field added to the API arrives here without anybody
        // maintaining a second shape.
        ->and($delivery->payload)->toHaveKeys(['confidence', 'readiness', 'outreach_restriction']);
});

it('never puts an unrevealed contact value in a payload', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [, $team] = webhookTeam();
    endpointFor($team, ['contact.enriched']);

    $lead = Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee', 'website' => 'brightbean.test']);

    app(TeamContext::class)->run($team, fn () => LeadContact::factory()->create([
        'team_id' => $team->getKey(),
        'lead_id' => $lead->getKey(),
        'name' => 'Dana Whitlow',
        'email' => 'dana@brightbean.test',
        // A vendor was paid and nobody has spent to see it (ADR 0029).
        'email_source' => 'fake-vendor',
        'revealed_email_at' => null,
    ]));

    event(new LeadEnriched((int) $team->getKey(), (int) $lead->getKey()));

    $delivery = WebhookDelivery::query()->withoutGlobalScopes()
        ->where('event', WebhookEvent::ContactEnriched->value)
        ->latest('id')
        ->firstOrFail();

    $json = (string) json_encode($delivery->payload);

    // Masking is automatic rather than remembered, because the payload is built with the resource
    // that already does it — which is the whole reason the relay uses the app's own resources.
    expect($json)->not->toContain('dana@brightbean.test')
        ->and($delivery->payload['contacts'][0]['name'])->toBe('Dana Whitlow');
});

// --- What the review found -------------------------------------------------------------------

it('retries a host that did not resolve rather than giving up on it', function (): void {
    Http::fake();

    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);
    // `.invalid` never resolves, which is also what a DNS blip looks like from here.
    $endpoint->forceFill(['url' => 'https://nowhere.invalid/hook'])->save();

    $delivery = WebhookDelivery::query()->create([
        'team_id' => $team->getKey(),
        'webhook_endpoint_id' => $endpoint->getKey(),
        'event' => WebhookEvent::LeadCreated->value,
        'payload' => ['id' => 1],
        'state' => DeliveryState::Pending->value,
    ]);

    $job = new DeliverWebhookJob((int) $delivery->getKey());

    expect(fn () => $job->handle(app(WebhookFailureCounter::class)))->toThrow(RuntimeException::class);

    Http::assertNothingSent();

    // Still pending and unpunished: the guard's **refusals** are permanent, but "did not resolve"
    // is transient, and treating the two alike spent a delivery and a strike on somebody else's
    // nameserver having a bad minute.
    expect($delivery->fresh()?->state)->toBe(DeliveryState::Pending)
        ->and($endpoint->fresh()?->failure_count)->toBe(0);
});

it('counts concurrent failures without losing any', function (): void {
    config()->set('integrations.webhooks.max_consecutive_failures', 50);

    [, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $counter = app(WebhookFailureCounter::class);

    // **Separately loaded copies, all reading 0**, which is what five workers failing against one
    // endpoint look like. A read-modify-write turned that into five writes of 1 and the twenty that
    // switch an endpoint off would never have been reached; `increment()` is atomic, so it is five.
    $copies = collect(range(1, 5))->map(
        fn (): WebhookEndpoint => WebhookEndpoint::query()
            ->withoutGlobalScopes()
            ->findOrFail($endpoint->getKey()),
    );

    foreach ($copies as $copy) {
        $counter->failed($copy);
    }

    expect($endpoint->fresh()?->failure_count)->toBe(5);
});

it('audits removing an endpoint, by host and never by URL', function (): void {
    [$user, $team] = webhookTeam();
    $endpoint = endpointFor($team, ['lead.created']);

    $this->actingAs($user)
        ->delete(route('integrations.webhooks.destroy', ['endpoint' => $endpoint->getKey()]))
        ->assertRedirect();

    $entry = AuditLog::query()->withoutGlobalScopes()
        ->where('action', 'webhook_endpoint.deleted')
        ->firstOrFail();

    // "When did we stop receiving these" has no other answer once the row is gone — and the URL
    // itself is a credential, so the trail keeps the host.
    expect($entry->properties['host'] ?? null)->toBe('93.184.216.34')
        ->and($entry->properties['events'] ?? null)->toBe(['lead.created'])
        ->and(WebhookEndpoint::query()->withoutGlobalScopes()->count())->toBe(0);
});

it('cannot redeliver another team\'s delivery', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [$user] = webhookTeam();

    $other = Team::factory()->create();
    Feature::for($other)->activate(PrelaunchFeature::Webhooks->value);
    $foreign = endpointFor($other, ['lead.created']);

    $delivery = app(WebhookDispatcher::class)
        ->dispatchTo($foreign, WebhookEvent::LeadCreated, ['id' => 1]);

    $this->actingAs($user)
        ->post(route('integrations.deliveries.redeliver', ['delivery' => $delivery->getKey()]))
        ->assertNotFound();

    expect(WebhookDelivery::query()->withoutGlobalScopes()->count())->toBe(1);
});

it('names the endpoint each log row went to', function (): void {
    Http::fake(['*' => Http::response('ok', 200)]);

    [$user, $team] = webhookTeam();
    $first = endpointFor($team, ['lead.created']);
    $second = endpointFor($team, ['lead.created']);

    app(WebhookDispatcher::class)->dispatch($team, WebhookEvent::LeadCreated, ['id' => 1]);

    // A team with three endpoints could not tell their log rows apart without this, which made the
    // log useless to the teams that most need it.
    $this->actingAs($user)->get(route('integrations.index'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('deliveries', 2)
            ->where('deliveries.0.endpoint_id', $second->getKey())
            ->where('deliveries.1.endpoint_id', $first->getKey()));
});
