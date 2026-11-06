<?php

declare(strict_types=1);

use App\Modules\Core\Models\AuditLog;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\Signals\Actions\RotateSignalWebhookSecretAction;
use App\Modules\Signals\Contracts\SignalSourceProvider;
use App\Modules\Signals\Data\SignalSourceQuery;
use App\Modules\Signals\Enums\SearchRegion;
use App\Modules\Signals\Enums\SignalSourceKind;
use App\Modules\Signals\Exceptions\SignalsException;
use App\Modules\Signals\Models\Signal;
use App\Modules\Signals\Models\SignalDefinition;
use App\Modules\Signals\Models\SignalInboundEvent;
use App\Modules\Signals\Models\SignalWebhookSecret;
use App\Modules\Signals\Services\SignalSourceRegistry;
use App\Modules\Signals\Support\SignalPresetCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;

/*
 * Reading stored inbound events back as a source (S13b, ADR 0023 §7), and rotating the secret.
 *
 * `SignalEventWebhookTest` covers the door — the signature, the window, the fields a caller may not
 * set. This covers what happens afterwards, which the S13b review found untested: the endpoint
 * stores and the **next run reads**, so a bug on this side is an event a customer was told we
 * accepted and that nothing ever looked at.
 */

uses(RefreshDatabase::class);

function webhookSource(): SignalSourceProvider
{
    return app(SignalSourceRegistry::class)->for(SignalSourceKind::Webhook);
}

/** A team with a webhook-sourced type. */
function webhookType(): array
{
    $user = createUserWithTeam();
    $team = $user->currentTeam;

    $definition = app(TeamContext::class)->run($team, function () use ($team): SignalDefinition {
        $definition = signalDefinition($team, SignalPresetCatalogue::FUNDING_ROUND);

        $definition->forceFill(['source_keys' => [SignalSourceKind::Webhook->value]])->save();

        return $definition->refresh();
    });

    return [$user, $team, $definition];
}

/** The query the builder emits for a webhook-sourced definition. */
function webhookQuery(Team $team, SignalDefinition $definition): SignalSourceQuery
{
    return new SignalSourceQuery(
        eventKey: $definition->key,
        q: '',
        region: SearchRegion::Gb,
        gl: 'gb',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        kind: SignalSourceKind::Webhook,
        config: [
            'definition_id' => (int) $definition->getKey(),
            'team_id' => (int) $team->getKey(),
        ],
    );
}

function pushEvent(Team $team, SignalDefinition $definition, array $overrides = []): SignalInboundEvent
{
    return app(TeamContext::class)->run($team, fn (): SignalInboundEvent => SignalInboundEvent::factory()->create([
        'team_id' => $team->getKey(),
        'signal_definition_id' => $definition->getKey(),
        ...$overrides,
    ]));
}

it('offers this team’s stored events for this type', function (): void {
    [, $team, $definition] = webhookType();

    pushEvent($team, $definition, ['idempotency_key' => 'one']);
    pushEvent($team, $definition, ['idempotency_key' => 'two']);

    $items = app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch(webhookQuery($team, $definition)));

    expect($items)->toHaveCount(2)
        // **Never classified by the source.** A pushed event is a customer's assertion, so it is
        // read by the same prompt as a news snippet (ADR 0023 §6–7) — the one rule that keeps a
        // caller from naming their own confidence.
        ->and($items[0]->classification)->toBeNull()
        ->and($items[0]->kind)->toBe(SignalSourceKind::Webhook);
});

it('offers nothing to another team, and nothing for another type', function (): void {
    [, $teamA, $definitionA] = webhookType();
    [, $teamB, $definitionB] = webhookType();

    pushEvent($teamA, $definitionA, ['idempotency_key' => 'team-a-event']);

    // Team B's own query finds nothing...
    $theirs = app(TeamContext::class)->run($teamB, fn (): array => webhookSource()->fetch(webhookQuery($teamB, $definitionB)));

    // ...and so does a query that *names* team A's definition while running as team B. The source
    // reads `team_id` from the query and the global scope stands behind it, so neither the config
    // nor the context alone is what protects this.
    $forged = app(TeamContext::class)->run($teamB, fn (): array => webhookSource()->fetch(
        webhookQuery($teamB, $definitionA),
    ));

    expect($theirs)->toBe([])->and($forged)->toBe([]);
});

