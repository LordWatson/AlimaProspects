# ADR 0027 — Enrichment vendors

- **Status:** **Proposed — `DECISION PENDING`** (the architecture decisions in §3–§6 are **Accepted**
  and binding on PL2/PL3; only the vendor names in §2 are open)
- **Date:** 2026-11-08
- **Phase:** PL0 of `pre-launch.md`; consumed by PL2 (contracts + adapters) and PL3 (waterfall)
- **Supersedes / amends:** amends **ADR 0008**, which ruled out paid enrichment for the MVP. Paid
  enrichment is now in scope for launch. Nothing else in 0008 changes: every field stays attributed,
  and a `LeadSourceProvider` still stores no named individual.

## Context

ADR 0008 chose Companies House, Google Places and Serper web search and deliberately excluded paid
enrichment. The product's enrichment today is free-tier: AI extraction of a lead's public web text
(`EnrichLeadAction`, `WebsiteTextGatherer`), a pattern-inferred email marked `is_inferred`
(`EmailPatternInferrer`), and a syntax + disposable + MX check (`EmailVerifier`,
`EmailVerificationStatus`). That finds a role inbox and guesses at a person, which is honest but not
competitive: the gap analysis found that every comparable product ships a **verified** email and
often a mobile number, and that this is the single most cited reason a trial does not convert.

PL2 and PL3 therefore add a multi-vendor waterfall. The vendor choice is Alex's and is not made yet:
it turns on commercial terms that have to be confirmed in writing, not on anything in the code.

## Decision

### 1. The requirement that decides it: embedding must be allowed

A vendor is only viable if its terms permit **embedding its data inside a SaaS product our customers
use** — i.e. our customers see and export data the vendor supplied to us. Many enrichment vendors
licence their data for the subscriber's _internal_ use only, which makes reselling it inside a product
a breach regardless of how the calls are made.

**Written confirmation is required before a real adapter is enabled in production**, and the
confirmation (or the clause that grants it) is quoted in §2 when this ADR is completed. This is the
same evidence standard ADR 0025 held the free sources to: a licence nobody read is a guess with a
label on it, and "silence is not permission" cost that plan five sources of seventeen.

**Apollo is excluded.** Its standard plans are internal-use only.

### 2. Vendor slate — `DECISION PENDING`

To be completed by Alex. Candidates carried over from `pre-launch.md` §2:

| Capability                 | Candidates                               | Terms allow embedding? | Per-call cost | Decision                       |
| -------------------------- | ---------------------------------------- | ---------------------- | ------------- | ------------------------------ |
| Email find                 | Prospeo                                  | _unconfirmed_          | _unknown_     | PENDING                        |
| Email + mobile (waterfall) | FullEnrich, BetterContact                | _unconfirmed_          | _unknown_     | PENDING                        |
| Email verification         | ZeroBounce, MillionVerifier, NeverBounce | _unconfirmed_          | _unknown_     | PENDING                        |
| People / decision-makers   | _to identify_                            | _unconfirmed_          | _unknown_     | PENDING                        |
| Job-change webhooks        | Crustdata                                | _unconfirmed_          | _unknown_     | **Post-launch** — out of scope |

