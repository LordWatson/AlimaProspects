<?php

declare(strict_types=1);

use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\Core\Observability\SentryOptions;
use App\Modules\Core\Support\AuditContext;
use App\Modules\Core\Support\TeamContext;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Severity;
use Sentry\Tracing\SamplingContext;
use Sentry\Tracing\TransactionContext;
use Sentry\UserDataBag;
use Tests\Support\RecordingSentryTransport;

/*
 * Error reporting (Phase 20, ADR 0017).
 *
 * Through the application's real configured client with a recording transport in its place, so
 * `before_send`, `ignore_exceptions` and the sampler under test are the ones production will run —
 * see Tests\Support\RecordingSentryTransport.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A DSN has to be present or the client never attempts a send. Nothing leaves the process:
    // the transport is swapped before the first capture.
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');
});

it('captures a thrown exception', function (): void {
    $transport = RecordingSentryTransport::install();

    app('sentry')->captureException(new RuntimeException('the database is on fire'));

    expect($transport->events)->toHaveCount(1);

    $exceptions = $transport->first()?->getExceptions() ?? [];

    expect($exceptions)->toHaveCount(1)
        ->and($exceptions[0]->getValue())->toBe('the database is on fire')
        ->and($exceptions[0]->getType())->toBe(RuntimeException::class);
});

it('does not report an expected refusal', function (): void {
    $transport = RecordingSentryTransport::install();

    // Both are the application working: a wrong password and a team over its AI allowance.
    // Reporting them trains an ops team to ignore the error tracker, which is the failure mode
    // that makes every other alert useless.
    app('sentry')->captureException(new AuthenticationException);
    app('sentry')->captureException(AiCreditExceededException::make());

    expect($transport->events)->toBeEmpty();

    // And a real fault still gets through, so the filter is a filter and not a mute button.
    app('sentry')->captureException(new RuntimeException('boom'));

    expect($transport->events)->toHaveCount(1);
});

it('tags an event with the tenant and the actor id, and nothing else about them', function (): void {
    $user = createUserWithTeam(['name' => 'Dana Operator', 'email' => 'dana@example.test']);
    $this->actingAs($user);

    app(TeamContext::class)->set((int) $user->currentTeamId());

    $transport = RecordingSentryTransport::install();

    app('sentry')->captureException(new RuntimeException('boom'));

    $event = $transport->first();

    expect($event?->getTags()['team_id'] ?? null)->toBe((string) $user->currentTeamId());

    $sentUser = $event?->getUser();

    expect($sentUser?->getId())->toBe($user->getKey())
        // The claim in SentryOptions is "an id and nothing else". These three are the claim.
        ->and($sentUser?->getEmail())->toBeNull()
        ->and($sentUser?->getUsername())->toBeNull()
        ->and($sentUser?->getIpAddress())->toBeNull();
});

it('flags an impersonated session', function (): void {
    app(AuditContext::class)->add(['impersonated_by' => 99]);

    $transport = RecordingSentryTransport::install();

    app('sentry')->captureException(new RuntimeException('boom'));

    // The same error from an operator standing in a customer's shoes and from the customer
    // themselves are different incidents.
    expect($transport->first()?->getTags()['impersonated'] ?? null)->toBe('true');
});

it('redacts sensitive values anywhere in an event', function (): void {
    $transport = RecordingSentryTransport::install();

    app('sentry')->captureEvent(
        tap(Event::createEvent(), function (Event $event): void {
            $event->setRequest([
                'url' => 'https://alimaprospects.test/login',
            ]);

            $event->setExtra([
                'nested' => ['api_key' => 'sk-live-123', 'keep' => 'visible'],
                'password' => 'hunter2',
                'stripe_secret' => 'sk_live_abc',
                'two_factor_recovery_codes' => ['a', 'b'],
                // The allowlist: a token *count* is a measurement, and redacting it would cost
                // real debugging for no privacy gain.
                'input_tokens' => 4200,
                'prompt_key' => 'lead.summary',
            ]);

            $event->setBreadcrumb([
                new Breadcrumb(
                    Breadcrumb::LEVEL_INFO,
                    Breadcrumb::TYPE_DEFAULT,
                    'app',
                    'Saved a lead',
                    ['contact_email' => 'someone@example.test', 'lead_id' => 7],
                ),
            ]);
        }),
    );

    $event = $transport->first();

    $extra = $event?->getExtra() ?? [];
    /** @var array<string, mixed> $nested */
    $nested = $extra['nested'] ?? [];

    // At any depth, and only the sensitive key: `keep` survives beside a redacted sibling.
    expect($nested['api_key'] ?? null)->toBe('[redacted]')
        ->and($nested['keep'] ?? null)->toBe('visible')
        ->and($extra['password'] ?? null)->toBe('[redacted]')
        ->and($extra['stripe_secret'] ?? null)->toBe('[redacted]')
        ->and($extra['two_factor_recovery_codes'] ?? null)->toBe('[redacted]')
        ->and($extra['input_tokens'] ?? null)->toBe(4200)
        ->and($extra['prompt_key'] ?? null)->toBe('lead.summary');

    $metadata = ($event?->getBreadcrumbs()[0] ?? null)?->getMetadata() ?? [];

    // The realistic leak: a Log::error with a payload in it becomes a breadcrumb.
    expect($metadata['contact_email'] ?? null)->toBe('[redacted]')
        ->and($metadata['lead_id'] ?? null)->toBe(7);
});