it('ignores an event received outside the window', function (): void {
    [, $team, $definition] = webhookType();

    pushEvent($team, $definition, ['idempotency_key' => 'fresh']);
    pushEvent($team, $definition, ['idempotency_key' => 'ancient', 'received_at' => now()->subDays(60)]);

    $items = app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch(webhookQuery($team, $definition)));

    // The window is what makes "no `processed_at`" safe, and it has to stay shorter than the
    // rejection ledger's 30-day memory or a refused event would return the day that expired.
    expect($items)->toHaveCount(1)
        ->and($items[0]->raw['idempotency_key'])->toBe('fresh');
});

// **The S13b review's finding 8, and it loses a customer's data silently.** The window is capped,
// and the cap used to be spent on whatever was newest — including the events already turned into
// signals, which `dropAlreadySeen()` would discard moments later. A customer pushing two hundred
// events in a burst, which is exactly what a backfill or a retry loop looks like, had the same
// newest fifty offered on every run and the other hundred and fifty never classified at all.
it('does not spend its cap on events that are already signals', function (): void {
    [, $team, $definition] = webhookType();

    config()->set('signals.sources.webhook.max_per_run', 2);

    $first = pushEvent($team, $definition, ['idempotency_key' => 'done-1', 'received_at' => now()->subMinutes(5)]);
    $second = pushEvent($team, $definition, ['idempotency_key' => 'done-2', 'received_at' => now()->subMinutes(4)]);
    pushEvent($team, $definition, ['idempotency_key' => 'waiting-1', 'received_at' => now()->subMinutes(3)]);
    pushEvent($team, $definition, ['idempotency_key' => 'waiting-2', 'received_at' => now()->subMinutes(2)]);

    // The first two have already become signals.
    foreach ([$first, $second] as $event) {
        app(TeamContext::class)->run($team, fn () => Signal::factory()->create([
            'team_id' => $team->getKey(),
            'signal_definition_id' => $definition->getKey(),
            'dedupe_hash' => $event->dedupeIdentity(),
        ]));
    }

    $items = app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch(webhookQuery($team, $definition)));

    expect($items)->toHaveCount(2)
        ->and(collect($items)->pluck('raw.idempotency_key')->sort()->values()->all())
        ->toBe(['waiting-1', 'waiting-2']);
});

// **The subject a caller sent was being thrown away** (S13b review, finding 9). The schema accepted
// `company_name`, the row stored it in `payload`, and the classifier's batch sends title, snippet,
// source and date — so an event that named its company had it classified with no idea whom it
// concerned, and `SignalLeadMatcher` had nothing to match on.
it('puts the subject a caller named where the classifier will read it', function (): void {
    [, $team, $definition] = webhookType();

    pushEvent($team, $definition, [
        'idempotency_key' => 'with-subject',
        'summary' => 'A renewal closed this morning.',
        'payload' => ['company_name' => 'Acme Ltd', 'person_name' => 'A. Director'],
    ]);

    $items = app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch(webhookQuery($team, $definition)));

    expect($items[0]->snippet)
        ->toContain('A renewal closed this morning.')
        ->toContain('Acme Ltd')
        ->toContain('A. Director');
});

it('does not repeat a subject the summary already names', function (): void {
    [, $team, $definition] = webhookType();

    pushEvent($team, $definition, [
        'idempotency_key' => 'already-named',
        'summary' => 'Acme Ltd renewed for another year.',
        'payload' => ['company_name' => 'Acme Ltd'],
    ]);

    $items = app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch(webhookQuery($team, $definition)));

    expect($items[0]->snippet)->toBe('Acme Ltd renewed for another year.');
});

