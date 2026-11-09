# 0034 — Leaving is a contract

- **Status:** Accepted
- **Date:** 2026-11-09
- **Phase:** PL7 (`pre-launch.md`)
- **Supersedes / amends:** nothing. Creates `app/Modules/Integrations` and the framework PL8 and
  PL9 build on. Binds PL5's suppression gate and PL4's metering to a third path, after PL6's export.

## Context

Everything Alima has built so far ends on a screen. PL6 let a team take their list out as a file,
which is the crudest possible answer to "put this where I work" — it is a file somebody has to
remember to re-export.

PL7's two halves answer it properly, and they are not two features:

- **Outbound webhooks.** The whole Zapier/Make/n8n surface for the price of one HTTP POST, which is
  why they come first and why the acceptance criterion is a signature a customer's own code can
  verify.
- **A CRM framework.** The contract, the tables, the OAuth state, the token refresh and the push,
  with no provider-specific connector — Pipedrive is PL8 and HubSpot is PL9.

`docs/prelaunch/recon.md` binds this phase: a new module via the `new-module` skill, `SafeUrl`
shared and never copied, and the inbound webhook (`signal_inbound_events`,
`POST /api/v1/signal-events`) read before designing signing, replay protection and rate limits.

## Decisions

### 1. A connector is a registry entry, for the fourth time

`CrmConnector` behind `CrmConnectorRegistry`, keyed by the `CrmProvider` enum, registered by a
service provider. That is ADR 0008's pattern (lead sources), ADR 0023's (signal sources) and ADR
0029's (enrichment vendors), and the fourth appearance is the argument for it: `SyncLeadToCrmAction`
contains no `match` over providers, because that `match` is where the second provider's behaviour
gets bolted onto the first one's.

A **provider is an enum and a signal type is a row**, and ADR 0018 §1's test still decides which:
a class exists per CRM, so the application branches on the value.

Unlike `SignalSourceKind`, `CrmProvider::isAvailable()` **asks the registry** rather than answering
from a `match`. A signal source can be declared-but-unwired because a team stores a _key_ against
one and that key has to be refused at the form; a CRM is only ever reached through a route or an
`integrations` row, and both ask the registry — so a second list would be a second answer.

Both cases are declared although neither is wired, because the alternative was a settings screen
with nothing on it and a framework with no way to run. The screen shows "not available yet" rather
than a Connect button that 404s.

### 2. The suppression check is in the Action, before any connector

A CRM is a place a record goes **in order to be contacted from**. Once an organisation is in
somebody's Pipedrive, no later opt-out can reach it — which is ADR 0033 §2's argument for the
export, arriving at the same answer for the same reason. So `ContactApprovalGate::allowsDomain()`
(PL5's seam, PL6's third question) is asked before the push, and the same two rules the export
applies are applied to the contacts: a value nobody has paid to reveal is **not sent at all** rather
than sent masked, and a suppressed address is dropped although the company is fine.

A refusal is a **logged row**, not a silence. "Why is this company not in my CRM" has to have an
answer, and `SyncOutcome::Refused` is apart from `Skipped` precisely so it is not buried in the
noise of unchanged records.

A lead with **no website** is pushed rather than refused: there is no domain to ask about, and
refusing would mean a company nobody has found a site for can never be synced — which is a great
many perfectly contactable small businesses.

### 3. `state` is a cache entry, not a signature

The plan asked for a signed, single-use, ten-minute `state`. What shipped is an opaque random token
in a cache entry that is **deleted as it is read**, and it is stronger: a signed value proves we
issued it and nothing more, so it can be replayed until it expires and cannot be revoked. Ten
minutes is a ceiling rather than the protection.

It is bound to the **team, the user and the provider**, and all three are checked. The provider
matters as much as the other two: a state issued for Pipedrive completing a HubSpot callback would
store HubSpot tokens on a Pipedrive row, and every later call would be made against the wrong API
with a credential that works.

What this defends against is worth writing down, because it is easy to read `state` as ceremony: a
callback is a **GET to our application with somebody else's authorisation code in it**. Without it,
an attacker who gets a member to open a crafted callback URL connects _their_ CRM to the member's
team, and from then on every lead that team finds is pushed into it.

**PKCE is not here.** It is in the plan and it belongs with a connector — the challenge method a
provider accepts, and whether it accepts one, is provider knowledge. `authorizeUrl()` composes the
whole URL, so PL8 can add a `code_challenge` without this changing.

### 4. A refused credential and a dead one are different statuses

`IntegrationStatus::NeedsReauth` against `::Error`. The first is **the customer's to fix** and the
only cure is a person clicking Reconnect, so it stops the sync and notifies the team's admins. The
second is ours or the provider's, is usually transient, and `canSync()` returns **true** for it,
because the next attempt is how it recovers.

