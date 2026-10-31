<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\Auth\Actions\IssueApiTokenAction;
use App\Modules\Auth\Enums\TokenAbility;
use App\Modules\Core\Enums\ApiErrorCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

/*
 * Token scopes (Phase 17). Every test here sends a **real** token as `Authorization: Bearer …`,
 * because that is the only way the scope is exercised: `Sanctum::actingAs()` sets a token object on
 * the user but sends no credential, and `EnsureTokenAbility` reads the credential the way Sanctum's
 * guard does. A test that used the helper would pass without checking anything.
 */

uses(RefreshDatabase::class);

/**
 * @param  list<string>  $abilities
 */
function tokenFor(User $user, array $abilities): string
{
    return app(IssueApiTokenAction::class)->handle($user, 'Contract test', $abilities);
}

it('lets a scoped token through to what it is scoped for', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team, ['company_name' => 'Bright Bean Coffee']);

    $token = tokenFor($user, [TokenAbility::LeadsRead->value]);

    $this->withToken($token)
        ->getJson(route('api.v1.leads.index'))
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('refuses a token that lacks the ability, and says which one it needed', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    // Read-only on leads: the list is fine, adding one is not.
    $token = tokenFor($user, [TokenAbility::LeadsRead->value]);

    $response = $this->withToken($token)->postJson(route('api.v1.leads.store'), [
        'company_name' => 'Bright Bean Coffee',
    ]);

    $response->assertApiError(ApiErrorCode::InsufficientTokenAbility, 403);

    expect($response->json('error.details.required_abilities'))
        ->toBe([TokenAbility::LeadsWrite->value]);
});

it('refuses a token scoped to another resource entirely', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    $token = tokenFor($user, [TokenAbility::ReportsRead->value]);

    $this->withToken($token)
        ->getJson(route('api.v1.leads.index'))
        ->assertApiError(ApiErrorCode::InsufficientTokenAbility, 403);

    // …and through to the one it *is* scoped for.
    $this->withToken($token)
        ->getJson(route('api.v1.reports.dashboard'))
        ->assertOk();
});

it('lets a wildcard token do everything', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    $token = tokenFor($user, [TokenAbility::WILDCARD]);

    $this->withToken($token)->getJson(route('api.v1.leads.index'))->assertOk();
    $this->withToken($token)->getJson(route('api.v1.signals.index'))->assertOk();
    $this->withToken($token)->getJson(route('api.v1.notifications.unread'))->assertOk();
});

it('never scopes away identity or a token\'s own lifecycle', function (): void {
    [$user] = Fixtures::team();

    // A token with the narrowest scope there is must still be able to say who it belongs to, list
    // its siblings and revoke itself — otherwise it is a credential nobody can clean up.
    $token = tokenFor($user, [TokenAbility::ReportsRead->value]);

    $this->withToken($token)->getJson(route('api.v1.user.show'))->assertOk();
    $this->withToken($token)->getJson(route('api.v1.auth.tokens.index'))->assertOk();
    $this->withToken($token)->deleteJson(route('api.v1.auth.token.current'))->assertNoContent();

    expect($user->tokens()->count())->toBe(0);
});

it('answers a revoked token with unauthenticated, not forbidden', function (): void {
    [$user] = Fixtures::team();

    $token = tokenFor($user, [TokenAbility::LeadsRead->value]);

    $user->tokens()->delete();

    // 401: the credential is gone, so there is nothing to scope. Answering 403 here would send a
    // client off to widen a scope on a token that no longer exists.
    $this->withToken($token)
        ->getJson(route('api.v1.leads.index'))
        ->assertApiError(ApiErrorCode::Unauthenticated, 401);
});

it('lets a first-party session caller through without scopes', function (): void {
    [$user, $team] = Fixtures::team();
    Fixtures::lead($team);

    // The web app reaches these endpoints on a session cookie and has no notion of a scope. If the
    // guard refused it, the same member would be less trusted through /api/v1 than through a page.
    $this->actingAs($user)->getJson(route('api.v1.leads.index'))->assertOk();
});

it('issues a token only with abilities the API actually enforces', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->postJson(route('api-tokens.store'), [
            'name' => 'Invented scope',
            'abilities' => ['leads:everything'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('abilities.0');

    expect($user->tokens()->count())->toBe(0);
});

it('issues a token over the API with the abilities it asked for', function (): void {
    [$user] = Fixtures::team();

    $response = $this->postJson(route('api.v1.auth.token.issue'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Contract test iPhone',
        'abilities' => [TokenAbility::LeadsRead->value, TokenAbility::NotificationsRead->value],
    ]);

    $response->assertCreated();

    $token = $user->tokens()->firstOrFail();

    expect($token->abilities)->toBe([
        TokenAbility::LeadsRead->value,
        TokenAbility::NotificationsRead->value,
    ]);

    // And the token it handed back is scoped to exactly that.
    $plainText = (string) $response->json('token');

    $this->withToken($plainText)->getJson(route('api.v1.leads.index'))->assertOk();
    $this->withToken($plainText)
        ->getJson(route('api.v1.signals.index'))
        ->assertApiError(ApiErrorCode::InsufficientTokenAbility, 403);
});

it('rejects an invented ability at the token exchange too', function (): void {
    [$user] = Fixtures::team();

    $this->postJson(route('api.v1.auth.token.issue'), [
        'email' => $user->email,
        'password' => 'password',
        'device_name' => 'Contract test iPhone',
        'abilities' => ['leads:*'],
    ])->assertApiValidationErrors('abilities.0');
});
