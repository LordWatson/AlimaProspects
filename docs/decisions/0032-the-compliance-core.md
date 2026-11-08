# ADR 0032 — The compliance core: one gate, a public door, and three things we refuse to guess

- **Status:** Accepted
- **Date:** 2026-11-17
- **Phase:** PL5 of `pre-launch.md`
- **Supersedes / amends:** nothing. Extends ADR 0006 (tenancy), 0007 (markets), 0024 (an automation
  may not send), 0025 (platform reference datasets), 0029 (the enrichment contracts).
- **Related:** `docs/compliance.md` §3.2, §3.5, §3.15–§3.17; `docs/modules/compliance-privacy.md`.

## Context

PL5 is the phase that makes the product safe to sell. Four things were missing, and `recon.md`'s gap
table named all four: a **public door** for somebody who is not a customer and not a user, **TPS/CTPS
screening** for UK numbers, the **PECR subscriber distinction**, and one **gate** that every path
which stores, reveals, exports or syncs a contact has to pass.

Almost everything else the plan asked for already existed under a different name. `outreach_suppressions`
is the plan's `suppression_entries`; `EraseSubjectDataAction` is its purge; `AuditLogger` is its audit
trail. The risk in this phase was never writing too little. It was writing a second suppression list.

## Decision

### 1. `outreach_suppressions` stays the one do-not-contact table, and gains one type

PL5 adds `SuppressionType::CompanyNumber` and nothing else to the schema of the list. A team arriving
with their own do-not-contact list means companies, not addresses — "never approach this client
again" — and a list that can only name addresses cannot say it.

The **number** and not the name, because `LeadSourcing\Support\CompanyNameKey` matches loosely on
purpose (ADR 0021) and a loose match on a suppression list suppresses the wrong business.
`ContactIdentity::companyNumber()` is the one normaliser, beside the three that were already there,
and it requires **at least one digit** — every register numbers its companies, so a value of pure
letters is a word, and in practice the word is the header of the CSV column somebody just pasted.
`company_number` stored as the registration number `COMPANYNUMBER` would sit on a team's list for
ever.

The column `type` had to be widened from `string(8)`: `company_number` is fourteen characters, and
MySQL would have truncated it to `company_` — on this table, a team believing a client is suppressed
when they are not.

### 2. One gate, reached through a seam, asserted by an architecture test

`Compliance\Services\SuppressionApprovalGate` implements PL3's `Enrichment\Contracts\ContactApprovalGate`
and is bound over `AllowAllContactApproval`. It is four lines and it is the whole of "one checker on
every outbound path": a person on the list is not stored as a contact, and nobody is charged to
reveal an address they may not use.

It **reads `SuppressionList`** rather than keeping its own copy. `OutreachGuard` remains the only
answer to _may this prospect be approached_; this is the narrower question in front of it — _may we
hold this at all_ — and the two are deliberately different, because a company we may not cold-call is
still a company whose switchboard we may know.

The acceptance criterion is held by a test over the **seam**, not by a test per screen:
`ContactApprovalGateTest` asserts the binding and asserts that each path which stores or reveals a
contact takes the contract in its constructor. A new path that forgets to ask would pass a behaviour
test it never wrote; this one fails. PL6's export and PL7's sync join that list rather than adding a
second check of their own.

### 3. A screening is a fact to record, not a reason to refuse — so it is a second seam

`Enrichment\Contracts\ContactNumberScreener` is new, with a no-op default, bound by Compliance to
`TpsScreener`. It is deliberately **not** part of the approval gate.

The gate answers _may we hold and show this_ before any money moves. A TPS registration does not stop
a team holding a switchboard number or ringing it about an existing contract — it stops an
unsolicited marketing call — so a screening folded into the gate would have to refuse, and refusing
would enforce a rule that does not exist. Instead the screener returns nothing, stamps a verdict on
the contact, and the product withdraws the one-tap call button and shows a badge. The member's
decision stays theirs.

It runs **after** the charge in `RevealContactValueAction`, and swallows its own failures. The member
has already paid by the time it runs; a slow register must not cost them the credit. Same reasoning
as `AttemptRecorder` (ADR 0030): the row is written after the money left.

### 4. Unknown is not clear, and that is why there is no real TPS adapter

`TpsScreeningProvider` ships with exactly one implementation, `UnlicensedTpsScreening`, which answers
`null` to everything. TPS and CTPS are licensed datasets and automated screening inside a product
needs a licence that permits it — an open item, not code. Everything around the seam is finished: bind
a licensed adapter and the cache, the 28-day re-screen, the badge and the dial-link rule all start
working with no other change.