`TokenRefresher` refreshes a token that is about to expire _before_ the call, retries exactly once
on a refusal, and only then gives up. Once is the right number: the second refusal after a
successful refresh is the provider saying something other than "your token was old", and a loop
there is a loop against somebody else's rate limit.

The Action's generic `catch` is forbidden from **downgrading** `needs_reauth` to `error`, and that
was a real defect before a test caught it: the refresher had set the right status and told the
admins, and the catch two frames up overwrote it with the pushable one — so the sync would have
retried a credential only a person can replace, for ever, with nobody told.

### 5. Links are a memory; logs are history

Three tables because they answer three questions and age at three rates.

`integration_links` is "their id for our row", and it is the one that must **never** be pruned:
losing a link means the next push creates the organisation again, in somebody else's CRM, where we
cannot clean it up. It is also why **disconnecting does not delete the row** — a team who
disconnects on Friday and reconnects on Monday should update what they already have.

Which makes the account check load-bearing: `external_account_id` comes back with the tokens and is
compared on reconnect, and a **mismatch clears the links**. Reconnecting to a different Pipedrive
account with the old links in place would have Alima updating organisation 4821 inside a company
that has never heard of us. A provider that tells us nothing about the account is treated as a
change, because "we do not know" and "it is the same" must not be the same branch.

`synced_hash` is over the **fields a CRM can see**, not over the lead row: `updated_at` moves when
somebody adds a private note, and a hash keyed on that would re-push every organisation a team owns
on the next sweep.

**The link is written before the note and the people, with an empty hash, and the real fingerprint
only after they are through.** Two things have to be true at once and they pull in opposite
directions: a retry must find the organisation we just created rather than create a second one, and
it must not be told this lead is already up to date. Recording the external id immediately satisfies
the first; leaving `synced_hash` empty — a value that can never equal a `sha256` — satisfies the
second. Writing the fingerprint with the link, which is what PL7 did first, meant a note endpoint
having a bad minute left the company in the customer's CRM with no note and no contacts, and every
later push answering `Skipped`, silently, for ever. The job's retries did not help, because the
Action catches `Throwable` and returns an outcome rather than rethrowing.

The plan's `direction` column is not here (there is only a push, and a column with one value lies
about the table the day a pull arrives), and its `action` + `outcome` pair became one `SyncOutcome`
(two columns allowed the state "created / failed", which means nothing).

### 6. The delivery row exists before the request, and holds only `data`

A webhook delivery is written, then attempted, then updated. That makes the log honest — a worker
killed mid-POST leaves a `pending` row rather than no evidence that anything was tried — and it
makes **redelivery a job dispatch rather than a rebuild**: the body it sends is what happened, not
what the lead looks like today, which is the whole point of replaying a webhook. A redelivery is a
**new row**, because the log must keep saying that the first attempt failed.

`payload` holds the envelope's `data` only. The id, the event name and the timestamp are built from
the row at send time, so the stored json carries no second copy of what the row already says and the
two can never disagree.

The payload is composed with the **app's own API resources**, resolved inside the team's context.
That is what makes masking automatic rather than remembered: `LeadContactResource` already sends one
already-masked field per value (ADR 0029), so an unrevealed address cannot reach a customer's Zapier
because somebody forgot to think about it.

**The envelope carries no `team_id`.** The plan asked for an opaque public one to avoid handing out
the internal key — but an endpoint belongs to exactly one team, so the field tells the receiver
nothing they do not know, and the choice was between a column on `teams` with a backfill and a value
derived from `APP_KEY` that changes when the key is rotated. A field that carries no information is
not worth either.

### 7. The signature is the inbound one, outbound

`X-Alima-Signature: t=<ts>,v1=<hmac_sha256(secret, ts.body)>`, deliberately the same construction
`SignalWebhookSecret` uses for the inbound direction (S13b, ADR 0023 §7) — the timestamp **inside**
the signature's scope so a captured request cannot be refreshed by editing it, and the **raw bytes**
of the body, because `json_encode(json_decode($body))` is not `$body` and signing a re-encoded
payload would let anybody who can reorder keys keep a valid signature over different content.

The secret is **encrypted, not hashed**, and that is forced by the cryptography rather than chosen:
producing a signature means recomputing an HMAC, which needs the secret itself. It is shown in the
clear exactly once, by the call that mints it; nothing reads it back, so a screenshot or a support
ticket cannot leak it later. Rotation **replaces** it — two live secrets is a grace period nobody
asked for and a second thing to find when one leaks — and the cost is explicit on the screen.

`v1=` is versioned because a signing scheme that cannot be changed never is.

### 8. The SSRF guard runs twice, and the second time it pins

