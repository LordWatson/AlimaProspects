# ADR 0017 — Observability and security posture

- **Status:** Accepted
- **Date:** 2026-11-01
- **Relates to:** [ADR 0001](0001-frontend-and-app-stack.md) (the stack, which named Sentry and Pulse), [ADR 0007](0007-markets-and-compliance.md) (UK GDPR / UAE PDPL, and therefore what may leave the building), [ADR 0013](0013-api-v1-contract.md) (token scopes), [ADR 0014](0014-admin-panel-bespoke-inertia.md) (the operator identity these dashboards borrow)

## Context

Phase 20 is the phase where a product that works starts being a product that can be run and defended. Nineteen phases produced eighteen modules, a queue, five external providers, a bespoke operator panel, a mobile app and an `/api/v1` surface — and almost none of it was watched. `sentry/sentry-laravel` and `laravel/pulse` had been in `composer.json` since Phase 0 and neither was wired: Sentry had a published config file and no `Integration::handles()`, so an unhandled exception went to a log file on one box; Pulse had a published config and a gate reading `in_array(optional($user)->email, [])`.

Four things made the shape of this phase, and each one is why a decision below is not the obvious one.

**An error tracker is a data processor.** This product holds third-party personal data — a prospect's name, their work email address, their company — that a customer is the controller of and we are the processor for (`docs/compliance.md`). An error report is, by default, a request body, a set of cookies, an IP address and whatever somebody put in a `Log::error` context three modules away. Wiring Sentry naively does not add observability to a compliant product; it adds an unassessed sub-processor holding prospect data in a region nobody chose. The default configuration is the problem, not the vendor.

**A dashboard that shows every tenant is not a lesser surface than `/admin`.** Pulse's slow-query card shows SQL. Its exception card shows messages. Its queue card shows job classes. None of that is scoped to a team, and all of it is about customers. Phase 19a spent a whole phase deciding who may read one tenant's data and how that is audited; `/pulse` shipped with a published gate that would have been filled in by hand with somebody's email address.

**A Content-Security-Policy is the one control that is free here and expensive later.** This app has no third-party widget, no tag manager and no inline `onclick` anywhere: exactly two inline tags in `app.blade.php`, both ours. A strict nonce policy is therefore affordable **today** and becomes unaffordable the moment somebody adds an analytics snippet that needs `unsafe-inline`. The window for deciding this is before launch, not after.

**And the AI surface had a bound on the wrong thing.** Phase 8 capped the _fetcher_ (`WEB_FETCH_MAX_BYTES`, `WEB_FETCH_MAX_CHARS`), which bounds one input. Nothing bounded the **assembled prompt**, which is built from several of them plus free text a member typed. A prompt near the model's context limit is slow, is charged to the team's allowance whatever comes back, and is the shape a prompt-injection attempt takes when the aim is to push the system instructions out of the model's attention.

## Decision

### 1. Error reporting sends an id, a tenant and a stack trace — and nothing about a person

Three Sentry projects (backend, browser, mobile), because a 500 in a queue worker, a `TypeError` in a browser and a crash on a three-year-old handset are triaged by different people with different questions, and one project makes none of the three release lists readable.

What leaves the building is bounded in four places, deliberately overlapping:

| Layer                             | What it does                                                                                                                                                                                  |
| --------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `send_default_pii => false`       | Stops the SDK collecting the **cookies, the request headers and the IP address**. Nothing else — see the correction below.                                                                    |
| `max_request_body_size => 'none'` | Stops it collecting the **request body**, whose SDK default is ten kilobytes on every error event.                                                                                            |
| `SentryOptions::scrubRequest()`   | **By name**: redacts the query string, strips the query off the URL, and drops the cookies and body if anything set them anyway.                                                              |
| `SentryOptions::beforeSend()`     | **By key**: redacts the value of every key that looks like a credential or personal data, at any depth, in request data, extra context, contexts, tags, the user bag and breadcrumb metadata. |
| `shared/observability/sentry.ts`  | The same two rules in the browser, including the query string dropped from the URL and from every breadcrumb — a search term in this product is somebody's company name.                      |
| The Sentry project's own settings | Out of this repository's scope, and noted in the runbook as the belt to this set of braces.                                                                                                   |

The key list lives in `config/observability.php`, because what counts as sensitive is a compliance decision rather than a code one. It is **substring, case-insensitive, with a short allowlist** — `input_tokens` is a measurement, not a credential, and redacting it would cost real debugging for no privacy gain.

