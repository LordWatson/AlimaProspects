# ADR 0029 — Contact data, and the shape a paid vendor fits into

- **Status:** Accepted
- **Date:** 2026-11-14
- **Phase:** PL2 of `pre-launch.md`
- **Supersedes / amends:** nothing. It **implements** the architecture ADR 0027 §3–§6 described,
  extends **ADR 0008**'s per-field attribution to per-value provenance, and is constrained by
  **ADR 0026**.

## Context

The product's contacts are free-tier: a name and a role read off a company's public pages, a
pattern-guessed address flagged as a guess, and a syntax-plus-MX check that can prove an address is
_routable_ and never that a mailbox exists. ADR 0027 admitted paid enrichment for launch and
described the seams; PL2 builds them.

It builds no orchestration — that is PL3 — and connects no vendor, because ADR 0027 §2 is still
`DECISION PENDING`. What it decides is the shape: what a contact row holds, what a vendor is asked,
what it may answer, and what a member is allowed to see before somebody has paid.

## Decision

### 1. `lead_contacts` is the contact table, extended

The plan's `contacts` is `lead_contacts` (ADR 0026 §2d). It gains the person's parts, per-value
identity and provenance, the reveal stamps and its own pipeline state. Every column is nullable or
defaulted, and a contact the free-tier extraction wrote today looks exactly as it did.

Three things the plan asked for are **not** added, each for the same reason — a column with no
writer is dead schema:

- **No `full_name`, no `job_title`.** `name` and `title` are those columns and have shipped.
- **No `tps_status`, no `pecr_subscriber_type`.** PL5 adds them with the enum and the service that
  fills them, one phase away, and nothing between now and then reads them.
- **No libphonenumber.** `phone_e164` exists and stays null until PL3, which is where the plan puts
  the dependency. Normalising needs a country, and a half-normalised number dials somewhere else.

### 2. Four narrow capabilities, one registry, and order lives in config

`PersonFinder`, `EmailFinder`, `EmailVerificationProvider` and `PhoneFinder` over a shared
`EnrichmentProvider`, resolved by `EnrichmentProviderRegistry` — the deliberate third instance of
the pattern ADR 0008 set and ADR 0023 repeated: a class per vendor, registered in the module's
provider, and **never a branch in the orchestrator**.

The capabilities are narrow because the alternative is expensive. Vendors sell "enrichment" as one
call returning a person, their email and their mobile; buying it that way means paying for three
answers to get the one that was wanted, and it means a vendor that is good at mobiles and poor at
emails cannot be used for mobiles alone.

**Order is config** (`enrichment.waterfall.<capability>`), not registration order. It is a
commercial judgement about price and hit rate that an operator changes in `.env`, and it must not be
a line of PHP somebody has to find. A key naming an unregistered provider is ignored at runtime and
reported by `unknownIn()` — one typo must not take a capability down, and it must not sit there
unnoticed either, which is why a test asserts the shipped config has none.

A provider is also refused **at registration** if it claims a capability whose contract it does not
implement. The two registries before this one both had the other behaviour, and the failure mode is
a mis-registered vendor blowing up inside a queued job, against a real lead, after being paid.

### 3. A miss is an answer; a failure is an exception

- Nothing found → `EnrichmentResult` with a null value, or an empty `PeopleResult`.
- The provider failed → `EnrichmentProviderException`, with three named causes.
- The provider could not tell → `EmailVerificationStatus::Unverifiable`, which is also an answer.

A miss is the **ordinary** outcome in a waterfall — it is the reason a waterfall exists — and an
exception for the ordinary outcome turns orchestration into `try`/`catch`. The three failure causes
are separate because they are three different decisions: `notConfigured` will fail identically for
ever, `unavailable` is worth trying the next provider for, and `unreadable` means our mapping is
wrong and every result already stored from that vendor is suspect.

This is ADR 0023 §4a's lesson in a new direction. There the question was whether a 4xx meant the
provider was unwell or the query was silly; here it is whether "no" is an answer or a failure. Both
times, conflating them costs money.

### 4. One enum for what we know about an address, however we learned it

`EmailVerificationStatus` gains `Unverifiable`, `Risky`, `Undeliverable`, `AcceptAll` and
`Verified` — cases on the **same** enum rather than the plan's second `EmailStatus` beside it. A
column with two vocabularies is a column every reader has to ask which one it is speaking, and the
reader that forgets is the one that shows a verified address as unchecked. A vendor's own statuses
are **mapped onto** these by its adapter; the mapping is lossy and that is the price of one
vocabulary.