On save it is a **usability** control: a member typing `http://localhost:6379/` deserves "we can't
reach that address" beside the field, not a delivery log full of failures an hour later. The form is
also stricter than the shared guard — **https only**, where `SafeUrl` allows `http` for a lead's own
website — because a webhook body carries revealed contact details and a signature proving it came
from us, and there is no legitimate reason for a public endpoint not to have a certificate.

At send time it is the **actual** protection, because DNS changes after a form is submitted and that
is the whole of rebinding. So the addresses are approved and then **pinned** for the request
(`SafeUrl::approvedIps()` / `resolveOption()` — shared with the feed source and never copied),
redirects are refused because a `Location` is an address nobody checked, and a host that cannot be
resolved is refused outright rather than attempted. `FeedSignalSource` made that last call first and
for the same reason: an endpoint we cannot pin is one we will not post a customer's lead data to.

A guard refusal is **permanent** — it fails the delivery immediately rather than retrying — and it
**counts** toward the auto-disable, because an endpoint that now resolves to a private address will
never work again. A host that merely **fails to resolve** is a different thing and retries like any
other failure: it is also what a DNS blip looks like from here, and treating the two alike spent a
delivery and a strike on somebody else's nameserver having a bad minute.
`IntegrationException::endpointRefused()` is what tells them apart; both used to arrive as the same
bare exception.

### 9. The retry schedule is the queue's, and `next_retry_at` is for the screen

`$backoff = [60, 300, 1800, 7200, 43200]` with `$tries = 6`. A non-2xx **throws**, on purpose: that
is how the queue's own backoff is reached. `next_retry_at` is written for the log to show and read by
nothing, because a second mechanism that could disagree with the first is worse than no column.

`ShouldBeUnique` is deliberately **absent** on the delivery job. One row is one attempt to reach one
endpoint with one event; the idempotency that would matter — not posting twice — cannot be
guaranteed over somebody else's HTTP endpoint anyway. That is why the envelope carries an `id` and it
is repeated in a header: **the receiver de-duplicates**, which is the only place it can be done
correctly. `PushLeadToCrmJob` _is_ unique per (integration, lead), because there the race writes a
duplicate organisation into a customer's CRM.

### 10. Twenty consecutive deliveries, counted per delivery and reset by a success

Consecutive, so the threshold means "this endpoint has not worked for twenty tries running" rather
than "this endpoint has had twenty bad days since March" — counting cumulatively would eventually
disable every endpoint that has ever sat behind a flaky proxy. Per **delivery**, not per attempt: one
delivery is already six attempts over thirteen hours, so twenty is days of a dead URL, which is the
point, because switching one off stops a customer's automation.

`disabled_at` is ours and `is_active` is the member's own switch, stored apart so the screen can say
which. Re-enabling clears the count as well as the stamp: leaving nineteen failures on the row would
have us disable it again on the first bad answer.

Disabling **notifies the team's admins**, because a switched-off endpoint is silent by definition.

### 11. The allowance is held when the push starts and settled against what moved

`CrmRowAllowance` is the **seventh** ADR 0012 seam, and the second bound **inside**
`billing.enforce_plans` — a record pushed to a customer's CRM costs us nothing, so a ceiling on it is
a plan feature rather than a cost guard, which is PL6's argument and not PL4's. `BillingMetric::CrmSync`
has existed since PL4 with an allowance on every plan and nothing spending it; this is what spends it.

A **skip** and a **refusal** both release the hold. Neither created anything in anybody's CRM, and
charging for the `synced_hash` optimisation would make being careful cost a team money.

### 12. A rule may prepare an unattended push, and the default is off

Auto-sync hangs off `LeadScored`, because that event is the moment a lead's worth changed — a nightly
sweep asking "which leads are good now" would re-ask about every lead a team owns, leaving
`synced_hash` as the only thing between them and a few thousand pointless API calls a night.

**Off unless a team switched it on**, and the default matters more than the mechanism: switching it on
means Alima starts writing into somebody's CRM with no person present, and nobody should discover
that as a default.

An **absent threshold means "do not care"**, and an **unjudged confidence fails a floor rather than
passing it** — `leads.confidence` is null until PL1's evidence has been weighed, and null is not zero
(ADR 0028). Failing toward not pushing is the safe direction, because the thing that cannot be undone
is a record arriving in somebody else's CRM.

