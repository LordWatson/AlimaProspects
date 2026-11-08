# ADR 0030 — The enrichment waterfall: when to stop paying

- **Status:** Accepted
- **Date:** 2026-11-15
- **Phase:** PL3 of `pre-launch.md`
- **Supersedes / amends:** nothing. It builds the orchestration **ADR 0029** contracted for,
  declares the fifth and sixth seams of **ADR 0012**'s kind, and applies **ADR 0023 §4a**'s
  distinction to a vendor instead of to a search source.

## Context

ADR 0029 gave the module four narrow capability contracts, a registry, DTOs and fakes, and no
orchestration. PL3 writes the thing that uses them: ask vendors in order until one answers, record
every call, and stop as soon as the answer is good enough.

Everything expensive about enrichment is in that last clause. The vendors, their order and their
prices are config; **when to stop paying** is a judgement, and it is the only judgement this phase
makes. The rest of PL3 is bookkeeping around it, which is why the bookkeeping is append-only.

Nothing in it can run in production yet: ADR 0027 §2 is still `DECISION PENDING`, no vendor is
enabled, and three of the four waterfalls are empty. The acceptance criteria are met against the
fakes, deliberately — they are the providers PL4 will meter.

## Decision

### 1. Two tables: a run is a request, an attempt is a call

`enrichment_runs` is what a member asked for. `enrichment_attempts` is one call to one vendor, and
a run makes several.

**`enrichment_attempts` is append-only and is the money.** Every paid call lands there with its
cost whether it found anything or not, there is no `updated_at`, and nothing updates a row. That
shape is chosen because the questions that matter commercially are all about the **misses**: which
vendor is being paid to say "no", what a hit really costs once the failures are counted, whether a
team's reveals are worth their plan. A table that only recorded successes would answer none of them.

Everything downstream reads the attempts rather than the run's `summary`, which is a convenience
snapshot: if the two disagree the attempts are right, because they are written as each call returns
and the summary is composed once at the end.

### 2. Three kinds of nothing, and keeping them apart is the point

`AttemptOutcome` separates a **miss** (the vendor looked and did not know), an **error** (the
vendor let us down) and a **skip** (we never called). A skip says _why_: its breaker was open, we
had already asked too often this minute, or the question had nothing to go on.

