# ADR 0013 — The `/api/v1` contract: one envelope, token scopes, and a generated specification

- **Status:** Accepted
- **Date:** 2026-10-31
- **Extends:** ADR 0001 (stack), ADR 0002 (module structure), ADR 0004 (Fortify + Sanctum), ADR 0007 (bilingual / RTL)
- **Supersedes nothing**

## Context

`/api/v1` was not built; it accumulated. Every phase from 1 to 16 added endpoints to its own
module's `routes/api.php` against a one-line rule in `CLAUDE.md` — versioned, Resources only,
consistent error envelope, pagination, per-token rate limiting — and each phase honoured the parts
of it that its own endpoints needed. Phase 17 found what that leaves.

- The **error envelope** was the contract for `DomainException` and nothing else. A failed
  validation came back as Laravel's `{message, errors}`, a 401/404/405/429 as a bare `{message}`.
  Three shapes, and which one a client got depended on which layer refused — on the error path,
  where a client can least afford to guess.
- **Token abilities** had been stored on every token since Phase 1 and never checked. Every token
  was effectively `*`, so "scope this integration to read-only" was a promise the API could not
  keep.
- **`per_page`** meant three different things, and two lists that only grow returned whole.
- The **rate limiter** keyed on the user, despite the comment saying token.
- There was **no specification**, no version policy beyond the path, and no API types the mobile app
  (Phase 18) could import.

Six questions had non-obvious answers, each expensive to revisit once a mobile build in the field
depends on it.

### 1. What is the error envelope's scope — the path, or the `Accept` header?

`{error: {code, message, details?}}` for every failure is the easy half. The hard half is _which
requests_. `$request->expectsJson()` is the idiomatic test and it is wrong here: Inertia requests
also expect JSON, and every form in the web app reads its error bag from Laravel's native 422.

### 2. Where does a token scope get enforced — a policy, or middleware?

The repo's rule is that authorisation lives in policies. But a scope is not an authorisation
question. Authorisation asks whether _this member_ may act; a scope asks what _this credential_ may
do. The same member, on the same team, with two different tokens, must get two different answers —
which a policy, which only sees the user, cannot give.

### 3. What happens to a first-party session caller on `/api/v1`?

Sanctum's own `CheckForAnyAbility` refuses one: it demands a stored token. But the web app reaches
these endpoints on a session cookie, and the web app has no notion of a scope at all.

### 4. Is the specification written, or generated?

A hand-written OpenAPI document is a second description of the API that drifts from the first the
day after it is written, and nothing reports it.

### 5. Are the shared TypeScript types generated from the specification, or moved?

`resources/js/types/*` already mirrored every API Resource — 1,700 lines of hand-written types the
web app has been reading since Phase 2. Generating a second set from the specification would make
them two sets.

### 6. What does a new API version actually mean?

Without an answer, "we'll version it" means either every change waits for v2 or nothing does.

## Decision

- **One envelope, scoped by path.** `Core\Http\Responses\ApiError` is the single definition, and
  both callers go through it: `DomainException::render()` and `Core\Exceptions\ApiExceptionRenderer`
  (registered in `bootstrap/app.php` for everything the framework raises). The predicate is
  `$request->is('api/*')` — **never** `expectsJson()`, for the reason in §1. `code` is the contract
  and is safe to branch on; `message` is prose and may be translated; `details` carries only what is
  structured (field errors keyed by input name, the abilities a token was missing). A module adds a
  failure mode by extending `DomainException` with its own namespaced code, never by inventing a
  response shape.
    - `ApiError` also stamps `X-Api-Version`, because an exception never returns through the
      middleware that would have, and an error response is the one a client most needs it on.

- **Scopes are middleware, not policies** (`Auth\Http\Middleware\EnsureTokenAbility`, aliased
  `ability`), declared per route and defined coarsely in `Auth\Enums\TokenAbility` — one read/write
  pair per resource group. Coarse on purpose: a scope per endpoint is unusable in a picker and makes
  every new endpoint a breaking change for existing tokens, where a scope per resource group is the
  unit a person can reason about ("let it read my leads, not send my email").
    - The decision is the **credential's**, through `tokenCan()`, which answers correctly for all
      three things Sanctum hands over: a stored token's own abilities decide, a session's
      `TransientToken` passes, and no credential at all is refused. Reading the bearer _header_
      instead — an earlier cut — puts the fail-open case on the common path and makes any scope
      exercised through `Sanctum::actingAs()` pass untested.
    - **A first-party session is let through unscoped** (§3). A browser session is already trusted at
      the full trust level of an app that has no scopes; refusing it would make the same member less
      trusted through `/api/v1` than through a page. What a session may do is still decided by the
      policies the controllers call.
    - A refusal is **403** with the abilities that would have sufficed in `details`, never 401:
      re-authenticating cannot widen a scope, and sending a client to sign in again for it is a loop
      it never leaves.
    - **Two routes carry no scope**: identity (`GET user`) and a token retiring _itself_
      (`DELETE auth/token`). A credential that cannot say who it belongs to, or stand itself down
      when a handset is wiped, is one nobody can clean up. Listing or revoking the account's _other_
      tokens is a different power and needs `tokens:manage`.
    - **Asking for a scope and naming none is a 422.** An absent `abilities` key means "no scope
      asked for" and issues `*`; an empty array means a caller that meant to limit the token and
      named nothing. Collapsing the two hands full access to exactly the caller trying to avoid it.