**A key-based scrubber is not enough on its own, and the first version of this decision got that wrong.** It said `send_default_pii => false` meant the SDK was not _trying_ to send a body, a cookie or a URL, with the key list as a second line over the top. The security review at the end of this phase disproved it against the installed SDK: `send_default_pii` gates the cookies, the headers and the IP, and **not** the URL, the query string, or — with `data_collection` at its default `null` — the request body. So a 500 on a signed invitation link would have shipped `GET /invitations/<secret-token>?expires=…&signature=…`, a replayable credential, to a third party; and a failed save would have shipped the prospect record, because `name`, `company_name` and `notes` are not credential-shaped words and were never going to be on a deny-list.

Hence the two mechanisms in the table rather than one. The general lesson is worth keeping: **a deny-list works where the key names the value's sensitivity, and fails where it does not.** `password` says what it holds; `data` does not.

Even so, the key list is the layer that matters most for our own code, because the realistic leak is a `Log::error('...', ['payload' => $request->all()])` three modules away becoming a breadcrumb. **The scrubber is aimed at our own code; the two SDK options are aimed at the vendor's.**

Two further refusals:

- **Session replay is off**, and not merely unconfigured. It records the screen of somebody working a list of named prospects, and a debugging convenience is not a lawful basis. The sample rates are plumbed through so turning it on is a deliberate act with the privacy notice open.
- **The browser SDK's `BrowserSession` and `Console` integrations are removed.** A session envelope is the one thing the browser SDK sends with an _inferred IP address_, and `beforeSend` never sees it because it is not an event. Console breadcrumbs are free text, and a key-based scrubber cannot inspect a sentence. Both were sending something no scrubber could clean up.

The actor is attached as an **id**. Not a name, not an email address — and the scrubber nulls the username, email and IP on the user bag regardless of what put them there, so the claim is enforced rather than merely intended.

**Expected refusals are not reported**, declaratively, through `ignore_exceptions`: a wrong password, a stale CSRF token, a 404, a rate limit, a plan that does not include the feature, a team over its AI allowance. Reporting them trains an ops team to ignore the error tracker, which is the one failure mode that makes every other alert useless. The base `DomainException` is deliberately **not** on that list — an AI transport failure is a domain exception too, and it is something we want to be told about.

#### The browser DSN is served, not built in

`HandleInertiaRequests` shares it as a prop; the bundle contains no DSN. One `.env` value therefore configures staging and production with no rebuild, and — the reason that matters more — the **CSP derives the Sentry ingest origin from the same config value**, so the policy cannot allow a host the SDK does not post to, or forbid one it does. A DSN authorises _writing_ an event to a project and nothing else; the token that would read a project is not in this application.

### 2. The Content-Security-Policy is nonce-based, and is skipped in exactly two places

`Core\Http\Middleware\SecurityHeaders` is **global** middleware, not on the web or API group: the headers belong to the HTTP response, so `/up`, a CSV download, a 500 page, the Pulse dashboard and an `/api/v1` error envelope all carry them. Registering it on the groups instead is how the one route nobody thought about becomes the one route without a header.

`Core\Support\Security\ContentSecurityPolicy` builds the policy from `config/security.php`. `script-src` is `'self'` plus a per-request nonce — the one Laravel's Vite helper already mints — and the two inline tags in `app.blade.php` carry it. There is no `'unsafe-inline'` on scripts and no `'unsafe-eval'` anywhere.

Two directives are looser, and both are stated rather than discovered:

- **`style-src-attr 'unsafe-inline'`.** Reka UI, vue-sonner and the Inertia progress bar all set `element.style`. A style _attribute_ is defacement, not execution; the `<style>` _element_ stays nonce-gated.
- **`img-src data: blob:`.** The logo is an inline SVG data URI in places, exports are handed over as blobs, and the 2FA QR code is a data URI. None is a script source.

The two skips:

- **While the Vite dev server is running.** A nonce policy holds only if _every_ inline script and style carries the nonce, and under `npm run dev` Vue injects component styles from JavaScript with none. A policy that stayed on locally would hold _by breaking the page_, which teaches a developer to switch it off. It is on for every built asset path — which is every environment that is not somebody's laptop mid-edit.
- **On `pulse` and `horizon`.** Both inject their own inline Livewire and Alpine bootstrapping we neither control nor can nonce. A policy that breaks the tool an operator opens at 2am is worse than no policy on a route already behind the platform-admin door, enforced 2FA and the optional IP allowlist. It is a **config list** (`security.csp.except`) so the exemption is visible rather than implied.

