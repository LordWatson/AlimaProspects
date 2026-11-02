# Runbook — observability and security posture

> For whoever is on call. Written for somebody who has not read the code, and complete as of Phase 20.
>
> Background, if you want it afterwards: ADR 0017, `docs/modules/core.md` and `docs/modules/admin.md`.

---

## 1. The four places to look, in order

Something is wrong. Start at the top and stop when you have an answer.

| Where                   | Answers                                                                              | Freshness           |
| ----------------------- | ------------------------------------------------------------------------------------ | ------------------- |
| **Sentry** (3 projects) | What broke, where in the code, for which tenant, how often, since which release      | Seconds             |
| **`/pulse`**            | Is something happening _right now_ — queue depth, slow queries, AI spend, exceptions | ~Per minute, 7 days |
| **`/admin/operations`** | _Which tenant's_ job failed, which provider's breaker is open, what has not run      | Live, tenant-aware  |
| **`/admin/reporting`**  | What a customer cost, MRR, churn, the onboarding funnel                              | Last night's rollup |

The split between the last two is worth internalising: **Pulse is a clock, the panel is a tenant.** "The queue is 40,000 deep" is a Pulse question. "Whose discovery run did that, and what has it cost them" is a panel question. Neither can answer the other's.

### Getting into `/pulse`

The same door as the admin panel, in this order:

1. **Platform admin, or a 404.** Not a 403 — a 403 confirms there is something there.
2. **Two-factor confirmed**, or you are redirected to the security page to set it up.
3. **Inside `ADMIN_IP_ALLOWLIST`**, if one is set. Outside it: 404, plus a `warning` log line with the address.
4. **The `operations.manage` ability**, or a 403. That is **Support** and **Super admin**; Finance and Read-only roles get the 403 by design — the queue is the on-call person's screen.

`/horizon` answers identically, and behind the **same four controls** — its routes carry the three admin middlewares too, asserted over its whole route table. (It shipped with only `['web']` in front of it, which was safe purely because the old `viewHorizon` gate denied everybody; the Phase 20 review caught it once that gate started passing.) The Horizon _worker_ does not run on Windows — it needs `ext-pcntl` — so locally there is nothing behind the door; Phase 21 is where it runs.

### If `/pulse` is empty

- `PULSE_ENABLED=false` somewhere. Check `php artisan about`.
- Or nothing has happened in the selected period. The period picker is top-right; it defaults to the last hour.
- Pulse trims after **7 days** (`PULSE_STORAGE_KEEP`). Anything older is gone, on purpose — the panel is where history lives.

---

## 2. Reading the Pulse dashboard

Cards are ordered by the question somebody who has just been paged arrives with.

**Queues** and **Exceptions** sit side by side because a spike in one is usually the other.

**Slow queries** is the card to open when "the app is slow" is the whole report. It shows the SQL and, by default, where in the code it came from. Note what it means in this product specifically: a tenant query should always have `team_id` in its `where` clause, so **a slow query with no `team_id` in it is either a platform report or a tenancy bug**, and both are worth a second look.

**AI usage** is ours. Per prompt key: calls, how many were served from the response cache, how many failed, the spend in the configured currency, and the mean provider latency. Read it for shape, not for money:

- **Failures climbing on one prompt** → a provider change or a template that no longer validates against its schema. Cross-check `/admin/operations/providers` for the breaker.
- **Cached falling towards zero** → something is defeating the response cache (usually a prompt input that now contains a timestamp or an id), and the bill will follow.
- **Latency climbing across every prompt** → the provider, not us.

> **The AI usage card is not the billing figure.** It is sampled, trimmed after seven days, and disappears entirely with `PULSE_ENABLED=false`. The authoritative per-team cost is the `ai_requests` ledger, read by `/admin/reporting` behind `reports.read`. If the two disagree, the ledger is right.

---

## 3. Reading Sentry

Three projects, and the one you want depends on who is complaining:

| Project | DSN env key           | Set where                                                    |
| ------- | --------------------- | ------------------------------------------------------------ |
| Backend | `SENTRY_LARAVEL_DSN`  | `.env` on the server                                         |
| Browser | `SENTRY_DSN_FRONTEND` | `.env` on the server (**served** to the page, so no rebuild) |
| Mobile  | `VITE_SENTRY_DSN`     | `mobile/.env`, **at build time**                             |

### What is on an event

- `team_id` — the tenant. This is the tag to filter by when a customer reports something.
- `request_id` — matches the `X-Request-Id` header and every log line for that request. The fastest way from a Sentry event to the log context around it.
- `impersonated: true` — an operator was wearing a customer's account. The same error from an operator and from the customer are different incidents.
- `context: console` — a queue job or a scheduled command, not a request.
- The user, as an **id only**.

