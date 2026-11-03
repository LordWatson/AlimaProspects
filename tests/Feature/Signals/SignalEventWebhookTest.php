<?php

declare(strict_types=1);

use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalWebhookSecret;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;

/*
 * `POST /api/v1/signal-events` (S13b, ADR 0023 §7).
 *
 * **Most of this file refuses things**, which is the right proportion for the only path into this
 * module that originates outside our own code. Each of the ADR's conditions of acceptance gets a
 * test, and so does each of the fields a caller must not be able to set — because the way this
 * feature fails is not "the endpoint is broken", it is "a customer could assert something Alima
 * should have decided".
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('signals.sources.webhook.timestamp_tolerance', 300);
});

/** A team, an active webhook-sourced type, and its secret. */
function webhookFixture(): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $definition = app(TeamContext::class)->run($team, function () use ($team): SignalDefinition {
        $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

        $definition->forceFill([
            'source_keys' => [SignalSourceKind::Webhook->value],
        ])->save();

        return $definition->refresh();
    });

    $secret = 'a'.str_repeat('b', 63);

    app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::factory()
        ->withSecret($secret)
        ->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
        ]));

    Sanctum::actingAs($user, ['signals:write']);

    return [$team, $definition, $secret];
}

/**
 * Sign and post a body, the way a customer's own code would.
 *
 * The body is serialised **once** and both signed and sent, which is the point of the scheme: the
 * signature covers the exact bytes, so a test that re-encoded between signing and sending would be
 * testing something the product deliberately refuses.
 */
function postEvent(array $body, string $secret, ?int $timestamp = null, ?string $signature = null, array $extraHeaders = []): TestResponse
{
    $raw = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp ??= now()->getTimestamp();
    $signature ??= hash_hmac('sha256', $timestamp.'.'.$raw, $secret);

    return test()->call(
        'POST',
        '/api/v1/signal-events',
        [],
        [],
        [],
        collect([
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_SIGNAL_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_SIGNAL_SIGNATURE' => $signature,
        ])->merge($extraHeaders)->all(),
        $raw,
    );
}

function eventBody(array $overrides = []): array
{
    return [
        'event_type' => SignalPresetCatalogue::FUNDING_ROUND,
        'idempotency_key' => 'crm-deal-4411',
        'title' => 'Acme signed a renewal worth £400k',
        'summary' => 'Our CRM recorded a closed-won renewal.',
        'url' => 'https://crm.example.com/deals/4411',
        'source_name' => 'Acme CRM',
        'company_name' => 'Acme Ltd',
        ...$overrides,
    ];
}

it('accepts a correctly signed event', function (): void {
    [$team, $definition, $secret] = webhookFixture();

    postEvent(eventBody(), $secret)
        ->assertStatus(202)
        ->assertJsonPath('data.idempotency_key', 'crm-deal-4411')
        ->assertJsonPath('data.created', true);

    $event = SignalInboundEvent::withoutGlobalScopes()->sole();

    expect($event->team_id)->toBe($team->getKey())
        // Resolved from the **key** within the authenticated team, never taken as an id.
        ->and($event->signal_definition_id)->toBe($definition->getKey())
        ->and($event->title)->toBe('Acme signed a renewal worth £400k')
        ->and($event->source_name)->toBe('Acme CRM');
});

it('accepts a replay once and acknowledges it twice', function (): void {
    [, , $secret] = webhookFixture();

    postEvent(eventBody(), $secret)->assertStatus(202)->assertJsonPath('data.created', true);

    // A machine retrying asked for the event to exist, and it does. Reporting a conflict would make
    // a correct client look broken and tempt an integration into treating its own retries as errors.
    postEvent(eventBody(), $secret)->assertStatus(202)->assertJsonPath('data.created', false);

    expect(SignalInboundEvent::withoutGlobalScopes()->count())->toBe(1);
});