`report_only` exists so a tightened policy can be rolled out by reading violations rather than by breaking pages, and the posture check warns when production is still in that state — because nobody notices on their own.

**HSTS follows the connection, not the environment.** Over plain HTTP a browser ignores it; from a local `.test` domain it pins that host to HTTPS in the developer's browser for a year. So the condition is `$request->secure()` — true on staging and production, false on a laptop — rather than `isProduction()`, which would have skipped a staging box that is just as public. `preload` is off: it is a one-way door whose removal takes months, and it belongs to the Phase 21 deployment decision.

### 3. Pulse and Horizon go behind the admin panel's own door

`config/pulse.php` puts `EnsurePlatformAdmin` (**404**, not 403, for anybody who is not an operator), `EnsureAdminTwoFactor` and `AdminIpAllowlist` in front of Pulse's own `Authorize`, and `viewPulse` maps to `AdminAbility::ManageOperations` — the 2am incident ability that already lets the same person retry a failed job, not `platform.access` and not the Stripe switch. `viewHorizon` answers identically, and `HorizonServiceProvider::gate()` is emptied so there is **one** definition of who may read every tenant's queue.

Pulse and the panel answer different questions, and keeping both is the decision rather than an accident:

| Surface                   | Question                                                                | Shape                                              |
| ------------------------- | ----------------------------------------------------------------------- | -------------------------------------------------- |
| `/admin/operations` (19c) | _Which tenant's_ job failed, what did this customer cost, who do I ring | Tenant-aware, audited, authoritative               |
| `/pulse`                  | Is something happening **right now**, across everything                 | Per-minute, sampled, trimmed after 7 days, no team |

The **AI usage card** is ours, because nothing in Pulse knows what a prompt key or a cost micro is. It is keyed by **prompt key, not by team**: the useful question on a dashboard with no tenant scope is which prompt is burning money or breaking, and per-team cost already has a home behind `AdminAbility::ReadPlatformReports`. The card says on its face that it is not the billing figure — it samples, it trims, and `PULSE_ENABLED=false` makes it disappear.

It is fed by an **event**, `AiPlatform\Events\AiRequestRecorded`, carrying scalars rather than the model. AI Platform raises it; a Pulse recorder listens. Calling `Pulse::record()` from `AiUsageLogger` would have put a metrics dashboard in the middle of the billing ledger's write path.

### 4. The AI input bound is two bounds, in characters

- **`max_untrusted_chars` truncates.** A scraped page arriving too big is a normal Tuesday, and the first twenty thousand characters of a company's website is where the answer is. `PromptInterpolator` cuts with `mb_substr` — this text is Arabic as often as English — and says inside the data block that it did, so the model is not left inferring that a sentence ending mid-word means the page ended. The notice is the **last** thing in the block, where content that tried to end with its own instructions no longer is, and the nonce scrub still runs first, so the injection defence and the truncation compose.
- **`max_prompt_chars` refuses**, after rendering and before the transport, with a `413` and the counts in `details` rather than in the message. Nothing is sent and nothing is charged.

**Characters, not tokens.** A token count is only knowable by asking the provider, which is the call these bounds exist to avoid making.

`PromptInterpolator` takes the limit as a **constructor argument**, read from config in `AiPlatformServiceProvider`. It is a pure string-in, string-out service whose unit tests construct it with `new`, and a `config()` call inside it would have made a pure renderer require a booted framework.

### 5. `security:posture` checks the machine, not the repository

`php artisan security:posture [--strict]` asserts the settings that must hold in production: debug off, an app key, an HTTPS `APP_URL`, a secure/http-only/same-site session cookie, CSP on, HSTS on, admin 2FA required, a backend DSN, and `send_default_pii` off. Failures exit non-zero with what to set; **warnings** (a report-only policy, an empty IP allowlist) do not fail a deploy, because a check that cries wolf gets `|| true` appended to it.

A command and not only a test, and the distinction is the whole point: a test asserts what the repository says, and every setting in that list is _correct in `.env.example`_ and silently wrong on a box somebody configured in a hurry. Several are also switched off for an afternoon and left off. Phase 21 runs it as a deploy step. `SecurityHeadersTest` covers the other half — that the headers actually arrive — because neither claim is true on its own.