Each row needs three things before it is Accepted: the clause permitting embedding, the per-call
cost in **micros** (ADR 0026 §3 — PL4's margin guard reads it), and the account's rate limit.

Until a row is decided, **PL2 ships one reference adapter per capability against a mainstream
vendor's public API docs, `enabled => false` by default and marked `experimental` here.** Marked
experimental means: it is tested against `Http::fake()` fixtures only, it is never enabled in
production, and no live call has ever validated our mapping of its statuses.

### 3. One capability, one contract, and a vendor is a registry entry

Vendors sit behind narrow capability contracts — email find, email verify, phone find, people find —
registered in a registry resolved from `config/enrichment.php`. This is the **deliberate mirror** of
the pair ADR 0008 established for lead sources and ADR 0023 for signal sources: a provider class per
vendor, registered in the module's service provider, and **never a branch inside the orchestrator**.
Adding a vendor is a registration plus a config line.

Three consequences inherited from ADR 0023, because they were learned the expensive way:

- **Each vendor gets its own circuit-breaker key** under its own namespace (`Core\Support\CircuitBreaker`),
  so one vendor's outage is not another's, **and a matching `Admin\Enums\PlatformProvider` case** —
  an open breaker with no admin surface is an outage nobody can see.
- **A vendor's health and a request's validity are different questions.** A 4xx that means _we asked
  for something that cannot be found_ is a per-request `not_found`, is counted, and leaves the
  breaker shut. A 4xx that means _this account is exhausted or we are sending the request wrongly_
  fails loudly. Swallowing the second as the first completes every run green, having found nothing,
  for ever.
- **A vendor never throws on "not found."** A miss is a status, because a miss is the common case
  and an exception for the common case produces a waterfall made of `try`/`catch`.

### 4. Nothing paid happens without a reservation

No paid call is made until PL4's reservation succeeds, and the actual cost is committed after it
returns (the unused part released). PL3 therefore depends on a `CreditReserver` seam with a **no-op
default** until PL4 binds the real one — the fifth seam of the kind ADR 0012 established, and the
same shape as `CreditSource`/`LeadQuotaSource`/`SeatLimitSource`/`SignalQuotaSource`.

The one exception to "a stub is permissive" is the one `StubSignalQuotaSource` already makes: where a
default would remove a **cost floor**, it is not permissive. A reveal's floor is a cost floor.

### 5. Keys, and what is never written down

Vendor API keys live in `.env` and are documented by name only in `.env.example`. **No key reaches
code, a test, a fixture or any document, including this one.** Credentials stored per team (PL7's CRM
tokens) use `encrypted` casts. Vendor responses are logged by id and status, never by value: a found
email is personal data, and a log is the one place it would end up unencrypted and unretained.

### 6. What the vendor is not allowed to decide

Three things a vendor asserts that we do not believe, following ADR 0023's rule for an inbound event:

- **Deliverability.** `email_confidence` stays a soft 0–100 signal, never a guarantee, and the
  existing `EmailVerificationStatus` remains the vocabulary — a vendor's statuses are **mapped onto
  it**, not added beside it.
- **Lawfulness.** Whether a prospect may be approached is `OutreachGuard`'s answer (ADR 0024),
  never a field on a vendor payload. A number a vendor supplies is screened against TPS/CTPS in PL5
  regardless of what the vendor says about it.
- **Identity.** A vendor-supplied contact is matched to a lead by our own rules
  (`LeadSourcing\Support\CompanyNameKey`, strict `for()` — never `bare()`), because a contact pinned
  to the wrong company is the failure that reaches a stranger's inbox with someone else's name on it.

## Consequences

- PL2 and PL3 can be built and merged before a single vendor is signed. That is the point of §3:
  the orchestration, the metering, the UI and the tests are vendor-agnostic, and the day a contract
  is signed the work is a config line and an adapter with fixtures.
- Until §2 is completed, the product's enrichment in production is what it is today — free-tier,
  with an inferred email clearly marked. The reveal UI PL3 builds will have nothing to reveal, which
  is why PL3's acceptance criteria are met against fakes and the flag stays off.
- This ADR is **incomplete on purpose** and must be revisited before launch. PL12's go/no-go
  checklist already carries the blocking item ("real vendor keys in production `.env`; one live
  end-to-end enrichment on an internal test lead"), and a launch with §2 still `PENDING` means
  shipping without verified contact data — a conscious commercial choice, not an oversight.
- Amending ADR 0008 does not reopen it. Paid enrichment is in; "store no named individual at the
  sourcing step" is unchanged, and a person still enters only through enrichment or a signal.