/*
 * The request bag (Phase 20 security review).
 *
 * The finding that made these tests necessary: a key-based scrubber is structurally the wrong tool
 * for `url`, `query_string`, `cookies` and `data` — none of those *keys* looks sensitive while
 * three of the values are — and `send_default_pii => false` does **not** stop the SDK collecting
 * them. It gates the cookies, the headers and the IP; the URL, the query string and (in the SDK's
 * default legacy `data_collection` mode) the request body are collected regardless.
 */

it('never sends a query string, because a signed url is a credential', function (): void {
    $transport = RecordingSentryTransport::install();

    app('sentry')->captureEvent(
        tap(Event::createEvent(), static function (Event $event): void {
            $event->setRequest([
                // A real one: the invitation token is in the path and a working signature is in the
                // query (`Auth\Support\InvitationUrl`). The one-click unsubscribe link is the same
                // shape. Shipped to an error tracker, that is a replayable credential readable by
                // everyone with access to the project.
                'url' => 'https://app.example.test/invitations/secret-token-123?expires=1999999999&signature=deadbeef',
                'query_string' => 'expires=1999999999&signature=deadbeef',
                'method' => 'GET',
            ]);
        }),
    );

    $request = $transport->first()?->getRequest() ?? [];

    expect($request['query_string'] ?? null)->toBe('[redacted]')
        // The path survives — which endpoint failed is the useful half — and the token in it is
        // part of the path, so the URL alone is no longer a working link.
        ->and($request['url'] ?? null)->toBe('https://app.example.test/invitations/secret-token-123?[redacted]')
        ->and($request['method'] ?? null)->toBe('GET');
});

it('never sends the request body or the cookies', function (): void {
    $transport = RecordingSentryTransport::install();

    app('sentry')->captureEvent(
        tap(Event::createEvent(), static function (Event $event): void {
            $event->setRequest([
                'url' => 'https://app.example.test/leads',
                // The prospect record somebody was saving. None of these keys is credential-shaped,
                // so no deny-list was ever going to catch them — and all of it is third-party
                // personal data this company is a processor for.
                'data' => [
                    'name' => 'Dana Prospect',
                    'company_name' => 'Acme Holdings Ltd',
                    'notes' => 'Met at the trade show, interested in Q1.',
                ],
                'cookies' => ['alimaprospects_session' => 'abc123'],
            ]);
        }),
    );

    $request = $transport->first()?->getRequest() ?? [];

    expect($request)->not->toHaveKey('data')
        ->and($request)->not->toHaveKey('cookies')
        ->and($request['url'] ?? null)->toBe('https://app.example.test/leads');
});

it('tells the SDK not to collect a request body in the first place', function (): void {
    // Dropping it in `before_send` is the second line. This is the first: the SDK's own default is
    // `medium`, which attaches ten kilobytes of body to every error event.
    expect(config('sentry.max_request_body_size'))->toBeIn(['none', 'never']);

    expect(app(ClientBuilder::class)->getOptions()->getMaxRequestBodySize())
        ->toBeIn(['none', 'never']);
});

it('drops the user metadata rather than redacting it', function (): void {
    $transport = RecordingSentryTransport::install();

    app('sentry')->captureEvent(
        tap(Event::createEvent(), static function (Event $event): void {
            $event->setUser(UserDataBag::createFromArray([
                'id' => 42,
                'email' => 'leak@example.test',
            ])->setMetadata('phone', '+971500000000'));
        }),
    );

    $user = $transport->first()?->getUser();

    expect($user?->getId())->toBe(42)
        ->and($user?->getEmail())->toBeNull()
        // Dropped, not `[redacted]`: a redaction in a user field reads like a value.
        ->and($user?->getMetadata())->not->toHaveKey('phone');
});

it('does not trace the health check or the operator dashboards', function (): void {
    Config::set('sentry.traces_sample_rate', 1.0);

    $sample = static fn (string $name): float => SentryOptions::tracesSampler(
        SamplingContext::getDefault(new TransactionContext($name)),
    );

    expect($sample('/up'))->toBe(0.0)
        ->and($sample('GET /pulse'))->toBe(0.0)
        ->and($sample('GET /horizon/jobs'))->toBe(0.0)
        // Everything else honours the configured rate.
        ->and($sample('GET /leads'))->toBe(1.0);
});