### 6. Dependency and secret scanning is its own scheduled workflow

`.github/workflows/security.yml`, separate from `tests`, with a **weekly schedule** as well as the pull-request trigger: a dependency that was clean when a pull request merged is not clean for ever, and nobody opens a pull request to tell us.

The thresholds are a decision. `composer audit --locked` fails on any advisory. `npm audit --audit-level=high` across the whole tree of both JavaScript projects, and `--omit=dev --audit-level=moderate` for the packages that run in front of a customer. The remaining moderates are build tooling with no fixed release — `@capacitor/cli` pins an old `rimraf` — and failing on those would mean a red build nobody can turn green, which is how a security gate acquires `|| true`. Dependabot raises them weekly instead, where they get reviewed rather than ignored. (This phase did take the one that mattered: a **critical** advisory in Vitest's worker pool, fixed by upgrading the mobile app's test runner.)

Secret scanning runs the **gitleaks release binary verified against the release's own checksums file**, not `gitleaks/gitleaks-action`. The action's v2 asks organisations for a licence key, which is a dependency on a vendor's billing state inside the job that is supposed to tell us about leaked keys — and a scanner is exactly the thing to pin by checksum rather than by tag. It scans the full history, because a credential committed and removed in the next commit is still a credential that was published. `.gitleaks.toml` allowlists the two `.env.example` files and a short list of obvious placeholder stems; `tests/` stays scanned, because that is exactly where somebody pastes a real key to see if it works.

### 7. The token-abilities audit asks whether the scope is the right _kind_

`RouteContractTest` (Phase 17) already asserts that every `/api/v1` route has a scope and that the scope exists. `TokenAbilityAuditTest` adds the question that was missing: `EnsureTokenAbility` passes a token carrying **any one** of the listed abilities, so a route declared `ability:leads:read,leads:write` is a read-only token that can write — and it passes every Phase 17 assertion. The audit found no violations; it is here so the next one fails a build.

## The review round

`security-review`, `laravel-module-reviewer` and `brand-reviewer` on the finished phase. Three findings were real holes and are fixed; the rest are corrections and gaps, also fixed. They are listed here rather than only in a changelog because two of them are the kind of mistake that is easy to make again.

**Three holes.**

