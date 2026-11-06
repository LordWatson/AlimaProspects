<?php

use App\Modules\AiPlatform\Exceptions\AiCreditExceededException;
use App\Modules\AiPlatform\Exceptions\AiInputTooLargeException;
use App\Modules\Core\Exceptions\CapabilityException;
use App\Modules\Core\Observability\SentryOptions;
use App\Modules\Signals\Exceptions\SignalAllowanceExceeded;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Sentry Laravel SDK configuration file.
 *
 * **Every key in here is handed to Sentry's own option resolver, which throws on a key it does not
 * know** — so this file holds the SDK's settings and nothing else. Ours live in
 * `config/observability.php`: the browser and mobile DSNs, the scrub list, and the transactions the
 * sampler drops. A `frontend_dsn` added here would stop the application booting (Phase 20).
 *
 * The three callables below are static methods rather than closures for an equally unglamorous
 * reason: a closure in a config file makes `php artisan config:cache` fail, and the first symptom
 * of that is a deploy that will not boot. See {@see SentryOptions} for what they do.
 *
 * @see https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/
 */
return [

    /*
    | Scrub and annotate (Phase 20, ADR 0017). Nothing is dropped here — expected exceptions are
    | filtered declaratively by `ignore_exceptions` below, which is cheaper because the event is
    | never built.
    */
    'before_send' => [SentryOptions::class, 'beforeSend'],
    'before_send_transaction' => [SentryOptions::class, 'beforeSendTransaction'],

    /*
    | The sampling decision, so a health check and the two polling operator dashboards are never
    | measured rather than measured and thrown away. It honours a parent's decision — a trace that
    | is half sampled is not a trace — and otherwise falls back to `traces_sample_rate` below.
    */
    'traces_sampler' => [SentryOptions::class, 'tracesSampler'],

    /*
    | Expected refusals, not faults. Every one of these is the application working: a wrong
    | password, a stale CSRF token, a 404, a rate limit, a plan that does not include the feature,
    | a team over its AI allowance. Reporting them trains an ops team to ignore the error tracker,
    | which is the one failure mode that makes every other alert useless.
    |
    | Note what is **not** here: no blanket `DomainException`. An AI transport failure and an
    | invalid provider response are domain exceptions too, and both are things we want to be told
    | about. Matching is by `instanceof`, so a subclass is covered — which is exactly why the
    | base class must not be listed.
    */
    'ignore_exceptions' => [
        AuthenticationException::class,
        AuthorizationException::class,
        ValidationException::class,
        TokenMismatchException::class,
        ThrottleRequestsException::class,
        NotFoundHttpException::class,
        MethodNotAllowedHttpException::class,
        CapabilityException::class,
        AiCreditExceededException::class,
        // A prompt over the size bound is a refusal, not a fault: the caller sent too much and the
        // 413 tells them so. Added in the review round — without it every oversized paste would be
        // reported as a bug in the AI platform.
        AiInputTooLargeException::class,
        // A Signals plan limit or allowance saying no (S16): this month's searches are gone, the
        // team is at its watch ceiling, their plan has no automations. Every one of them is the
        // application working, and the one that is scheduled rather than clicked — a watch run
        // refused for a spent allowance — would otherwise report itself once an hour per watch,
        // for every team on a ceiling, which is a tracker nobody reads. The refusal is still
        // recorded on the run and in the `signals` log, where the team and an operator can both
        // see it. Note it is the **subclass**, never `SignalsException`: a source returning
        // something unreadable is a domain exception too and we want to hear about that one.
        SignalAllowanceExceeded::class,
    ],

    // @see https://docs.sentry.io/concepts/key-terms/dsn-explainer/
    'dsn' => env('SENTRY_LARAVEL_DSN', env('SENTRY_DSN')),

    // @see https://spotlightjs.com/
    // 'spotlight' => env('SENTRY_SPOTLIGHT', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#logger
    // 'logger' => Sentry\Logger\DebugFileLogger::class, // By default this will log to `storage_path('logs/sentry.log')`

    // The release version of your application
    // Example with dynamic git hash: trim(exec('git --git-dir ' . base_path('.git') . ' log --pretty="%h" -n1 HEAD'))
    'release' => env('SENTRY_RELEASE'),

    // When left empty or `null` the Laravel environment will be used (usually discovered from `APP_ENV` in your `.env`)
    'environment' => env('SENTRY_ENVIRONMENT'),

    // Override the organization ID used for trace continuation checks.
    'org_id' => env('SENTRY_ORG_ID') === null ? null : (int) env('SENTRY_ORG_ID'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#sample_rate
    'sample_rate' => env('SENTRY_SAMPLE_RATE') === null ? 1.0 : (float) env('SENTRY_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#traces_sample_rate
    'traces_sample_rate' => env('SENTRY_TRACES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_TRACES_SAMPLE_RATE'),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#profiles_sample_rate
    'profiles_sample_rate' => env('SENTRY_PROFILES_SAMPLE_RATE') === null ? null : (float) env('SENTRY_PROFILES_SAMPLE_RATE'),

    // Only continue incoming traces when the organization IDs are compatible with this SDK instance.
    'strict_trace_continuation' => env('SENTRY_STRICT_TRACE_CONTINUATION', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_logs
    'enable_logs' => env('SENTRY_ENABLE_LOGS', false),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#enable_metrics
    'enable_metrics' => env('SENTRY_ENABLE_METRICS', true),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#log_flush_threshold
    'log_flush_threshold' => env('SENTRY_LOG_FLUSH_THRESHOLD') === null ? null : (int) env('SENTRY_LOG_FLUSH_THRESHOLD'),

    // The minimum log level that will be sent to Sentry as logs using the `sentry_logs` logging channel
    'logs_channel_level' => env('SENTRY_LOG_LEVEL', env('SENTRY_LOGS_LEVEL', env('LOG_LEVEL', 'debug'))),

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#send_default_pii
    'send_default_pii' => env('SENTRY_SEND_DEFAULT_PII', false),

    /*
    | **The request body is never collected** (Phase 20 security review).
    |
    | Not a hardening extra — it closes a real hole, and the reason it is worth the comment is that
    | `send_default_pii => false` above does **not** do this. That option gates the cookies, the
    | headers and the IP address. The *body* is governed by this option, whose SDK default is
    | `medium` — ten kilobytes of it, attached to every error event, with `data_collection` left at
    | `null` (legacy mode).
    |
    | In this product that body is the prospect record somebody was saving or the outreach copy they
    | were writing: `name`, `company_name`, `notes`, `body`. Those are not credential-shaped words,
    | so no deny-list was ever going to catch them — and they are third-party personal data this
    | company is a **processor** for (docs/compliance.md), which an error tracker is not in the
    | processing record for. `SentryOptions::scrubRequest()` also drops `data` if it is somehow
    | present, so changing this line alone cannot reopen it silently.
    */
    'max_request_body_size' => env('SENTRY_MAX_REQUEST_BODY_SIZE', 'none'),

    // Configures which data is collected automatically, `null` keeps collecting data based on `send_default_pii`
    'data_collection' => null,

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_exceptions
    // 'ignore_exceptions' => [],

    // @see: https://docs.sentry.io/platforms/php/guides/laravel/configuration/options/#ignore_transactions
    'ignore_transactions' => [
        // Ignore Laravel's default health URL
        '/up',
    ],

    // Breadcrumb specific configuration
    'breadcrumbs' => [
        // Capture Laravel logs as breadcrumbs
        'logs' => env('SENTRY_BREADCRUMBS_LOGS_ENABLED', true),

        // Capture Laravel cache events (hits, writes etc.) as breadcrumbs
        'cache' => env('SENTRY_BREADCRUMBS_CACHE_ENABLED', true),

        // Capture Livewire components like routes as breadcrumbs
        'livewire' => env('SENTRY_BREADCRUMBS_LIVEWIRE_ENABLED', true),

        // Capture SQL queries as breadcrumbs
        'sql_queries' => env('SENTRY_BREADCRUMBS_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query breadcrumbs, ignored when `data_collection` is set
        // This option will be migrated to `data_collection.database_query_data` in the next major version
        'sql_bindings' => env('SENTRY_BREADCRUMBS_SQL_BINDINGS_ENABLED', false),

        // Capture queue job information as breadcrumbs
        'queue_info' => env('SENTRY_BREADCRUMBS_QUEUE_INFO_ENABLED', true),

        // Capture command information as breadcrumbs
        'command_info' => env('SENTRY_BREADCRUMBS_COMMAND_JOBS_ENABLED', true),

        // Capture HTTP client request information as breadcrumbs
        'http_client_requests' => env('SENTRY_BREADCRUMBS_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture send notifications as breadcrumbs
        'notifications' => env('SENTRY_BREADCRUMBS_NOTIFICATIONS_ENABLED', true),
    ],

    // Performance monitoring specific configuration
    'tracing' => [
        // Trace queue jobs as their own transactions (this enables tracing for queue jobs)
        'queue_job_transactions' => env('SENTRY_TRACE_QUEUE_ENABLED', true),

        // Capture queue jobs as spans when executed on the sync driver
        'queue_jobs' => env('SENTRY_TRACE_QUEUE_JOBS_ENABLED', true),

        // Capture SQL queries as spans
        'sql_queries' => env('SENTRY_TRACE_SQL_QUERIES_ENABLED', true),

        // Capture SQL query bindings (parameters) in SQL query spans, ignored when `data_collection` is set
        // This option will be migrated to `data_collection.database_query_data` in the next major version
        'sql_bindings' => env('SENTRY_TRACE_SQL_BINDINGS_ENABLED', false),

        // Capture where the SQL query originated from on the SQL query spans
        'sql_origin' => env('SENTRY_TRACE_SQL_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for SQL queries to resolve their origin
        'sql_origin_threshold_ms' => env('SENTRY_TRACE_SQL_ORIGIN_THRESHOLD_MS', 100),

        // Capture views rendered as spans
        'views' => env('SENTRY_TRACE_VIEWS_ENABLED', true),

        // Capture Livewire components as spans
        'livewire' => env('SENTRY_TRACE_LIVEWIRE_ENABLED', true),

        // Capture HTTP client requests as spans
        'http_client_requests' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ENABLED', true),

        // Capture where the HTTP client request originated from on the HTTP client request spans
        'http_client_requests_origin' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_ENABLED', true),

        // Define a threshold in milliseconds for HTTP client requests to resolve their origin
        'http_client_requests_origin_threshold_ms' => env('SENTRY_TRACE_HTTP_CLIENT_REQUESTS_ORIGIN_THRESHOLD_MS', 250),

        // Capture Laravel cache events (hits, writes etc.) as spans
        'cache' => env('SENTRY_TRACE_CACHE_ENABLED', true),

        // Capture Redis operations as spans (this enables Redis events in Laravel)
        'redis_commands' => env('SENTRY_TRACE_REDIS_COMMANDS', false),

        // Capture where the Redis command originated from on the Redis command spans
        'redis_origin' => env('SENTRY_TRACE_REDIS_ORIGIN_ENABLED', true),

        // Capture send notifications as spans
        'notifications' => env('SENTRY_TRACE_NOTIFICATIONS_ENABLED', true),

        // Enable tracing for requests without a matching route (404's)
        'missing_routes' => env('SENTRY_TRACE_MISSING_ROUTES_ENABLED', false),

        // Configures if the performance trace should continue after the response has been sent to the user until the application terminates
        // This is required to capture any spans that are created after the response has been sent like queue jobs dispatched using `dispatch(...)->afterResponse()` for example
        'continue_after_response' => env('SENTRY_TRACE_CONTINUE_AFTER_RESPONSE', true),

        // Capture AI agent interactions as spans (requires laravel/ai)
        'gen_ai' => env('SENTRY_TRACE_GEN_AI_ENABLED', true),

        // Capture AI invoke_agent spans
        'gen_ai_invoke_agent' => env('SENTRY_TRACE_GEN_AI_INVOKE_AGENT_ENABLED', true),

        // Capture AI chat spans
        'gen_ai_chat' => env('SENTRY_TRACE_GEN_AI_CHAT_ENABLED', true),

        // Capture AI execute_tool spans
        'gen_ai_execute_tool' => env('SENTRY_TRACE_GEN_AI_EXECUTE_TOOL_ENABLED', true),

        // Capture AI embeddings spans
        'gen_ai_embeddings' => env('SENTRY_TRACE_GEN_AI_EMBEDDINGS_ENABLED', true),

        // Capture AI evaluate spans for classifications (requires laravel/ai 1.0+)
        'gen_ai_evaluate' => env('SENTRY_TRACE_GEN_AI_EVALUATE_ENABLED', true),

        // Enable the tracing integrations supplied by Sentry (recommended)
        'default_integrations' => env('SENTRY_TRACE_DEFAULT_INTEGRATIONS_ENABLED', true),
    ],

];