it('refuses a bad signature', function (): void {
    [, , $secret] = webhookFixture();

    postEvent(eventBody(), $secret, signature: str_repeat('0', 64))->assertStatus(403);

    expect(SignalInboundEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses a signature over a different body', function (): void {
    [, , $secret] = webhookFixture();

    // The signature covers the exact bytes. Signing one payload and sending another — the move
    // available to anybody who can intercept and edit — does not survive.
    $signed = json_encode(eventBody(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $timestamp = now()->getTimestamp();
    $signature = hash_hmac('sha256', $timestamp.'.'.$signed, $secret);

    postEvent(eventBody(['title' => 'Something else entirely']), $secret, $timestamp, $signature)
        ->assertStatus(403);
});

it('refuses a stale timestamp, so a captured request expires', function (): void {
    [, , $secret] = webhookFixture();

    postEvent(eventBody(), $secret, timestamp: now()->subHour()->getTimestamp())->assertStatus(403);

    // And a far-future one, because a fast clock is as much a sign of a forged request as a slow
    // one — and accepting one would let a caller mint a signature valid for a week.
    postEvent(eventBody(), $secret, timestamp: now()->addHour()->getTimestamp())->assertStatus(403);
});

it('refuses a request with no signature or no timestamp at all', function (): void {
    [, , $secret] = webhookFixture();

    postEvent(eventBody(), $secret, extraHeaders: ['HTTP_X_SIGNAL_SIGNATURE' => ''])->assertStatus(403);

    $raw = json_encode(eventBody());

    $this->call('POST', '/api/v1/signal-events', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SIGNAL_SIGNATURE' => hash_hmac('sha256', '1.'.$raw, $secret),
    ], $raw)->assertStatus(403);
});

it('refuses an event for a signal type that belongs to another team', function (): void {
    [, , $secret] = webhookFixture();

    $other = Team::factory()->create();
    app(TeamContext::class)->run($other, fn () => signalDefinition($other, SignalPresetCatalogue::ACQUISITION));

    // The lookup is scoped by the token's team, so another tenant's key simply does not resolve —
    // and the answer is the same 403 a bad signature gets. The difference would be an oracle: "that
    // key exists but you may not use it" is how somebody enumerates another team's signal types.
    postEvent(eventBody(['event_type' => SignalPresetCatalogue::ACQUISITION]), $secret)
        ->assertStatus(403);

    expect(SignalInboundEvent::withoutGlobalScopes()->count())->toBe(0);
});

it('refuses an event for a paused signal type', function (): void {
    [$team, $definition, $secret] = webhookFixture();

    app(TeamContext::class)->run($team, fn () => $definition->forceFill(['is_active' => false])->save());

    // A paused type searches nothing, so it receives nothing. Accepting would store events that
    // could never be classified.
    postEvent(eventBody(), $secret)->assertStatus(403);
});

it('refuses an event for a type with no secret', function (): void {
    [$team, $definition, $secret] = webhookFixture();

    app(TeamContext::class)->run($team, fn () => SignalWebhookSecret::query()
        ->where('signal_definition_id', $definition->getKey())
        ->delete());

    // Revoking the secret revokes the integration, which is what "one secret per type" is for.
    postEvent(eventBody(), $secret)->assertStatus(403);
});

// --- What a caller may not set (ADR 0023 §7) --------------------------------------------------

it('ignores every field a caller must not be able to set', function (): void {
    [, , $secret] = webhookFixture();

    postEvent(eventBody([
        // The score is ours, computed by `SignalScorer` from stored components. A caller who could
        // set it could rank themselves to the top of somebody's morning.
        'heat' => 100,
        'score_breakdown' => ['anything' => 'at all'],
        'confidence' => 100,
        'relevance' => 100,
        // **The one that is not a matter of taste.** These decide which outreach restriction a
        // converted lead carries, so a caller able to assert them is a compliance hole with an API
        // key (ADR 0019 §4, compliance.md §3.11).
        'jurisdiction' => 'XX',
        'resolved_market' => 'uk',
        // Provenance and matching are the product's conclusions about the team's own data.
        'lead_id' => 1,
        'matched_lead_id' => 1,
        // The team comes from the token, and the type from a key resolved within it.
        'team_id' => 999,
        'signal_definition_id' => 999,
        'status' => 'converted',
    ]), $secret)->assertStatus(202);

    $event = SignalInboundEvent::withoutGlobalScopes()->sole();

    // Stripped before validation rather than rejected: a customer's integration that helpfully
    // sends `confidence` should have it ignored rather than have its event refused. Either way the
    // value never reaches a column — and the stored payload is what we *accepted*, so an operator
    // reading it sees that too.
    foreach ([
        'heat', 'score_breakdown', 'confidence', 'relevance', 'jurisdiction',
        'resolved_market', 'lead_id', 'matched_lead_id', 'team_id',
        'signal_definition_id', 'status',
    ] as $forbidden) {
        expect($event->payload)->not->toHaveKey($forbidden);
    }
});

it('refuses a payload that breaks the bounded schema', function (): void {
    [, , $secret] = webhookFixture();

    // Rejected whole rather than coerced.
    postEvent(eventBody(['title' => '']), $secret)->assertStatus(422);
    postEvent(eventBody(['title' => str_repeat('x', 500)]), $secret)->assertStatus(422);
    postEvent(eventBody(['idempotency_key' => 'short']), $secret)->assertStatus(422);
    postEvent(eventBody(['url' => 'javascript:alert(1)']), $secret)->assertStatus(422);
    postEvent(eventBody(['occurred_at' => 'not a date']), $secret)->assertStatus(422);
    // A date far enough out to be nonsense rather than news.
    postEvent(eventBody(['occurred_at' => now()->addYear()->toIso8601String()]), $secret)->assertStatus(422);
});

it('refuses a request whose token lacks the signals ability', function (): void {
    [, , $secret] = webhookFixture();

    $user = createUserWithTeam();
    Sanctum::actingAs($user, ['leads:read']);

    // A scope asks what a *credential* may do (ADR 0013), and this credential may not write signals.
    postEvent(eventBody(), $secret)->assertStatus(403);
});

// --- The secret itself -------------------------------------------------------------------------

it('never stores the secret in the clear', function (): void {
    [$team, $definition] = webhookFixture();

    $stored = (string) DB::table('signal_webhook_secrets')
        ->where('signal_definition_id', $definition->getKey())
        ->value('secret');

    $secret = 'a'.str_repeat('b', 63);

    // Encrypted rather than hashed, because verifying an HMAC means recomputing it — but a database
    // dump must still yield ciphertext.
    expect($stored)->not->toBe($secret)
        ->and($stored)->not->toContain($secret)
        ->and(strlen($stored))->toBeGreaterThan(64);

    $model = app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::query()->sole());

    expect($model->secret)->toBe($secret)
        // The hint is the tail, which is what differs between two secrets somebody is telling apart.
        ->and($model->hint)->toBe(substr($secret, -8));
});

it('records when a secret was last used', function (): void {
    [$team, , $secret] = webhookFixture();

    postEvent(eventBody(), $secret)->assertStatus(202);

    $model = app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::query()->sole());

    // So a member can see whether an integration they set up is actually sending anything.
    expect($model->last_used_at)->not->toBeNull();
});

it('produces the signature a customer’s own code would', function (): void {
    [$team, , $secret] = webhookFixture();

    $model = app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::query()->sole());

    // One definition of what a valid signature is, beside the secret it is made with — so the docs,
    // the tests and a customer's implementation cannot drift.
    expect($model->signatureFor('1762000000', '{"a":1}'))
        ->toBe(hash_hmac('sha256', '1762000000.{"a":1}', $secret))
        ->and($model->verify('1762000000', '{"a":1}', $model->signatureFor('1762000000', '{"a":1}')))
        ->toBeTrue()
        ->and($model->verify('1762000000', '{"a":2}', $model->signatureFor('1762000000', '{"a":1}')))
        ->toBeFalse();
});