1. **The request bag was not scrubbed by name** — `url`, `query_string`, `cookies` and `data` — so a 500 on a signed invitation link would have shipped a replayable credential to Sentry, and a failed save would have shipped the prospect record. Fixed in `SentryOptions::scrubRequest()`, and the premise that caused it is corrected above.
2. **The request body was being collected at all.** `max_request_body_size` defaults to `medium` in the SDK, independently of `send_default_pii`. Now `none`, asserted by `security:posture` and by a test.
3. **`/horizon` had only the gate, not the door.** `config/horizon.php` was `['web']`, which was safe only because the published `viewHorizon` gate denied everybody — and this phase made that gate _pass_. So, briefly, `/horizon/api/*` (every tenant's serialised job payloads, plus retry and delete) was reachable with a password alone while `/admin` and `/pulse` refused the same session. It now carries the same three admin middlewares as Pulse, asserted over Horizon's whole route table.

**Corrections.**

- The CSP's `connect-src` derived the socket origin from `broadcasting.connections.reverb` — where _Laravel publishes to_ — rather than the `VITE_REVERB_*` values the browser connects to. Identical on one host, different behind a proxy, and wrong in the direction that silently blocks the socket.
- The CSP exemption hard-coded `pulse` while `PULSE_PATH` is configurable, so moving the dashboard would have lost its exemption and broken it.
- `ignore_transactions` mixed path-shaped and method-prefixed patterns, so `/up` — the entry that matters, ten seconds apart for ever — would not have matched `GET /up`. Both spellings now match.
- The `request_id` tag was gated behind "not console", which is false for a queue worker _and_ for the test asserting it. The context tag and the request id are now independent facts.
- `security:posture` never reset its results between runs in one process, so a second invocation reported the first one's failures.
- An oversize-prompt refusal was being reported to Sentry as a fault. It is a refusal, and is now in `ignore_exceptions`.
- The Pulse entry types were five string literals in two files — a contract where a rename does not error, the column comes back null and a dashboard figure quietly becomes zero. Now `AiPlatform\Enums\AiPulseMetric`.
- The AI usage card's aggregation moved to `Observability\AiUsageSummary`; all three of its sums go wrong quietly, and each wanted a test.
- `Observability/` and `Support/` were not in ADR 0002's directory list. [ADR 0002](0002-module-structure.md) now records both, with why a Pulse recorder is not a `Listeners/` listener.
- The Pulse card's failure count used `text-red-500`, about 3.8:1 on Pulse's white card and below WCAG AA. Now `red-600`, which Pulse itself uses.

**A limitation, now written down rather than fixed.** An exception that **escapes a queue job** carries no `team_id`. Every job activates its tenant with `TeamContext::run()`, which restores the previous value in a `finally` — so the context has already unwound by the time the worker reports. That fails in the safe direction (a missing tag, never a false one, which is why `run()` is right) and a test pins the direction. Giving the queue layer a durable notion of which tenant a job belongs to is the real fix, and it is not Phase 20's.

Two things the review flagged that were **not** changed, deliberately: the key-scrub list over-redacts (`ip_address` matches `address`), which is the safe direction; and domain exception messages are plain strings rather than `__()` keys, which is the existing pattern across every module and a gap for its own change rather than one to half-fix here.

## Consequences

**Good.**

- An unhandled exception anywhere — request, queue job, scheduled command — reaches a person, with the tenant and the request on it and nothing about a human being.
- The browser and the phone report their own failures, which were previously invisible.
- A strict CSP, HSTS, frame denial and a feature-denying `Permissions-Policy` on **every** response, including the ones nobody remembered.
- `/pulse` and `/horizon` are as hard to reach as `/admin`, by the same four controls, with one gate definition.
- A prompt cannot be made enormous, and an attempt is a 413 rather than an invoice.
- Dependency advisories and committed secrets are checked on a clock, not on somebody's memory.
- One command answers "is this box configured safely", and it can fail a deploy.

**Costs, accepted.**

- **The CSP is not exercised locally** under `npm run dev`. A violation introduced by a new dependency surfaces on the first built environment, which is why `report_only` exists and why the runbook says to use it. The alternative — a policy loose enough to hold under HMR — is not a policy.
- **Two vendor dashboards are exempt from the CSP.** Mitigated by four access controls and written down as a config list rather than a condition in code.
- **Breadcrumb and console _messages_ are not scrubbed**, only metadata keys — which is why the browser's `Console` integration is off and why the rule for our own code is to log IDs, not prose (CLAUDE.md §2).
- **The browser DSN is in every Inertia payload**, including on public pages. A DSN is a write-only ingest key by design; the exposure is that somebody could post junk events to the project, which is a quota problem rather than a disclosure one.
- **The AI usage card double-counts nothing but agrees with nothing either.** It is a sampled rolling window and the ledger is authoritative; the card says so, and a figure on a screen that disagrees with an invoice will still be asked about.
- **`npm audit` moderates in dev tooling are knowingly not blocking.** Reviewed weekly through Dependabot instead.
- **Sentry is a sub-processor.** The processing record and the DPA need it listed, and that is a Phase 22 legal item rather than a code one. Flagged there.

## Alternatives considered

- **Wire Sentry as documented and rely on the project's own data-scrubbing settings.** Rejected: those settings are in a vendor console, not in the repository, so they are neither reviewable in a diff nor true of a second installation. They are a useful second layer, not the control.
- **Hash-based or `'unsafe-inline'` CSP.** A hash policy breaks on every edit to the two inline tags; `'unsafe-inline'` makes `script-src` decorative. The nonce is only affordable because this app has no third-party scripts — which is itself worth protecting.
- **A separate `CspNonce` middleware.** The nonce has to exist before the view renders and the policy after the response exists; splitting that across two middlewares means two orderings to get wrong for one value.
- **Leave Pulse on its own `viewPulse` gate with an email allowlist.** That is what shipped, and it is a hard-coded list maintained by hand on a cross-tenant surface. The operator identity and its abilities already exist (ADR 0014); not reusing them would have been a second answer to "who is an operator".
- **Put the AI metrics in the admin panel only.** The panel's figures come from a nightly rollup (ADR 0016), which cannot answer "what is happening now". Both exist because both questions get asked, at different times of day.
- **Bound the AI prompt in tokens.** Requires a provider round trip — or a tokeniser that has to track the model — to enforce a bound whose job is to prevent a provider round trip.
- **Fail the build on every `npm audit` finding.** Produces a red build with no available fix, and a gate that cannot go green stops being a gate.
