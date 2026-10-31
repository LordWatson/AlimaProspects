<?php

declare(strict_types=1);

use App\Modules\Notifications\Enums\NotificationChannel;
use App\Modules\Notifications\Enums\NotificationType;
use App\Modules\Notifications\Enums\PushPlatform;
use App\Modules\Notifications\Models\PushDevice;
use App\Modules\Notifications\Services\NotificationDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Tests\Support\NotificationFixtures as Fixtures;

uses(RefreshDatabase::class);

/**
 * Push: registration, delivery and the lifecycle of a token (Phase 12).
 *
 * The case that keeps push working over time is the last one — **a token the provider permanently
 * rejects is deleted.** A device whose app was uninstalled returns `UNREGISTERED` for ever, and a
 * row we keep is a notification that silently goes nowhere, which nobody ever notices because
 * nothing fails.
 */
beforeEach(function (): void {
    Mail::fake();
    $this->push = Fixtures::fakePush();
});

// --- Registration --------------------------------------------------------------------------------

it('lists only the member\'s own devices, without tokens', function (): void {
    [$owner] = Fixtures::team();
    [$stranger] = Fixtures::team();

    Fixtures::device($owner, 'fcm-'.str_repeat('o', 140));
    Fixtures::device($stranger, 'fcm-'.str_repeat('s', 140));

    Sanctum::actingAs($owner, ['*']);

    $response = $this->getJson(route('api.v1.push-devices.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($response->json('data.0'))->not->toHaveKey('token')
        ->and($response->json('data.0'))->not->toHaveKey('token_hash')
        ->and($response->json('data.0.platform'))->toBe('ios');
});

it('registers a device and never hands the token back', function (): void {
    [$user] = Fixtures::team();

    Sanctum::actingAs($user, ['*']);

    $token = 'fcm-'.str_repeat('x', 140);

    $response = $this->postJson(route('api.v1.push-devices.store'), [
        'token' => $token,
        'platform' => 'android',
    ])->assertCreated();

    // A token is a bearer credential for sending *to* the device, and the client already has it.
    expect($response->json('data'))->not->toHaveKey('token')
        ->and(PushDevice::query()->sole()->token_hash)->toBe(PushDevice::hash($token))
        ->and(PushDevice::query()->sole()->platform)->toBe(PushPlatform::Android);
});

it('re-registers the same token in place rather than duplicating it', function (): void {
    [$user] = Fixtures::team();

    Sanctum::actingAs($user, ['*']);

    $token = 'fcm-'.str_repeat('y', 140);

    $this->postJson(route('api.v1.push-devices.store'), ['token' => $token, 'platform' => 'ios'])
        ->assertCreated();

    // FCM re-issues on reinstall, and the app registers again on every launch.
    $this->postJson(route('api.v1.push-devices.store'), ['token' => $token, 'platform' => 'ios'])
        ->assertOk();

    expect(PushDevice::query()->count())->toBe(1);
});

it('re-assigns a token that turns up for a different member', function (): void {
    [$first] = Fixtures::team();
    [$second] = Fixtures::team();

    $token = 'fcm-'.str_repeat('z', 140);

    [$device] = Fixtures::device($first, $token);

    // A handset passed on, or a shared tablet. Leaving the old owner on it would send the new
    // owner's notifications to the previous one.
    Sanctum::actingAs($second, ['*']);
    $this->postJson(route('api.v1.push-devices.store'), ['token' => $token, 'platform' => 'ios'])
        ->assertOk();

    expect(PushDevice::query()->count())->toBe(1)
        ->and($device->fresh()->user_id)->toBe($second->getKey());
});

it('unregisters a device, and refuses to unregister somebody else\'s', function (): void {
    [$owner] = Fixtures::team();
    [$stranger] = Fixtures::team();

    [, $token] = Fixtures::device($owner);

    Sanctum::actingAs($stranger, ['*']);
    $this->deleteJson(route('api.v1.push-devices.destroy'), ['token' => $token, 'platform' => 'ios'])
        ->assertNotFound();

    expect(PushDevice::query()->count())->toBe(1);

    Sanctum::actingAs($owner, ['*']);
    $this->deleteJson(route('api.v1.push-devices.destroy'), ['token' => $token, 'platform' => 'ios'])
        ->assertNoContent();

    expect(PushDevice::query()->count())->toBe(0);
});

// --- Delivery ------------------------------------------------------------------------------------

it('pushes to every device the member has, in the team\'s language', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::device($user, 'fcm-'.str_repeat('a', 140));
    Fixtures::device($user, 'fcm-'.str_repeat('b', 140));

    Fixtures::prefer($user, $team, NotificationType::TaskDue, [NotificationChannel::Push]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, [
        'title' => 'Call them back',
        'company' => 'Acme Ltd',
        'lead_id' => 42,
    ]);

    expect($this->push->sendCount())->toBe(1)
        ->and($this->push->tokens())->toHaveCount(2);

    $message = $this->push->lastMessage();

    // Finished prose, not keys: a device shows exactly what it is given.
    expect($message->title)->toBe('A reminder you set')
        ->and($message->body)->toBe('Call them back — Acme Ltd')
        // The machine-readable half the app needs to open the right screen.
        ->and($message->data['type'])->toBe('task_due')
        ->and($message->data['url'])->toBe('/leads/42');
});

it('does not push when the member has switched push off', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::device($user);
    Fixtures::prefer($user, $team, NotificationType::TaskDue, [NotificationChannel::InApp]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call']);

    expect($this->push->sendCount())->toBe(0);
});

it('does nothing when the member has no devices', function (): void {
    [$user, $team] = Fixtures::team();

    Fixtures::prefer($user, $team, NotificationType::TaskDue, [NotificationChannel::Push]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call']);

    expect($this->push->sendCount())->toBe(0);
});

it('deletes a token the provider will never accept again', function (): void {
    [$user, $team] = Fixtures::team();

    $dead = 'fcm-'.str_repeat('d', 140);
    $live = 'fcm-'.str_repeat('l', 140);

    Fixtures::device($user, $dead);
    Fixtures::device($user, $live);

    // What FCM returns for an uninstalled app.
    $this->push->reject([$dead]);

    Fixtures::prefer($user, $team, NotificationType::TaskDue, [NotificationChannel::Push]);

    app(NotificationDispatcher::class)->send($team, $user, NotificationType::TaskDue, ['title' => 'Call']);

    // The row goes, or every future notification to that member silently goes nowhere.
    expect(PushDevice::query()->count())->toBe(1)
        ->and(PushDevice::query()->sole()->token_hash)->toBe(PushDevice::hash($live));
});

// --- Pruning -------------------------------------------------------------------------------------

it('sweeps devices nothing has been sent to in months', function (): void {
    [$user] = Fixtures::team();

    PushDevice::factory()->stale(200)->create(['user_id' => $user->getKey()]);
    PushDevice::factory()->create(['user_id' => $user->getKey()]);

    $this->artisan('notifications:prune')->assertSuccessful();

    expect(PushDevice::query()->count())->toBe(1);
});

it('reports without deleting on a dry run', function (): void {
    [$user] = Fixtures::team();

    PushDevice::factory()->stale(200)->create(['user_id' => $user->getKey()]);

    $this->artisan('notifications:prune --dry-run')->assertSuccessful();

    expect(PushDevice::query()->count())->toBe(1);
});