Even `Verified` stops short of 100 confidence: a mailbox that existed this morning is a vendor's
word about this morning (ADR 0008's rule, unchanged).

**Widening it created a bug and fixed it in the same change.** Four places compared `=== MxFound`,
which was correct while `MxFound` was the best outcome that existed. The moment a vendor could
return something _better_, every one of them would have scored a verified address **lower** than a
merely routable one — `LeadReadiness` would have marked a lead down for having better data, and
`LeadDeduplicator` would have skipped the strongest evidence that two leads are the same company.
All four now ask `isRoutable()`. The general rule: **a literal comparison against "the best value
that exists" is a bug waiting for the enum to grow.**

`AcceptAll` is deliberately not the default stop in a waterfall. A catch-all domain cannot
_disprove_ an address, which reads as a pass and is not one; a team that stopped there would be
paying for confidence it had not bought.

### 5. Masking follows who was paid, not whether a value exists

This is the decision most likely to be got wrong, because the obvious implementation looks right.

Masking exists so the product can show that a contact **exists** and charge to show **which**. That
only makes sense for something a vendor was paid for. Masking _everything_ would have hidden the
addresses the free-tier extraction has been finding since Phase 8 and that Outreach has been sending
to since Phase 11 — a regression shipped inside a feature — and it would have charged a member to
see something printed on the prospect's own contact page.

So `requiresEmailReveal()` asks whether `email_source` names a provider. Two consequences:

- A **switchboard** number is never masked, even from a vendor. It is the company's published
  number, a business fact rather than a person's contact detail, and charging to reveal it is a toll.
- A number of **unknown** kind is treated as a person's. The cautious direction, because the costly
  mistake is the other one.

The resource sends **one** field per value, already masked. No `masked_email` beside a plain
`email`: that is a mask any client can step over, and the first one that does gives away what PL4
charges for. Neither reveal stamp is fillable — a reveal is a metered act and only the Action that
charged for it may claim one happened; `Model::shouldBeStrict()` makes an attempt throw outside
production.

### 6. One definition of an identity, in Core

`Core\Support\ContactIdentity` holds the normalisation, the hashing and the masking, and
`Outreach\Enums\SuppressionType` delegates to it. The behaviour of the suppression list is
unchanged; the code moved.

Not a tidy-up. Two tables hash an identity — `outreach_suppressions`, which records who asked to be
left alone, and `lead_contacts`, which from now on records what a vendor found. If the two
normalised differently, the consequence would not be a missed index. It would be **an email sent to
somebody who opted out**, and nothing would show it had happened. One implementation, and a test
asserting the two agree on every form.

Two deliberate non-features: it does **no** E.164 formatting (its job is matching, and a match must
survive a number written four ways — the dialable form is a different column for a different job),
and it uses **no salt** (the point is recognising the same identity arriving from somewhere else
later, which a salted hash cannot do). `setEmail()` and `setPhone()` write the value and its hash
together, because a value written without its hash matches nothing on the do-not-contact list.

### 7. One real adapter, and the missing one is the finding

ADR 0027 §2 asked for "one reference adapter for a mainstream email finder + verifier". PL2 ships
the **verifier** — ZeroBounce, written from the vendor's published v2 documentation — and **no
finder at all**, because no email finder's response fields could be confirmed from any vendor's own
docs.

A reference adapter built on a guessed response shape is **worse than none**: it looks finished, PL3
gets written against it, and the first live call proves the mapping wrong with a bill attached. That
is ADR 0027 §1's "silence is not permission" applied to our own code rather than to a licence.

Experimental means what ADR 0027 says it means: disabled by default, `Http::fake()` fixtures only,
**no live call has ever validated this mapping**, and nothing enables it before the written
confirmation exists. `email_find`, `phone_find` and `people_find` therefore ship with empty
waterfalls; the contracts and the fakes are what PL3 needs.

### 8. A vendor adapter is bound explicitly, with its own breaker

`CircuitBreaker::class` is **Lead Sourcing's** singleton, prefixed `lead_sourcing:cb`. An autowired
vendor adapter therefore shares a door with Companies House: one vendor's outage closing a provider
it has nothing to do with, and an open breaker landing on the wrong row of the operations screen.
Enrichment binds its own (`enrichment.breaker`, prefix `enrichment:cb`) and binds each adapter
explicitly against it.

PL2 found this the hard way — a breaker test passed vacuously against the wrong binding, and the
failure that exposed it was the only reason anybody looked. The general rule: **a shared-by-class
singleton is a shared resource, and a per-source breaker is only per-source if the wiring says so.**

## Consequences

- PL3 can be built and merged against four fakes and one real response shape, with no vendor signed.
  That was the point of ADR 0027 §3, and it holds.
- Until a vendor is chosen, nothing in production changes. Every provider is disabled, three
  waterfalls are empty, no contact has an `email_source`, and so **nothing is masked**. The reveal
  machinery is dark in the most literal sense: there is nothing yet that it applies to.
- `seniority` being free text means `SeniorityLadder` has to be able to place a vendor's vocabulary.
  It reads `config/scoring.php`, so a word it cannot rank defers to the AI half — the behaviour it
  already has for a title it cannot place.
- The widened `EmailVerificationStatus` reaches the admin resource filter, the shared TS union and
  anything that stored a status before PL2. Nothing is migrated, because nothing is removed: the
  five original cases mean exactly what they did.
- `ContactIdentity` is now load-bearing for compliance. A change to its normalisation changes which
  suppression entries match, including retrospectively — the hashes already stored were computed
  with the old rule. Any future change to it needs a migration of `outreach_suppressions` and
  `lead_contacts` together, or it will quietly un-suppress people.