### What is deliberately not on an event

No name, no email address, no IP, no cookies, no request body, and **no query string**. Two mechanisms, because one of them is not enough:

- **By key**, at any depth, in request data, extra context, breadcrumb metadata and the user bag: anything that looks like a credential or personal data becomes `[redacted]`. The list is `config/observability.php`; the mechanism is `Core\Observability\SentryOptions`. This is the layer aimed at _our own_ `Log::error` contexts, which is the realistic leak.
- **By name**, for the four fields a key list cannot reach — `url`, `query_string`, `cookies`, `data`. The query string is redacted and stripped off the URL; the cookies and the body are dropped. In this app a query string holds a search term (somebody's company name) or a signed URL's signature, and a body holds the prospect record somebody was saving. The body is also never collected in the first place, via `SENTRY_MAX_REQUEST_BODY_SIZE=none` — and note that `SENTRY_SEND_DEFAULT_PII=false` does **not** cover it. That is a trap worth remembering if you ever change either: PII-off governs the cookies, the headers and the IP, and nothing else.

**If you need the data to debug, get it from the database or the logs**, where tenancy and the audit trail apply. That is the trade this product makes on purpose: an error tracker is a sub-processor, and a prospect's email address does not belong in one.

### "This error isn't in Sentry"

Check, in order:

1. **It is an expected refusal.** A wrong password, a 404, a throttle, a plan refusal and an exhausted AI allowance are all in `ignore_exceptions` (`config/sentry.php`). They are the application working, and reporting them trains everyone to ignore the tracker. Those still appear in the logs.
2. **No DSN on that box.** `php artisan security:posture --strict` says so in one line.
3. **It was a browser error and the page never loaded the SDK.** No `SENTRY_DSN_FRONTEND` means the SDK is never even imported — by design, so an installation that is not using Sentry does not pay for its bundle.
4. **It happened after the response was sent.** `continue_after_response` is on, but a worker that was killed mid-job may not have flushed.

### Adding a release to a deploy

Set `SENTRY_RELEASE` to the deployed commit. Without it every error belongs to "no release" and _"did this start with yesterday's deploy?"_ has no answer. Phase 21's pipeline sets it.

---

## 4. Response headers, and the one that will bite you

Every response carries `X-Content-Type-Options`, `X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, the two cross-origin policies, and — on HTML — a **Content-Security-Policy** with a per-request nonce.

```bash
# What a page actually sends.
curl -sSI https://app.example.com/ | grep -i -E 'content-security|strict-transport|x-frame|referrer|permissions'
```

### A screen is blank, or a feature stopped working after a deploy

Nine times in ten this is the CSP, and the browser console says so in as many words (`Refused to load … because it violates the following Content Security Policy directive`). What to do:

1. **Read the directive it names.** If something now loads a script, a font or an API from a new host, that host has to be allowed — `SECURITY_CSP_EXTRA_SCRIPT_SRC`, `SECURITY_CSP_EXTRA_CONNECT_SRC`, `SECURITY_CSP_EXTRA_IMG_SRC`, comma-separated.
2. **If you need the page working now**, set `SECURITY_CSP_REPORT_ONLY=true` and `php artisan config:clear`. The policy is then reported and not enforced. **This is a temporary state**, and `security:posture` warns about it in production for exactly that reason.
3. **Never reach for `unsafe-inline`.** This app has no third-party scripts, which is the only reason a strict policy is affordable at all. An inline script that needs allowing is an inline script that should be in a Vite entry point.

Two things the policy does **not** cover, so you are not surprised:

- **It is not emitted while `npm run dev` is running.** Vue injects component styles from JavaScript with no nonce, so a policy that held locally would hold by breaking the page. Test the policy against a built environment, or against `npm run build` locally.
- **`/pulse` and `/horizon` are exempt** (`security.csp.except`). Both inject inline Livewire and Alpine bootstrapping we cannot nonce. They are behind four access controls instead.

### HSTS is missing

It is only sent over HTTPS, by design: sent from a local `.test` domain it would pin that host to HTTPS in your browser for a year. If it is missing in production, the app is not seeing the request as secure — which on a proxied deployment means `TrustProxies`, and is Phase 21's business.

---

## 5. Before a deploy: `security:posture`

```bash
php artisan security:posture            # checks this environment
php artisan security:posture --strict   # applies the production rules whatever APP_ENV says
```

Non-zero exit means something must be fixed. Each failing row names the setting.

| Row                        | Why it fails a deploy                                                                                                                                                  |
| -------------------------- | ---------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `app.debug off`            | The worst one. Debug mode puts the stack trace, the environment and the database credentials on the error page of anybody who can provoke a 500.                       |
| `APP_KEY set`              | No key, no encryption and no working sessions.                                                                                                                         |
| `APP_URL is https`         | Signed URLs (the one-click unsubscribe) and every mail link are built from it.                                                                                         |
| secure session cookie      | `null` means "follow the request", which behind a misconfigured proxy is a session cookie on the wire.                                                                 |
| CSP enabled / HSTS enabled | Somebody switched a control off to debug something.                                                                                                                    |
| admin two-factor required  | `ADMIN_REQUIRE_TWO_FACTOR=false` is a local convenience. One super-admin credential reads every tenant's data.                                                         |
| backend Sentry DSN         | Nothing is watching.                                                                                                                                                   |
| Sentry PII off             | `send_default_pii=true` sends the cookies, the request headers and the visitor's IP.                                                                                   |
| Sentry request body off    | A **separate** option, and the one that actually governs the body — ten kilobytes by SDK default, which in this app is a prospect record. No scrubber can un-send one. |

**Warnings do not fail the deploy** — a report-only CSP and an empty IP allowlist are both legitimate states. They are there so nobody has to remember to look.

It checks _configuration_. That the headers actually arrive is asserted by `tests/Feature/Core/SecurityHeadersTest.php` against the real middleware stack; neither claim is worth much without the other.

---

## 6. Dependencies and secrets

`.github/workflows/security.yml` runs on every pull request, on pushes to `main`, **every Monday at 06:00 UTC**, and on demand from the Actions tab. The schedule is the point: a dependency that was clean when a pull request merged is not clean for ever.

| Job                | What fails it                                                                                                                 |
| ------------------ | ----------------------------------------------------------------------------------------------------------------------------- |
| `dependency audit` | Any PHP advisory (`composer audit --locked`); `high`+ in either npm tree; `moderate`+ in the npm **production** dependencies. |
| `secret scanning`  | Gitleaks finding a credential anywhere in the **full history**.                                                               |

### "The audit is red and there is no fix"

Check whether it is a **production** dependency. If it is a build or test tool with no fixed release, the honest answer is a Dependabot pull request and a note, not a lowered threshold — but if the advisory genuinely has no available fix, raise the specific threshold in the workflow **with a comment naming the advisory**, and open an issue. A gate that cannot go green stops being a gate.

### "Gitleaks found something"

Assume it is real until you have shown otherwise, and in this order:

1. **If it is a live credential: rotate it first.** It is in the git history, which means it is published; removing the commit does not unpublish it.
2. If it is a fixture or a placeholder, add a `gitleaks:allow` comment on that line. Do **not** widen `.gitleaks.toml` to a directory — `tests/` stays scanned precisely because that is where somebody pastes a real key to see if it works.

---

## 7. Common incidents, end to end

**"Everything is slow."** `/pulse` → Slow queries. If one query dominates, read its location. If the queue is also deep, it is a worker problem, not a query problem — `/admin/operations`.

**"A customer says the app is broken."** Sentry, filter `team_id`. Take the `request_id` from the event into the logs for the surrounding context. If nothing is in Sentry, it is an expected refusal: look for a 402 (plan or allowance), a 403 (ability or token scope) or a suppression — `/admin/operations` and the tenant's screen in the panel.

**"The AI bill jumped."** `/pulse` → AI usage, for which prompt and whether the cache stopped working. Then `/admin/reporting` → margin, for which tenant and how much. The first tells you what changed; only the second is the number to quote.

**"Sentry is full of one error."** Decide whether it is a fault or a refusal. If it is a refusal the application makes on purpose, it belongs in `ignore_exceptions` (`config/sentry.php`) — with a comment saying why, because that list is also the list of things we have decided not to be told about.

**"We think a credential leaked."** Rotate, then run the secret-scanning job from the Actions tab against `main` to find out whether it is in the history as well as in the incident.

---

## 8. Production notes

- `SENTRY_SEND_DEFAULT_PII` stays false. It is checked by `security:posture` and asserted by the suite.
- `SENTRY_RELEASE` should be the deployed commit; set it in the pipeline.
- `PULSE_STORAGE_KEEP` defaults to 7 days. Longer means a bigger `pulse_*` table set; the panel is where history belongs.
- Pulse records into the **database** by default. On a busy production box, `PULSE_INGEST_DRIVER=redis` with `pulse:work` running moves the write off the request — Phase 21's Supervisor configuration is where that lands.
- Sentry is a **sub-processor** holding error context about customer data. It needs listing in the processing record and the DPA; that is a Phase 22 legal item, not a code one.
