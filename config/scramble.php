<?php

declare(strict_types=1);

/*
 * OpenAPI generation for `/api/v1` (Phase 17), via dedoc/scramble.
 *
 * Scramble is a **dev dependency**: the specification is generated here and committed to
 * `docs/api/openapi.json`, which is what CI, the shared TypeScript types and any client read.
 * Nothing in production needs the generator, so nothing in production has it — which is why this
 * file deliberately references **no Scramble class**: every config file is loaded on boot, and a
 * class constant from a package that isn't installed is a fatal error, not a missing feature. The
 * middleware and strategy are class-strings for the same reason, and `openapi_version` is simply
 * left to Scramble's own default.
 *
 * Regenerate with `composer api:docs` (export + the TypeScript types in one step).
 */

return [
    /*
     * One static include: the server becomes `/api/v1` and documented paths are relative to it
     * (`/leads`, not `/api/v1/leads`). The API has no unversioned surface - see routes/api.php.
     */
    'api_path' => 'api/v1',

    'api_domain' => null,

    /*
     * Committed, and read by the TS type generator and the docs test.
     */
    'export_path' => 'docs/api/openapi.json',

    'cache' => [
        'key' => 'scramble.openapi',
        'store' => 'file',
    ],

    'info' => [
        'version' => '1.0.0',

        'description' => <<<'MARKDOWN'
        The Alima API. One version, in the path, and everything below holds across all of it.

        **Authentication.** A Sanctum personal access token as `Authorization: Bearer <token>`.
        `POST /auth/token` exchanges email + password for one (hard-throttled). A first-party
        browser session is also accepted, which is how the web app's own screens reach these
        endpoints.

        **Token scopes.** Every endpoint but identity and a token's own lifecycle requires an
        ability — `leads:read`, `outreach:write`, and so on, one read/write pair per resource
        group. A token without the ability gets `403` with code `insufficient_token_ability` and
        the abilities it needed in `error.details.required_abilities`. A token issued with `*` can
        do everything.

        **Tenancy.** Every response is scoped to the caller's current team. `POST /teams/{team}/switch`
        changes it. Nothing is addressable across teams: a record belonging to another team answers
        `404`, never `403`, because the existence of the record is itself not yours to know.

        **Errors.** Every failure — validation, authorisation, a missing record, a throttle, an
        unhandled fault — answers with the same envelope: `{"error": {"code", "message", "details"?}}`.
        `code` is the contract and is safe to branch on. `message` is prose for a person and may be
        translated or reworded. `details` appears only when there is something structured to say:
        field errors keyed by input name on a `422`, `required_abilities` on a scope failure.

        **Pagination.** List endpoints that can grow return `data`, `links` and `meta`; `per_page`
        is accepted up to 100. The handful of bounded lists (a member's teams, their tokens and
        devices, a team's pipeline stages and ICPs) return `data` alone.

        **Versioning.** Responses carry `X-Api-Version`. The version changes only for a change that
        would break a client already in the field: a field removed or renamed, a parameter narrowed,
        a status code changed. Everything additive — a new endpoint, a new field, a new enum case, a
        new optional filter — ships inside the current version, so last season's mobile build keeps
        working. That holds only if clients tolerate fields they do not recognise, which is the one
        thing asked in return. A client may pin itself by sending `X-Api-Version`; a mismatch is a
        `400` rather than a best-effort answer from the wrong contract.

        **Rate limits.** 60 requests a minute per token (per IP before sign-in), and a few
        endpoints that spend money — drafting, sending, re-scoring, running a signal watch — carry
        tighter limits of their own. A `429` carries `Retry-After`.

        **Money and time.** Money is integer minor units with its currency. Timestamps are UTC
        ISO-8601; a team's own clock is in its `timezone`.
        MARKDOWN,
    ],

    'ui' => [
        'title' => 'Alima API v1',
    ],

    'dev_tools' => [
        'enabled' => env('SCRAMBLE_DEV_TOOLS', env('APP_DEBUG', false)),
    ],

    'renderer' => 'elements',

    'renderers' => [
        'elements' => [
            'view' => 'scramble::docs',
            'theme' => 'light',
            'hideTryIt' => false,
            'hideSchemas' => false,
            'logo' => '',
            'tryItCredentialsPolicy' => 'include',
            'layout' => 'responsive',
            'router' => 'hash',
        ],
    ],

    'servers' => null,

    'enum_cases_description_strategy' => 'description',

    'enum_cases_names_strategy' => false,

    'flatten_deep_query_parameters' => true,

    /*
     * The docs UI (`/docs/api`) is local-only: RestrictedDocsAccess lets `local` through and
     * otherwise requires the `viewApiDocs` gate, which nothing grants. The generator isn't
     * installed in production anyway, so the route does not exist there.
     */
    'middleware' => [
        'web',
        'Dedoc\Scramble\Http\Middleware\RestrictedDocsAccess',
    ],

    'extensions' => [],

    /*
     * Bearer auth is documented wherever a route is behind `auth:*` (every route but the token
     * exchange, which is marked public).
     */
    'security_strategy' => 'Dedoc\Scramble\SecurityDocumentation\MiddlewareAuthSecurityStrategy',
];