The temptation in an unlicensed build is to default to `clear` so nothing looks broken. That would put
a green light on every number in the product. So `TpsStatus` has **four** cases and three of them are
not "clear":

- `Unchecked` — nobody asked. Not a pass. The column default, and the model default too, so a
  resource over an unsaved instance reads `unchecked` rather than null.
- `NotApplicable` — outside both registers. A Dubai number is not clear of a UK register; the question
  does not arise there, and the UAE answer is `Market::Uae`'s prior-consent restriction instead.
- `Registered` — the only one that changes what the product offers.
- `Clear` — a register said so.

A provider that is unavailable, fails, or answers something unrecognised cannot produce a `Clear`.
A **stale** verdict is returned rather than discarded when nobody can replace it, because an expired
row with nothing in its place reads as "never checked" and would turn an outage into a product-wide
loss of every verdict.

`tps_checks` is **platform reference data** — no `team_id`, no `BelongsToTeam`, and the absence is the
statement (ADR 0025's shape). Whether a number is on the register is a fact about the number, the
register charges per lookup, and keying it per tenant would bill two customers twice for the same
answer while letting one hold a stale "clear" the other has already replaced. It stores a **hash,
never a number**: the row says "this identity is registered", and nothing in the product needs to read
a number back out of it.

Which register is asked **follows the subscriber, not the caller** — CTPS for a corporate subscriber,
TPS for everybody else, and TPS for an unknown one because it covers the wider set of people.

### 5. The PECR subscriber type is derived, never stored, and it warns rather than blocking

`PecrSubscriberType` is computed by `SubscriberClassifier` from three columns the lead already has.
There is **no column**, because a column would be a second answer that drifts the first time
Companies House restates a company type, and the question is cheap to ask.

**Only one path reaches `Corporate`:** a UK lead with a registration number **and** a company type
naming a body corporate. Everything else is `Unknown`, including a lead with no registration number —
which is most of what a maps provider returns. The temptation is to read "no company number" as "sole
trader" and label it `Individual`; that is not the same claim, and asserting a subscriber type on it
would be the product inventing a legal fact. `Unknown` warns exactly as `Individual` does
(`needsPriorConsent()` is true for both) and says only what we know.

Partnerships are checked **before** the corporate list, because `limited-partnership` would otherwise
match `limited` — and a partnership is the partners, which is exactly the mistake the badge exists to
prevent. ⚖️

**Non-UK leads answer null, not `Unknown`.** PECR is a UK instrument. Returning a subscriber type for
a Dubai prospect would read on screen as "marketing email is allowed", which is the opposite of the
truth there, and the UAE restriction is already shown on the same panel.

It reaches Lead Sourcing through `LeadSourcing\Contracts\SubscriberTypeResolver` — the third contract
Compliance fills in on that module, the fifth of this shape in the codebase — and the contract returns
**flat strings rather than the enum**, which is what lets it live on the Lead Sourcing side at all:
naming `Compliance\Enums\PecrSubscriberType` there would be the import the seam exists to avoid.
Nothing on the client branches on the value, which is ADR 0018 §1's test for why it is not an enum
across the seam.

And it **does not block.** Whether to email a sole trader is the customer's decision as controller
(`docs/compliance.md` §5); a product that refused would be making a legal call on their behalf with
less information than they have.

### 6. The public door: nothing happens until the person clicks their own link

`data_subject_requests` is a **platform** table — no `team_id`, no `BelongsToTeam` — deliberately
twice over. The person wrote to Alima, not to a tenant; and a tenant must not be able to read it,
because it names somebody another customer may also hold. Only the admin panel reads it.

`email` is stored in the clear, which looks wrong on a privacy table and is not: we cannot write back
to a hash, and a request we cannot verify or answer is a request we have refused. It is the one
personal datum the person handed us on purpose, for this purpose, and the retention sweep takes it.

**Four guards, and none of them is a login:**

1. A **throttle**, per IP. There is no account to key on.
2. A **challenge** (Turnstile), when a key is configured. `Core\Support\Security\Turnstile` is in Core
   beside `SafeUrl` because PL10's sample report is the second public form, and it is **off until a
   key exists — and off means pass**. That looks like the wrong default on a security check and is the
   right one here: an unreachable opt-out form is a worse compliance outcome than a spammable one, and
   a verification Cloudflare cannot answer passes for the same reason.
3. The **verification email**, which is the real authentication. An unverified form is a way to erase
   a competitor's prospects by typing their address into it.
4. **Incuriousness.** No response says whether we hold the person, and none names a team. A form
   anybody can submit that answered "yes, Acme Ltd has your details" would be a lookup tool.

On verification, and in this order: the clock starts (`due_at` from verification, because the
statutory month runs from a request we can act on); the **platform-scope** suppression is written
**first**, because it must not be able to fail after the data is gone — the person erased and
re-contactable is the one outcome worse than doing nothing; and only then is the purge queued.

**Only the email address is acted on.** The click proves control of one thing: a mailbox. A phone
number on the request is recorded and shown to an operator, and is neither suppressed nor erased,
because anybody can type a stranger's number — or a competitor's switchboard — into a public form,
verify it from their own inbox, and have that number blocked across every tenant and erased from all
of them. That is the exact threat the verification step exists to close, and it is a **denial**
rather than a leak, which is the half nobody tests for (ADR 0023 §4a's lesson, one surface further
out). Honouring a number automatically needs the number verified too, which is an SMS round trip and
a §5 question. The form's own copy says we confirm a number before acting on it.

**A repeat submission re-sends and rewrites nothing.** Reusing the pending row is right — two rows
for one person would be answered twice — but letting the second submission refresh the details
looked like a courtesy and was a way for anybody who knows an address to rewrite a pending request's
type, phone and free text before the real person clicks their link. And the re-send sits behind a
**per-address cooldown** in the cache, because the per-IP throttle stops one visitor flooding us and
does nothing about a rotating-IP caller using an unauthenticated endpoint to post a stranger an email
from us every few seconds.

**The purge is queued after the commit, and queued again if it never was.** It used to be dispatched
straight after the transaction: a queue blip there left the row verified, the person told it was
done, and every later click returning early — an erasure lost for good. The trigger is now
`verified_at` set with no `purged_at`, which a second click re-asserts, with `ShouldBeUnique` (and a
`uniqueFor`, so a killed worker does not hold the lock for ever) stopping a re-click queueing a
second walk.

**And `purged_at` is stamped only after a clean pass.** A per-team failure is counted and the job
then **throws**: `purged_at` is what the queue reads as "the deletion half is done", and stamping it
with failures counted beside it reported success while some tenants still held the person — and took
the job out of reach of its own `$tries`. The retry re-walks everything, which is safe because a team
whose records are already gone finds nothing.

**Two of the four request types are automatic** and two are not. An opt-out and an erasure are
mechanical: the person has told us to stop, the suppression and the purge follow from that, nothing a
human adds would make them more correct, and a delay is itself the harm. An access or rectification
request has to be read. An **access request still suppresses**, which is deliberate: somebody asking
what we hold should not be marketed to while they wait for the answer.

**Only an erasure purges.** An opt-out stops contact and keeps the company record — the person asked
us to stop writing to them, not to delete a business from somebody's CRM, and deleting more than was
asked for is its own failure.

### 7. The cross-tenant purge reuses the per-team erasure rather than reimplementing it

`PurgeSubjectDataJob` is the one job in the product that deliberately crosses tenants, and it is
allowed to because the request was made to Alima rather than to a customer. It walks every team and
calls `EraseSubjectDataAction` per team.

That Action already knows the hard parts — which records are erasable, that a message keeps its row
and loses its text, that Signals answers for its own tables, that the suppression is written inside
the same transaction. A second implementation here would be a second answer to "what does erasure
mean", diverging the first time either changed. The only change the Action needed was a **nullable
actor**: nobody at Alima decided this, the data subject did, and a system-attributed audit entry is
the honest record of an obligation rather than a choice.

A failure in one team does not stop the rest — a tenant with a corrupt row must not leave the other
ninety-nine holding the person — and `ComplianceException` is **not** a failure: it is what the
locator throws when there is nothing to find, which is the normal case. But a failure anywhere does
fail the **job**, for the reason above. `ShouldBeUnique` on the request id with a `uniqueFor`, so a
re-clicked link does not walk every team again while the first walk is running and a killed worker
does not hold the lock for ever. The summary holds **counts keyed by category and never a value**,
and `failed()` logs the exception **class** rather than its message — a driver quoting the offending
parameter would turn that line into a copy of the identity somebody asked us to forget.

### 8. The operator queue closes requests; it cannot decide whether to honour them

The admin screen has two routes, and the missing ones are the decision: there is **no** route that
suppresses or purges. Both happen at verification. An operator who could choose not to suppress would
be a compliance hole with a permission on it.

Behind `AdminAbility::ManagePlatformData`, because closing a statutory request is an act on our own
record rather than a read of a tenant's. **Unverified requests are not listed** — a queue that showed
them would move the attack the verification prevents one screen along. A rejection **needs a note**,
checked in the Action and not only in the form, because a refusal is the one outcome a regulator will
ask about and "refused" with no reason is not an accountability record.

### 9. The bulk load splits on separators rather than parsing a CSV

A team arrives with a column out of their CRM. The member says what the values **are**, and then every
cell of whatever they paste or upload is read as one. No column mapping, no header detection, and no
second CSV reader in the codebase — `LeadCsvImporter` stays Leads Workspace's, where its
`company_name` requirement makes sense. A one-column export is lines; so is a copied spreadsheet
column.

Nothing raw is stored and nothing is written to disk. The cap is on **values read per request** rather
than on list size, and a request that hits it **says so** — an import that silently added 1,412 of
5,000 would leave a team believing a list is covered when most of it is not. Team scope and reason
`manual` only, the same rule `SuppressIdentityAction` keeps: a member cannot write a global entry from
the product, because a global entry binds every other tenant.

## Consequences

- **The permissive default is gone from two seams.** A suppressed address can no longer be stored as a
  contact or revealed for a fee. That is a behaviour change for any team with entries on their list,
  and it is the point of the phase.
- **TPS screening does nothing until a licence exists.** Every number reads `unchecked`, the badge is
  absent, and every dial link still works. The seam, the cache, the staleness rule and the UI are
  finished and tested against a fake provider.
- **A new public surface exists** at `/privacy/request`, with its own throttle and no authentication.
  Turnstile keys and the two `SECURITY_CSP_EXTRA_*` hosts it needs are an operator task; `frame-src`
  gained an extra list for it, because a captcha is an iframe and `'none'` cannot be combined with a
  host.
- **Two new platform tables** join `platform_settings` and `platform_daily_metrics` as rows with no
  tenant. Both are swept by `compliance:purge`, which now has a platform step before its per-team one
  — closed requests past their retention window, **unverified** ones past twice their link's lifetime
  (neither a record nor live work, and the thing a clear-text address column cannot be justified by
  keeping), and unused TPS verdicts. An **open** request is never swept however old it is.
- **A phone-bearing public request needs an operator.** The suppression and the purge act on the
  address alone, so a request carrying a number arrives in the queue for a human to finish. Honouring
  it automatically is an SMS verification and an open item.
- **PL6 and PL7 inherit the gate rather than writing their own.** The architecture test's list is where
  they are added, and the export's TPS re-screen reads `TpsScreener::statusFor()` rather than a
  possibly-stale stamp.

## Alternatives considered

- **A second `suppression_entries` table, as the plan names it.** Rejected by ADR 0026 §2d before this
  phase started, and the reason holds: two tables hashing identities is not a missed index, it is an
  email to somebody who opted out.
- **A `tps_status` column per team.** Rejected: the register charges per lookup and its answer is a
  fact about the number. Two customers would pay twice and could disagree.
- **No column on `lead_contacts` at all, reading `tps_checks` on render.** Rejected: a lead screen
  renders every contact it holds, and that is an N+1 on the hottest page in the product — or a join to
  a table with no `team_id`, which is worse. The column is the read path and the cache is the truth;
  the screener reconciles them.
- **A `pecr_subscriber_type` column, as the plan asks.** Rejected: derived from three columns the lead
  already has, so a column is a second answer waiting to drift.
- **Blocking a send to an individual subscriber.** Rejected: it is the customer's call as controller,
  and §5's open questions are not ours to close on their behalf.
- **Failing the privacy-request form closed when no Turnstile key is configured.** Rejected: that
  makes an opt-out unreachable in every environment without a Cloudflare account, which is a worse
  outcome than the spam it prevents.
- **Suppressing and erasing on the phone number the person typed in.** Built first, then removed in
  review: the click authenticates a mailbox and nothing else, so it would let anybody have a
  stranger's number blocked across every tenant. The number is kept and queued instead.
- **Retrying only the teams that failed the purge.** Rejected: it needs a second piece of state that
  can itself go stale, and re-walking costs a query per team over an idempotent operation.
- **A `person_name_company` suppression type**, which the plan lists. Not built: nothing would read it.
  Name-based erasure is `PersonalDataLocator`'s confirmed-candidates path, where a human ticks the
  records, and a hashed `name+company` entry would be a fuzzy match stored as an exact one — the
  failure mode ADR 0021 already wrote down for `CompanyNameKey`. Revisit only with a reader.
