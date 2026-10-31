# The Alima API (`/api/v1`)

The contract the mobile app (Phase 18) and any customer integration are written against. This file
is the prose; **`openapi.json` beside it is the contract** — generated from the routes, FormRequests
and API Resources themselves, so it cannot describe an endpoint the code does not have.

|                     |                                                                                                       |
| ------------------- | ----------------------------------------------------------------------------------------------------- |
| Base                | `https://<host>/api/v1`                                                                               |
| Specification       | [`openapi.json`](openapi.json) (OpenAPI 3.1)                                                          |
| Browsable docs      | `/docs/api` — **local only** (`RestrictedDocsAccess`; the generator isn't installed in production)    |
| Regenerate          | `composer api:docs`                                                                                   |
| Shared client types | `resources/js/shared/api/` (envelope + typed `fetch` client), `resources/js/shared/types/` (payloads) |

---

## 1. The rules that hold everywhere

Phase 17 existed to make these true of _every_ endpoint rather than most of them. Each is enforced
by a test over the whole route table in `tests/Feature/Api/V1/RouteContractTest.php`, not endpoint
by endpoint — a per-endpoint test only covers the endpoints somebody remembered to write one for.

1. **Everything is versioned.** There is no unversioned surface. `routes/api.php` is empty on
   purpose; every route is declared by the module that owns the domain
   (`app/Modules/<Module>/routes/api.php`) and mounted under `/api/v1` with the `api.v1.` name
   prefix by `App\Modules\ModuleServiceProvider`.
2. **Everything is behind Sanctum** except `POST /auth/token`, which is how you get a credential.
3. **Everything declares a token scope**, except identity and a token's own lifecycle (§3).
4. **Everything answers with an API Resource**, never a model. No endpoint hands back a raw record,
   which is how fields like `raw`, `field_sources` and `team_id` stay off the wire.
5. **Everything fails in one shape** (§4).
6. **Everything is throttled** (§6) and stamped with the version that served it (§2).

## 2. Versioning

The version is in the path, it is one number, and `X-Api-Version` on every response says which one
answered — including on an error, which is when a client needs it most.

**What a new version is for.** Only a change that would break a client already in the field: a field
removed or renamed, a parameter narrowed, a status code changed, a resource restructured.

**What ships inside the current version.** Everything additive: a new endpoint, a new field on a
resource, a new enum case, a new optional filter, a new error code. A mobile build from six months
ago keeps working — but only if clients tolerate fields and enum cases they do not recognise, which
is the one thing asked of them in return.

A client may **pin** itself by sending `X-Api-Version: 1` (or `v1`). A mismatch is a `400` with code
`unsupported_api_version` rather than a best-effort answer, because a client that asked for v2 and
silently got v1 would misread everything it then parsed.

## 3. Authentication and token scopes

```http
POST /api/v1/auth/token
{ "email": "…", "password": "…", "device_name": "Nadia's iPhone", "abilities": ["leads:read"] }
→ 201 { "token": "…", "user": { … } }
```

Then `Authorization: Bearer <token>` on everything. Omit `abilities` for a full-access (`*`) token.
A first-party browser **session** is also accepted — that is how the web app's own screens reach
these endpoints — and carries no scopes, because the app it stands in for has none.

Scopes are one read/write pair per resource group, defined in `App\Modules\Auth\Enums\TokenAbility`
and enforced per route by `EnsureTokenAbility`:

`leads:*` · `discovery:*` · `icp:*` · `scoring:*` · `outreach:*` · `signals:*` · `insights:*` ·
`notifications:*` · `reports:read` · `team:*`

A token without the ability gets `403 insufficient_token_ability`, with what would have been enough
in `error.details.required_abilities` — so a client can say "reissue this token with `leads:write`"
instead of sending someone round the sign-in loop for a problem signing in cannot fix.

**Two routes carry no scope, deliberately**, and the line is narrow. `GET /user` and
`DELETE /auth/token` — identity, and a token retiring **itself**: a credential that cannot say who it
belongs to, or stand itself down when a handset is wiped, is one nobody can clean up, and
withholding that scopes away the safety mechanism rather than any data. Listing the account's
**other** tokens and revoking **them** is a different power — it would let a token issued to read
leads enumerate every integration on the account and switch them all off — so `GET /auth/tokens` and
`DELETE /auth/tokens/{id}` need `tokens:manage`.

**Asking for a scope and naming none is an error, not a shortcut.** Omitting `abilities` issues a
full-access token; sending `"abilities": []` is a `422`. The two requests mean opposite things, and
collapsing them would hand full access to the caller who was trying to avoid it.

Tokens are minted and revoked from **Settings → API tokens** in the web app, where the scope picker
is built from the same enum.

## 4. Errors

One envelope, whatever refused — the authenticator, a policy, the router, the validator, the
throttle, a module's own rule, or an unhandled fault:

```json
{
    "error": {
        "code": "validation_failed",
        "message": "Some of those details need another look.",
        "details": { "company_name": ["The company name field is required."] }
    }
}
```

- **`code`** is the contract. Branch on it. Framework-level codes are listed in
  `App\Modules\Core\Enums\ApiErrorCode`; a module raises its own, namespaced (`outreach.suppressed`,
  `team.last_owner`). Treat an unknown code as "something else went wrong" — the list is not closed,
  and a new code is an additive change.
- **`message`** is prose for a person. Translated (`Accept-Language: en|ar`) and free to be reworded
  within a version. Never branch on it.
- **`details`** appears only when there is something structured to say: field errors keyed by input
  name on `validation_failed`, `required_abilities` on `insufficient_token_ability`,
  `requested`/`supported` on `unsupported_api_version`.

Two conventions worth knowing:

- **A record on another team is `404`, never `403`.** Whether it exists is itself not yours to know.
  A `403` means "you, on this team, may not do this" — a different answer, and a different fix.
- **The envelope is scoped to `api/*` by path, not by `Accept`.** Inertia requests also expect JSON
  and every form in the web app reads its error bag from Laravel's native `{message, errors}`;
  widening the envelope to all JSON would break every one of them silently.

## 5. Pagination

A list that can grow is paged and returns `data`, `links` and `meta`; `per_page` is accepted up to
**100** (`Core\Http\Requests\PaginatedIndexRequest::MAX_PER_PAGE`, with tighter per-endpoint
ceilings where an endpoint is expensive). Without a ceiling, `per_page` is a way to ask for the
whole table in one query — an export nobody authorised, at the database's expense.

A handful of lists are **bounded by a business rule** and answer with `data` alone, no page: a
member's teams, tokens and push devices; a team's pipeline stages and ICPs. The specification says
which is which, and `tests/Feature/Api/V1/VersionAndPaginationTest.php` pins the list down.

## 6. Rate limits

- **60 requests a minute per token** (per user on a session, per IP before sign-in), **and 180 a
  minute per user** over the top. Per _token_ because a member's phone and a long-running
  integration are different callers, and a script's retry loop should not take the app down for the
  person; per _user_ as well because anyone can mint another token from the settings screen, and a
  per-token limit alone is one anybody can multiply.
- **`POST /auth/token`: 5 a minute** per email + IP.
- Tighter limits on the endpoints that spend money: `outreach-draft`, `outreach-send`, `scoring`,
  `signals-run`, `reports-export`, `leads-bulk`, `compliance-export`.

A `429` carries `Retry-After`. Plan allowances are a separate matter and are enforced in the guards,
not here (ADR 0012): exhausting a team's AI credit or lead allowance is a domain error with its own
code, not a throttle.

## 7. Conventions

- **Tenancy.** Every response is scoped to the caller's current team; `POST /teams/{team}/switch`
  changes it. Nothing is addressable across teams.
- **Money** is integer minor units with its currency beside it. **Timestamps** are UTC ISO-8601; a
  team's own clock is in its `timezone`.
- **Enums** travel as their stable storage key, usually with a `*_label` beside it for display.
  Branch on the key.
- **Locale.** `Accept-Language: en` or `ar` picks the language of prose and of labels.
- **Writes are idempotent where it matters.** `POST /leads` answers `201` for a new lead and `200`
  when the details were folded into one the team already had; `POST /push-devices` answers `201` on
  a first registration and `200` on the re-registration a phone does at every launch.

## 8. Regenerating the specification

```bash
composer api:docs      # artisan scramble:export → docs/api/openapi.json
```

Commit the result. `RouteContractTest` fails if a route is missing from the committed document — a
specification that silently lags the routes is worse than none, because a client generated from it
is wrong in a way nothing reports.

Two things the generator cannot see, and what covers them instead:

- **The error envelope** lives in middleware and a renderer, not in any return type, so
  `Core\Support\ApiDocs\DocumentsApiContract` writes it into the document — the `ApiError` schema
  plus the `401`/`403`/`404`/`422`/`429` responses every operation can give.
- **`GET /reports/dashboard`** returns a Spatie Data object, which the free generator records only
  as an object. Its shape is `App\Modules\Reporting\Data\DashboardReport`, mirrored in TypeScript at
  `resources/js/shared/types/reporting.ts`.

Scramble is a **dev dependency**: the document is a build artefact, so production has neither the
generator nor the `/docs/api` route. `config/scramble.php` therefore references no Scramble class —
every config file is loaded on boot, and a class constant from a package that isn't installed is a
fatal error rather than a missing feature.

## 9. Review (2026-10-31)

Reviewed at the end of Phase 17 by `laravel-module-reviewer` (backend) and `brand-reviewer` (the
token settings screen). Fixed from their findings:

- **`HttpResponseException` on `api/*` became a 500.** The renderer's `match` had no arm for it, and
  it is not an `HttpExceptionInterface`, so an exception carrying its own finished response — an
  `abort($response)`, a FormRequest overriding `failedValidation`, a vendor package — had that
  response thrown away. Latent (nothing in `app/` throws one today), now covered by a test that
  throws one on an `api/v1` route.
- **Any unmapped status answered `server_error`.** A 415 or a 419 told the client the fault was ours
  and the request was worth retrying, which is the one conclusion it must not draw. `fromStatus()`
  now maps 400, 410 and 415 by name and falls back by status class.
- **A 5xx message reached the client verbatim**, debug or not: `abort(503, 'redis pool exhausted')`
  is for the logs, not for a prospect's phone. 5xx now answers with the code's own prose.
- **The scope check read the bearer header** and treated its absence as first-party. There was no
  live bypass, but it put the fail-open case on the common path, and it made any scope exercised
  through `Sanctum::actingAs()` — which sets a token and sends no header — pass without being
  checked. It now asks the credential (`tokenCan()`), which answers correctly for all three things
  Sanctum hands over: a stored token's abilities decide, a session's `TransientToken` passes, and no
  credential is refused.
- **`tokens:manage`** was added for the two routes above, which had been left unscoped along with
  identity.
- **The per-token rate limit was a limit anyone could multiply** by minting another token. A
  per-user ceiling now sits over it.
- **The scope picker could issue the opposite of what it was asked for.** With the limit on and
  nothing ticked, the form posted no `abilities[]` at all, which the server reads as "no scope
  asked for" — a full-access token. Closed on both sides: the submit button is disabled in that
  state, and an explicitly empty `abilities` array is now a `422`.
- **The picker's layout and tab order.** `Label` is a flex row, so each scope string sat _beside_
  its label rather than under it; and the Create button came before the fieldset in the DOM, so a
  keyboard user met it before seeing the choice. Also: the hint is now tied to its checkbox with
  `aria-describedby`, the group headings use the app's own `tracking-eyebrow` idiom with
  `rtl:tracking-normal`, and every mono scope string is `dir="ltr"` — `leads:read` is an identifier
  and must not reorder under Arabic.

Accepted as-is, with reasons:

- **A token's abilities cannot be edited after issue** (Sanctum stores them on the row): narrowing
  one means revoking and minting another.
- **A token row with `NULL` abilities would raise a `TypeError` inside Sanctum's `can()`.** The only
  writer is `IssueApiTokenAction`, which always stores at least `['*']`, so this needs a
  hand-written row; and the failure is a logged 500 with a generic message, not a bypass.
- **Existing tokens carrying abilities outside the catalogue** would now be refused everywhere. The
  validation is at issue time only, and nothing has shipped, so there is nothing in the field to
  break — noted because that stops being true after Phase 21.
- **The settings screens are hard-coded English**, this one included. That is consistent with
  Profile, Security, Team and Appearance; only `settings/Notifications.vue` uses `useI18n`. A
  settings-wide translation pass is a job of its own (and would want the `TokenAbility` labels
  translated server-side at the same time), not a Phase 17 change that would make one screen the
  odd one out.
- **`Checkbox`'s focus ring is `ring-ring/50`**, under the 3:1 the brand intends. Pre-existing in the
  base component; a design-system follow-up, not this diff's to fix.