Folding them together would make an outage look like a vendor that is bad at its job — and a
vendor's hit rate, which is the number a renewal conversation turns on, would quietly include the
days it was down. So only `Hit` and `Miss` count towards a hit rate, and only a call we actually
made can have cost anything (the recorder forces a skip's cost to zero whatever a caller passes).

This is ADR 0023 §4a's lesson arriving from the other direction. There the question was whether a
4xx meant the provider was unwell or the query was silly; here it is whether "no" is an answer, a
failure, or something we chose not to ask. Both times, conflating them costs money.

### 3. Stop at the first _good-enough_ answer, not the first answer

A verifier that says `accept_all` has answered without telling us anything: a catch-all domain
cannot **disprove** an address, which reads as a pass and is not one.
`enrichment.stop_on.email_verify` is therefore a real knob, and it defaults to `verified` — a team
that stopped at `accept_all` would be paying for confidence it had not bought. A team that would
rather stop early can say so.

Three more rules in the same spirit:

- **Never ask a question that cannot be answered.** `PersonQuery::isAnswerable()` is checked
  _before_ a provider is called, and records a `SkippedUnanswerable`. A vendor that bills per
  attempt charges for a question we could have known was pointless.
- **Ask the cheap gates first.** The breaker and the rate limiter both produce a skip, so a paused
  provider costs a row and nothing else.
- **A rate-limited provider is skipped, not waited for.** The next provider can usually answer
  immediately, and holding a worker for a minute to save one call is a bad trade for the member.
  The limiter exists because hitting a vendor's published limit is a **ban** rather than a queue.

### 4. The reservation brackets the spending, in the job

`CreditReserver` is the **fifth** seam of ADR 0012's kind. Reserve before the calls, commit what
was actually spent, release the rest.

It lives in the **job**, not in the Action that created the run. A reservation taken at request
time would be held for as long as the queue is deep and released by nobody if the worker died. The
release is in a `finally`, for the same reason: an allowance held by a dead job is an allowance a
team never gets back. And the commit reads `EnrichmentRun::spentMicros()` — from the attempts, not
the summary — because a run whose worker died between its last call and its summary still owes for
the calls it made.

`$tries = 1`. A retry would re-pay for a waterfall that has already run; the providers retry
_inside_ their own adapters, where a retry is free.

### 5. A refusal is not a failure

`EnrichmentRunState::Refused` exists so that a plan ceiling, a flag that is off and a lead with
nothing to enrich are not recorded as outages. Without it, every team at its allowance would raise
the platform's failure rate on the provider panel, which is the mistake S16 corrected for Signals
and the reason `signal_runs` carries refusal codes.

Two refusals leave **no run at all**: the feature flag being off, and the plan not including
enrichment. A capability refusal says _the feature is not theirs_, which is a sales fact rather
than a cost event — and a row for it would put every plan boundary on the operations screen. A
spent **allowance**, by contrast, is a cost event a team must be able to see, so it is recorded.
That asymmetry is S16's, verbatim.

### 6. A second seam for whether we may hold a person at all

`ContactApprovalGate` is the sixth seam, and PL5 binds it. It is deliberately **narrower** than
`OutreachGuard`: that answers _may this prospect be approached_, and this answers _may we hold and
show this at all_. They have different answers — a company we may not cold-call is still a company
whose switchboard we may know.

It is asked at two moments, and the second is the one that costs money: when a found person is
about to become a contact, and when a member is about to **pay to reveal** a value. Charging for an
address that may not be used is the worst of both outcomes.

Its default is permissive, which is the **status quo** rather than a new permission: the free-tier
extraction has stored contacts with no such check since Phase 8, and sending has always been gated
separately. A module that refused here would be enforcing a rule nothing else enforces yet, in the
one place that cannot see the suppression list.

### 7. A broadcast carries counts, never values

`LeadEnrichmentUpdated` fans out on the team's existing `teams.{id}` channel and carries a run id,
a state and how many things were found. The client re-reads the lead to get them.

That round trip is not an optimisation we failed to make. A broadcast reaches **every member of the
team with a screen open**, and a revealed address in the payload would be a paid reveal delivered
to people who did not pay for it and may not have the lead open at all.

### 8. E.164 is a library's job, and a refusal is a valid answer

`PhoneNormaliser` uses libphonenumber because a national number is not a country code glued to the
digits — `020 7946 0000` is `+442079460000` and the leading zero disappears. Getting that wrong does
not produce an invalid number; it produces a **valid number belonging to somebody else**, which is
the one failure in this phase that reaches a stranger.

A number it cannot read with confidence leaves `phone_e164` null and the raw value on `phone`: the
screen shows what the vendor said and offers no tap-to-call. Two defaults in it fall deliberately
opposite ways — an **unknown kind** is read as a person's (compliance-cautious, since a mobile is
personal data) while a **known fixed line** is read as a switchboard (cost-cautious, since masking a
company's published number would charge a member to reveal something on the homepage).

Found while writing the tests, and worth recording because it will cost somebody an hour:
**`07700 900xxx` is Ofcom's reserved-for-drama range and libphonenumber rightly calls it invalid.**
Those are exactly the numbers documentation tells you to use.

### 9. Bulk is a loop with a cap, not a batch

`RequestBulkEnrichmentAction` does not use `Bus::batch`. A batch's value is a completion callback
and a progress figure over a set of jobs, and neither is worth its cost here: each lead already has
a run row carrying its own state, the screen already listens per lead, and a batch would add a
second place where "how far along is this" is answered — one of which would be wrong whenever a job
was retried.

What it does add is the two things that must not be left to a caller: a **hard cap**
(`max_bulk_leads`, **refused** rather than truncated, because a member who selected four hundred
leads and got eighty enriched would have no way of knowing which eighty) and **one decision per
lead through the same Action**, so a bulk request and a single click cannot disagree about what is
worth running.

### 10. Recording must never fail the run

`AttemptRecorder` wraps every write and swallows a failure, logging it. That is a deliberate
inversion of the usual rule, and the reason is the ordering: the attempt row is written **after**
the money has left, so an exception there would lose the record of a charge we have already
incurred _and_ cost the member their result. Failing loudly would be the worse outcome.

PL3 proved the risk of that choice immediately: passing a non-fillable `created_at` made every
write throw under `shouldBeStrict()`, the recorder swallowed it as designed, and the attempts table
silently stayed empty until a test asked. The swallow is right; feeding it an avoidable exception is
not, and the fix was to stop passing the column.

## Consequences

- With no vendor enabled, a member who switches `prelaunch.enrichment` on gets buttons that queue
  runs which find nothing and cost nothing. That is the honest state of the feature until ADR 0027
  §2 is closed, and the run's `summary` says so.
- The reveal is live but has nothing to reveal: no contact has an `email_source`, so nothing is
  masked (ADR 0029 §5). The machinery is dark in the most literal sense.
- `enrichment_attempts` will grow one row per vendor call, including skips. At zero vendors that is
  zero rows; the day a vendor is enabled it is the table PL4's margin guard reads and the one that
  will eventually want a retention sweep. **PL12 should add one** — it is not in the plan, and
  nothing else in the module prunes.
- Admin's provider panel reads the attempts directly rather than through `platform_daily_metrics`.
  That is defensible at this volume and is called out in `EnrichmentProviderStats`; a nightly fold
  is an ADR 0016 conversation if the table ever gets big.
- `PlatformProvider` gained a `ZeroBounce` case it should arguably have had in PL2 — ADR 0023's rule
  is that a source without a row there has a breaker nobody can see. Adding it late is the kind of
  gap a per-source breaker invites, and the `PlatformProviderTest` over the whole enum is what
  caught the three `match` arms that then needed filling.
- The mobile draft screen now excludes a **masked** address from its recipient list. Without that
  it would have offered `j***@acme.com` as somebody to write to — a bug PL3 introduced in PL2's
  masking and fixed in the same phase that made it reachable.