it('offers nothing when the query names no definition', function (): void {
    [, $team, $definition] = webhookType();

    pushEvent($team, $definition);

    $query = new SignalSourceQuery(
        eventKey: $definition->key,
        q: '',
        region: SearchRegion::Gb,
        gl: 'gb',
        hl: 'en',
        tbs: 'qdr:w',
        num: 20,
        kind: SignalSourceKind::Webhook,
        config: [],
    );

    expect(app(TeamContext::class)->run($team, fn (): array => webhookSource()->fetch($query)))->toBe([]);
});

it('is free, because receiving an event costs nothing', function (): void {
    expect(webhookSource()->estimatedCostMicros(500))->toBe(0);
});

// --- Rotating the secret ------------------------------------------------------------------------

it('replaces the secret rather than adding a second one', function (): void {
    [$user, $team, $definition] = webhookType();

    $first = app(RotateSignalWebhookSecretAction::class)->handle($team, $user, $definition);
    $second = app(RotateSignalWebhookSecretAction::class)->handle($team, $user, $definition);

    expect($second)->not->toBe($first);

    app(TeamContext::class)->run($team, function () use ($second): void {
        // One row, not two: two valid secrets at once is a grace period nobody asked for and a
        // second thing to find when one leaks.
        $secrets = SignalWebhookSecret::query()->get();

        expect($secrets)->toHaveCount(1)
            ->and($secrets->first()?->secret)->toBe($second)
            // A rotated secret has never been used, and saying so is how a member learns their
            // integration is still signing with the old one.
            ->and($secrets->first()?->last_used_at)->toBeNull();
    });
});

it('audits a rotation in the same commit as the rotation', function (): void {
    [$user, $team, $definition] = webhookType();

    app(RotateSignalWebhookSecretAction::class)->handle($team, $user, $definition);

    $row = AuditLog::withoutGlobalScopes()
        ->where('action', 'signals.webhook_secret_rotated')
        ->sole();

    $secret = app(TeamContext::class)->run($team, fn (): string => (string) SignalWebhookSecret::query()->sole()->secret);

    // **The hint and never the secret.** An audit row is read by operators and exported; a
    // credential in one would outlive every control this feature has.
    expect($row->properties['hint'] ?? null)->not->toBeNull()
        ->and((string) json_encode($row->properties))->not->toContain($secret)
        ->and((int) $row->team_id)->toBe((int) $team->getKey());
});

it('refuses to rotate a secret for another team’s type', function (): void {
    [$userA, $teamA] = webhookType();
    [, , $definitionB] = webhookType();

    // Handed a foreign definition it would have *created* a row pairing team A with team B's type:
    // correctly scoped by `team_id` and still a pairing that should not exist. The controllers
    // resolve within the team already; this is the second lock.
    expect(fn () => app(RotateSignalWebhookSecretAction::class)->handle($teamA, $userA, $definitionB))
        ->toThrow(SignalsException::class);

    expect(SignalWebhookSecret::withoutGlobalScopes()
        ->where('team_id', $teamA->getKey())
        ->where('signal_definition_id', $definitionB->getKey())
        ->exists())->toBeFalse();
});

it('mints a hint that is not the secret', function (): void {
    [$user, $team, $definition] = webhookType();

    $secret = app(RotateSignalWebhookSecretAction::class)->handle($team, $user, $definition);

    $model = app(TeamContext::class)->run($team, fn (): SignalWebhookSecret => SignalWebhookSecret::query()->sole());

    // The tail, so "ends in …" lets a member tell which secret their integration is signing with.
    // It is 8 of 64 hex characters of a 256-bit secret, behind the same authentication as rotating
    // it — recorded as a deliberate trade in the model's docblock rather than left implicit.
    expect($model->hint)->toBe(substr($secret, -8))
        ->and(strlen($secret))->toBe(64)
        ->and($model->hint)->not->toBe($secret);
});