- **The specification is generated and committed** — `docs/api/openapi.json`, from the routes,
  FormRequests and Resources themselves (dedoc/scramble, `composer api:docs`). Scramble is a **dev**
  dependency: the document is a build artefact, so production has neither the generator nor the
  `/docs/api` route, and `config/scramble.php` therefore references no Scramble class, because every
  config file is loaded on boot and a class constant from an uninstalled package is a fatal error
  rather than a missing feature. `Core\Support\ApiDocs\DocumentsApiContract` writes in the two things
  the generator cannot see: the error envelope, which lives in middleware rather than in any return
  type, and a relative server URL, so a committed file does not carry whichever machine generated it.

- **The shared TypeScript types moved, they are not generated** (§5). The payload types are now
  `resources/js/shared/types/`, re-exported by `@/types` so no web import changed, and the mobile app
  imports the same ones. They are kept by hand because they carry what a specification cannot
  express — which `label` fields are English **translation keys** (ADR 0007), which unions are
  discriminated — and because two sets drift. `resources/js/shared/api/` adds beside them what types
  alone cannot: the envelope and pagination shapes, and a `fetch` client that sends the token, pins
  the version, reads the envelope instead of guessing, and surfaces a 429 as something a caller can
  wait on.

- **The version is one number in the path, and it changes only for a break.** A field removed or
  renamed, a parameter narrowed, a status code changed. Everything additive — a new endpoint, a new
  field, a new enum case, a new optional filter, a new error code — ships inside the current version,
  so a mobile build from six months ago keeps working. That holds only if clients tolerate fields and
  enum cases they do not recognise, which is the one obligation placed on them in return. A client
  may pin itself with `X-Api-Version`, and a mismatch is a 400 rather than a best-effort answer from
  the wrong contract.

- **The audit is a test.** `tests/Feature/Api/V1/RouteContractTest.php` asserts over the whole route
  table that every endpoint is versioned, named, behind Sanctum, throttled, version-stamped, scoped
  with an ability the enum defines, handled by a controller rather than a closure, and present in the
  committed specification. A per-endpoint test only ever covers the endpoints somebody remembered to
  write one for, which is how the four gaps above opened in the first place.

## Consequences

- **The error envelope is a visible break for any existing API client.** Nothing has shipped, so
  there is nothing in the field; after Phase 21 a change of this shape needs a new version.
  `assertJsonValidationErrors()` no longer works against `/api/v1` — `assertApiValidationErrors()`
  does, and the ten tests that asserted the old shape were moved to it.
- **A token's scope cannot be edited after issue** (Sanctum stores abilities on the row). Narrowing
  one means revoking it and minting another.
- **Abilities are validated at issue time only.** A token already carrying something outside the
  catalogue would be refused everywhere, and a future change to the catalogue therefore needs a
  migration or a grace rule, not just an enum edit.
- **The rate limit is now two limits** — 60/minute per token and 180/minute per user — because a
  per-token limit alone is one anybody can multiply by minting another token.
- **`routes/api.php` is deliberately empty**, which looks like an oversight until read: every route
  belongs to the module that owns the domain, and the starter kit's `GET /api/user` closure was
  removed because it was unversioned, outside the envelope, outside the scopes, and returned a raw
  model.
- **The specification can lag the routes between regenerations**, which is why the route test fails
  when it does. `composer api:docs` is a step in any change to an endpoint.
- **Scramble documents a Spatie Data response only as an object**, so `GET reports/dashboard` is
  under-described; its shape is `Reporting\Data\DashboardReport`, mirrored in
  `resources/js/shared/types/reporting.ts`. Scramble PRO would document it, which is a purchase
  decision, not an architectural one.
- **The settings screens, including the new scope picker, are hard-coded English.** Consistent with
  the rest of settings, inconsistent with ADR 0007, and a settings-wide translation pass of its own —
  which would want the `TokenAbility` labels translated server-side at the same time.
