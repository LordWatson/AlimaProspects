<?php

declare(strict_types=1);

use App\Modules\Core\Enums\ApiErrorCode;
use App\Modules\Core\Enums\TeamRole;
use App\Modules\Core\Models\Team;
use App\Modules\Core\Support\TeamContext;
use App\Modules\LeadSourcing\Models\Lead;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\Support\LeadsWorkspaceFixtures as Fixtures;

/*
 * The error contract (Phase 17): every `/api/v1` failure answers with `{error: {code, message,
 * details?}}`, whichever layer refused — the authenticator, a policy, the router, the validator,
 * the throttle, or an unhandled fault.
 *
 * These are the assertions a mobile client's error handling is written against, so they are
 * deliberately literal about the key paths that `assertApiError()` checks: the macro keeps the
 * other 1,400 tests readable, and this file is where the shape itself is pinned down.
 */

uses(RefreshDatabase::class);

it('answers an unauthenticated call with the envelope', function (): void {
    $response = $this->getJson(route('api.v1.leads.index'));

    $response->assertUnauthorized();

    expect($response->json())->toBe([
        'error' => [
            'code' => 'unauthenticated',
            'message' => ApiErrorCode::Unauthenticated->message(),
        ],
    ]);
});

it('answers a failed validation with field errors under details', function (): void {
    [$user] = Fixtures::team();

    $response = $this->actingAs($user)->postJson(route('api.v1.leads.store'), []);

    $response->assertUnprocessable()
        ->assertJsonPath('error.code', 'validation_failed')
        ->assertJsonStructure(['error' => ['code', 'message', 'details' => ['company_name']]])
        // Not Laravel's native shape: one envelope, not two.
        ->assertJsonMissingPath('errors')
        ->assertJsonMissingPath('message');

    expect($response->json('error.details.company_name'))->toBeArray();
});

it('answers a record on another team with not_found, never forbidden', function (): void {
    [$user] = Fixtures::team();
    [, $otherTeam] = Fixtures::team();

    $foreign = app(TeamContext::class)->run($otherTeam, fn (): Lead => Lead::factory()->create([
        'team_id' => $otherTeam->getKey(),
        'dedupe_key' => 'no:foreign-envelope',
    ]));

    // 404 rather than 403 on purpose: whether the record exists is itself not this team's to know.
    $this->actingAs($user)
        ->getJson(route('api.v1.leads.show', ['lead' => $foreign->getKey()]))
        ->assertApiError(ApiErrorCode::NotFound, 404);
});

it('answers a policy refusal with forbidden', function (): void {
    [, $team] = Fixtures::team();

    // A read-only member: authenticated, on the right team, and not allowed to add a lead. This is
    // the shape a 403 has to have — which is a different answer from the 404 above, and
    // deliberately so: refusing an action is not the same as denying a record exists.
    $readOnly = Fixtures::member($team, TeamRole::ReadOnly);

    $this->actingAs($readOnly)
        ->postJson(route('api.v1.leads.store'), [
            'company_name' => 'Bright Bean Coffee',
        ])
        ->assertApiError(ApiErrorCode::Forbidden, 403);
});

it('answers a wrong method with method_not_allowed and keeps the Allow header', function (): void {
    [$user] = Fixtures::team();

    $response = $this->actingAs($user)->deleteJson(route('api.v1.leads.index'));

    $response->assertStatus(405)->assertApiError(ApiErrorCode::MethodNotAllowed);

    expect($response->headers->get('Allow'))->toContain('GET');
});

it('answers a throttled call with too_many_requests and Retry-After', function (): void {
    [$user] = Fixtures::team();

    // The global limiter is 60/minute per token; exhaust it rather than reconfiguring it, so the
    // test exercises the real limiter and the real headers.
    RateLimiter::clear('api');

    $response = null;

    for ($i = 0; $i <= 60; $i++) {
        $response = $this->actingAs($user)->getJson(route('api.v1.leads.stages'));

        if ($response->getStatusCode() === 429) {
            break;
        }
    }

    $response->assertStatus(429)->assertApiError(ApiErrorCode::TooManyRequests);

    expect($response->headers->get('Retry-After'))->not->toBeNull();
});

it('answers an unsupported pinned version with unsupported_api_version', function (): void {
    [$user] = Fixtures::team();

    $this->actingAs($user)
        ->withHeader('X-Api-Version', '2')
        ->getJson(route('api.v1.leads.index'))
        ->assertApiError(ApiErrorCode::UnsupportedApiVersion, 400, ['requested', 'supported']);
});

it('leaves the web forms on Laravel native validation', function (): void {
    [$user] = Fixtures::team();

    // The envelope is scoped to `api/*` by path. Inertia requests also expect JSON, and every form
    // in the app reads its error bag from `errors` — widening the envelope to `expectsJson()`
    // would break all of them silently.
    $this->actingAs($user)
        ->postJson(route('leads.store'), [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_name')
        ->assertJsonMissingPath('error');
});

it('keeps a domain exception in the same envelope', function (): void {
    [$user, $team] = Fixtures::team();

    // `TeamMembershipException` and friends render themselves; the point is that they render
    // through the same builder, so a client cannot tell a domain refusal from a framework one by
    // its shape — only by its code.
    $response = $this->actingAs($user)->postJson(route('api.v1.teams.switch', [
        'team' => Team::factory()->create()->getKey(),
    ]));

    $response->assertForbidden();

    expect($response->json())->toHaveKey('error.code');
    expect($response->json('error.code'))->toBeString();
    expect($team->getKey())->toBeInt();
});

it('hands back a response an exception carried, instead of a 500', function (): void {
    [$user] = Fixtures::team();

    // `HttpResponseException` carries a finished response (an `abort($response)`, a FormRequest
    // overriding `failedValidation`, a vendor package). The handler returns it one `match` arm
    // after the render callbacks, so a renderer that treats it as unhandled throws away a
    // deliberate answer and sends a 500 in its place.
    Route::middleware(['api', 'auth:sanctum'])->get('api/v1/testing/carried-response', function (): never {
        throw new HttpResponseException(new JsonResponse(['carried' => true], 418));
    });

    $this->actingAs($user)
        ->getJson('api/v1/testing/carried-response')
        ->assertStatus(418)
        ->assertExactJson(['carried' => true]);
});

it('does not read an unmapped client error as a server error', function (): void {
    [$user] = Fixtures::team();

    // `server_error` tells a client the fault was ours and the request is worth retrying. For a
    // 415 it is neither, and `code` is what a client branches on.
    Route::middleware(['api', 'auth:sanctum'])->get('api/v1/testing/unsupported-type', function (): never {
        abort(415);
    });

    $this->actingAs($user)
        ->getJson('api/v1/testing/unsupported-type')
        ->assertApiError(ApiErrorCode::UnsupportedMediaType, 415);
});

it('keeps the internals of a server error out of the response', function (): void {
    [$user] = Fixtures::team();

    // A 5xx message describes our internals. It belongs in the logs and in Sentry, not on a
    // prospect's phone — and the status has already told the client what to do.
    Route::middleware(['api', 'auth:sanctum'])->get('api/v1/testing/leaky-failure', function (): never {
        abort(503, 'redis pool exhausted on worker-3');
    });

    $response = $this->actingAs($user)->getJson('api/v1/testing/leaky-failure');

    $response->assertStatus(503)->assertApiError(ApiErrorCode::ServiceUnavailable);

    expect($response->json('error.message'))
        ->toBe(ApiErrorCode::ServiceUnavailable->message())
        ->not->toContain('worker-3');
});