The listener catches `Throwable`: it runs on an after-commit event and therefore inside the caller's
job, and an exception there would fail whatever was scoring the lead (ADR 0024's lesson).

### 13. The note is composed once, here, and says three things

The only **prose** this module produces, and the one place the product is read by somebody who has
never seen it — an account manager opens an organisation in Pipedrive and finds a paragraph from us.
So `CrmNoteComposer` owns it rather than each connector: two connectors would be two voices, and the
one with fewer customers would be the one that was never corrected.

The why-now sentence is used if PL1 generated one and **never invented** (ADR 0028: the summary comes
only from supplied evidence), the evidence is dated, sourced bullets with their links, and a
**disputed** item is dropped rather than shown — a member has already said it is wrong, and a CRM
note is beyond anybody's reach to correct afterwards.

Plain text, because every CRM renders notes differently and at least one strips tags.

## Consequences

- One more seam for Billing to fill, and the pattern is now used seven times.
- A new module, `app/Modules/Integrations`, which listens outwards and which nothing listens to.
  Four hookups, all on other modules' events and models, and `lead.created` is raised by an
  **observer** because Lead Sourcing has no such event — adding one would mean editing a module with
  no interest in webhooks. `Lead::observe()` takes more than one observer, which is the same
  mechanism ADR 0028 chose.
- **`lead.created` and `lead.updated` are per-record and a discovery run finds hundreds.** The
  endpoint lookup is memoised per process and the payload is composed behind a closure, so a team
  with no endpoints pays one query per run — but a team that subscribes pays a resource resolution
  per company, by design. `WebhookEvent::isHighVolume()` is what warns them on the screen.
- `webhook_deliveries` is now the fastest-growing table in the product, and every row holds a copy of
  a lead's payload. `integrations:prune-deliveries` sweeps settled rows after 30 days and **never a
  pending one, whatever its age** — a twelve-hour backoff means a row can legitimately sit pending
  most of a day, which is `signal_runs`' rule again.
- **No `/api/v1` routes.** `/api/v1` is how a customer's script reads Alima and webhooks are how
  Alima reaches their script; letting a token register an endpoint would let a credential decide
  where the team's whole lead flow is sent. Billing makes the same argument for having none.
- **A company suppressed by its registration number is still pushed.** The gap PL6 logged is
  unchanged: `SuppressionType::CompanyNumber` arrived in PL5 and nothing in the product reads it, so
  the sync is consistent with the export and with `OutreachGuard` rather than uniquely wrong.
- `prelaunch.webhooks` is one flag for both halves, because they are one screen and one set of nouns.
  A second flag for the CRM half would be a flag guarding a card that says "not available yet".
- **`connect` is its own policy ability, separate from `viewAny` and from `manage`.** There is no row
  to own yet, so it cannot take an `Integration` — and authorising the connect routes on `viewAny`,
  which only asks whether the caller has a team at all, let any member connect or _replace_ the
  team's CRM, point the auto-sync target at an account of their own, and trigger §5's
  account-changed link wipe. The callback had no check at all. Both found by review; both now behind
  the same Owner/Admin ability as everything else that configures an integration.
- **`WebhookDispatcher` is bound `scoped()`, not `singleton()`.** The memo has to survive a whole
  discovery run, which is the case it exists for, and must not survive the life of a `queue:work`
  process — as a singleton, an endpoint added after a worker booted received nothing at all until
  somebody restarted it. `PlanResolver` is bound this way for the same reason. `RelayWebhookEvents`
  memoises the team for the same run, because otherwise "one query per run" was one query per lead.
- **The failure count is `increment()`ed, never read-modify-written.** Five events can be failing
  against one endpoint at once, and five workers each reading 7 all write 8 — so twenty consecutive
  failures would not have been reached by an endpoint failing in parallel, which is every endpoint
  that matters.
- **Changing the auto-sync rule and deleting an endpoint are both audited Actions.** They were a
  controller `validate()` and a bare `delete()`; turning auto-sync on authorises unattended writes
  into somebody's CRM, and deleting an endpoint is where a team's events stopped going somewhere —
  both are questions with no other answer afterwards.

## Alternatives considered

- **A signed `state`, as the plan says.** Weaker than what shipped; see §3.
- **A `TokenRefresher` middleware, as the plan calls it.** It wraps a _call_, not a request, and its
  callers are a queued job and a controller — a Laravel middleware would have nothing to sit on.
- **An opaque public team id in the envelope.** A field carrying no information; see §6.
- **A "Send to CRM" button on the lead detail screen.** The route exists and is tested
  (`integrations.crm.sync`, and an ordinary member may use it — pushing one company somebody is
  looking at is working a lead, not configuring an integration). The **button** waits for PL8: there
  is nothing to send to, and a button that queues a job which refuses is worse than no button.
- **A delivery-log screen of its own, and a payload viewer.** The last hundred attempts on the
  Integrations page instead, with the response snippet and no payload. The body is the team's own
  lead data, it is already on the screen the lead came from, and putting a copy of every lead into a
  settings page's props would make it the largest response in the product.
- **A per-team daily delivery cap.** Not built. The volume is a direct consequence of what a team
  ticked, the enum says which events are noisy, and a cap would silently stop an automation somebody
  depends on. Revisit if a receiver is ever the reason our queue backs up.
- **Named `integrations` and `webhooks` queues, as the plan asks.** One `default` queue until PL12 has
  real depth numbers to split on (ADR 0026 §4).