it('honours a parent trace decision over the configured rate', function (): void {
    Config::set('sentry.traces_sample_rate', 0.0);

    $context = SamplingContext::getDefault(new TransactionContext('GET /leads'));
    $context->setParentSampled(true);

    // A trace that is half sampled is not a trace.
    expect(SentryOptions::tracesSampler($context))->toBe(1.0);
});

it('scrubs a transaction and drops the ignored ones', function (): void {
    $transaction = Event::createTransaction();
    $transaction->setTransaction('GET /pulse');

    expect(SentryOptions::beforeSendTransaction($transaction, new EventHint))->toBeNull();

    $kept = Event::createTransaction();
    $kept->setTransaction('GET /leads');
    $kept->setExtra(['password' => 'hunter2']);

    $result = SentryOptions::beforeSendTransaction($kept, new EventHint);

    expect($result)->not->toBeNull()
        ->and($result?->getExtra()['password'] ?? null)->toBe('[redacted]');
});

it('never throws while annotating, whatever state the container is in', function (): void {
    // This runs while an exception is being reported, so an error tracker that throws on its way
    // to reporting an error is the worst possible failure mode — `annotate()` swallows everything.
    //
    // The failure has to be **forced**: an earlier version of this test just called `beforeSend`
    // with a plain event, which returns it whether or not the `try` is there. Making the container
    // throw on the one resolution `annotate()` performs is what makes the assertion mean anything.
    app()->bind(TeamContext::class, function (): never {
        throw new RuntimeException('the container is on fire');
    });

    $event = Event::createEvent();
    $event->setLevel(Severity::error());

    expect(SentryOptions::beforeSend($event, new EventHint))->toBe($event)
        // And the event is still scrubbed: the guard is around the annotation, not around the
        // redaction, so a broken container costs a tag and never costs a redaction.
        ->and($event->getTags())->not->toHaveKey('team_id');
});

it('tags the request id and the console context', function (): void {
    $transport = RecordingSentryTransport::install();

    // A console event carries no request id — there is no request — and says so, which is how an
    // operator tells a queue failure from a web one at a glance.
    app('sentry')->captureException(new RuntimeException('boom'));

    expect($transport->first()?->getTags()['context'] ?? null)->toBe('console');
});

it('tags the request id on a web request', function (): void {
    Config::set('sentry.dsn', 'https://public@o0.ingest.test/1');

    // Through a real request, so `LogContext` mints the id and the middleware stack is the one
    // production runs. The route throws, and the reported event has to carry the same id the
    // response header does — that is the whole point of the tag: it is the join between a Sentry
    // event and the log lines around it.
    // On the `web` group, because that is where `LogContext` lives: a bare `Route::get()` in a
    // test carries no group middleware, so the id would never be minted and the test would be
    // asserting against a route shape production does not have.
    Route::middleware('web')->get('_test/boom', fn () => throw new RuntimeException('deliberate'));

    $transport = RecordingSentryTransport::install();

    $response = $this->withHeader('X-Request-Id', 'req-abc-123')
        ->get('_test/boom');

    expect($response->headers->get('X-Request-Id'))->toBe('req-abc-123')
        ->and($transport->first()?->getTags()['request_id'] ?? null)->toBe('req-abc-123');
});

it('leaves no stale tenant tag behind after a job has finished with a team', function (): void {
    $user = createUserWithTeam();
    $teamId = (int) $user->currentTeamId();

    $context = app(TeamContext::class);

    // Simulate a fresh worker. `createUserWithTeam()` goes through `CreatePersonalTeamAction`,
    // which activates the new team — a *request*-path `set()` — so without this the context would
    // already hold the team and the assertion below would be measuring the fixture rather than
    // `run()`. (Finding that was the point of writing the test.)
    $context->forget();

    // Every job in this product activates its tenant with `TeamContext::run()`, which restores the
    // previous value in a `finally`. That is what makes a **false** tenant tag impossible: a
    // scheduled sweep running after a team's job on the same long-lived worker cannot inherit it.
    try {
        $context->run($teamId, function (): void {
            throw new RuntimeException('the job failed');
        });
    } catch (RuntimeException) {
        // The job failing is the case under test.
    }

    expect($context->id())->toBeNull();

    $transport = RecordingSentryTransport::install();
    app('sentry')->captureException(new RuntimeException('reported after the job unwound'));

    // The direction this fails in is the safe one, and it is a real limitation rather than a
    // feature: an exception that escapes a job is reported *after* `run()` has restored the
    // context, so it carries **no** `team_id` — a missing tag, never a wrong one. ADR 0017 records
    // it; the job's own log line still carries the team, because the job logs inside `run()`.
    expect($transport->first()?->getTags() ?? [])->not->toHaveKey('team_id');
});
