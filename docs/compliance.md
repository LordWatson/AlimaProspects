# Compliance & Privacy — UK & UAE

> Prospect data is **personal data**. AlimaProspects processes it on behalf of tenant businesses across the **United Kingdom and the United Arab Emirates**. This document records the design approach so the rules are **enforced by the system, not by user discipline**. Items marked ⚖️ need a qualified lawyer's review before launch.

## 1. Applicable regimes

| Regime                                                | Scope                    | What it governs for us                                                                                   |
| ----------------------------------------------------- | ------------------------ | -------------------------------------------------------------------------------------------------------- |
| **UK GDPR + Data Protection Act 2018**                | UK data subjects         | Lawful basis, data-subject rights, retention, security, records of processing                            |
| **PECR** (Privacy and Electronic Communications Regs) | UK electronic marketing  | Rules for email/SMS marketing; B2B corporate-subscriber nuances; opt-out                                 |
| **UAE PDPL** — Federal Decree-Law No. 45 of 2021      | UAE data subjects        | Consent/lawful basis, cross-border transfer, data-subject rights, breach notice (to the UAE Data Office) |
| **UAE TDRA / CRA marketing rules & anti-spam**        | UAE electronic marketing | Sender identification, prior-consent expectations, opt-out, prohibited content                           |
| _(watch)_ DIFC DP Law 2020 / ADGM DP Regs             | DIFC / ADGM free zones   | Some UAE businesses sit under free-zone regimes with their own DP law ⚖️                                 |

> ⚖️ UAE marketing law skews more toward **prior consent** than UK B2B legitimate-interest practice. The platform must let a team configure, per market, whether a contact may be messaged, and default to the stricter rule when a prospect's jurisdiction is UAE.

## 2. Lawful basis — design

- **Legitimate interest (LIA)** is the working basis for sourcing and storing **business (role-based) contact data** for B2B outreach, where permitted. The system stores a **recorded Legitimate Interest Assessment** per team (purpose, necessity, balancing test) that must be completed before discovery runs are allowed.
- **Prefer business/role data over personal data.** Favour `info@`, role titles and company records over named-individual personal emails/phones. Flag personal-looking data (e.g. free webmail addresses) distinctly.
- For UAE-jurisdiction prospects, support a **consent / prior-relationship** gate before outreach is enabled, reflecting PDPL/TDRA expectations. ⚖️

## 3. Enforced-by-system requirements

1. **Source attribution on every field.** Every enriched data point stores `source`, `source_url`, `collected_at`, and `method`. No orphan data. (Enrichment & Scoring modules.)
2. **Suppression / do-not-contact.** ✅ **Built (Phase 11)** — `outreach_suppressions`, at **two scopes**: global (platform-wide, honoured by every team) and per-team. Any opt-out is honoured **across all teams** for that identity, matched on a **SHA-256 hash of the normalised value** — an email check also tests its domain, so "stop contacting anyone at this company" means that. The table stores the hash and a masked preview (`j***@example.com`) and **no column holds the address in the clear**: a register of exactly the people who want nothing to do with us is not somewhere to keep their contact details (§3.9). The hash is never exposed over the API and there is deliberately **no lookup endpoint**, which would be an enumeration oracle. `OutreachGuard` checks at draft time _and_ again immediately before transmission, because days may pass in between. Compliance (Phase 15) takes ownership of the table and adds the DSAR and retention tooling over it; it is a real table rather than a stub because a stub that returns "not suppressed" is an opt-out silently ignored.
3. **Opt-out handling.** ✅ **Built (Phase 11, completed Phase 15)** — every sent message carries sender identification (the team's trading name and website, plus one line naming Alima as the transmitter), an unambiguous opt-out and a link to the privacy notice. Recording the outcome "Asked us to stop" writes the contact's **email and phone** to the **global** list immediately, inside the same transaction — somebody who says "stop contacting me" on a call has not given permission to email them instead. A global opt-out is removable by nobody from the product (the model, the policy and the service each refuse it).
    - **Phase 15 made it self-service.** The footer link and the `List-Unsubscribe` header now carry an **HTTPS** target with `List-Unsubscribe-Post: List-Unsubscribe=One-Click` beside it (RFC 8058), so a mail client's own unsubscribe button works — and a recipient who uses it instead of reaching for "report spam" protects every other team's deliverability, not just the sender's. The link is a **signed, expiring URL carrying the message id and nothing else**: unguessable, not reversible into an address, not walkable to find other recipients. The `mailto:` route is still offered alongside it, and is the only one when the endpoint is switched off (`outreach.unsubscribe.enabled`) — advertising one-click against a `mailto:`-only header is read by Gmail and Yahoo as a bad signal.
    - **The GET does nothing.** Mail clients, link checkers and corporate security gateways fetch every URL in an email, so an endpoint that acted on GET would unsubscribe people who never clicked. One-click is a POST, CSRF-exempt because a mail client has no session, with the signature standing in its place. The POST suppresses the address **and** the contact's phone number globally, marks a still-pending approach `opted_out`, and audits it with **no actor** — the recipient is not a user of the platform. An outcome a member has already recorded is left alone; they know something the click does not.
    - **Still not automatic:** an inbound "unsubscribe" **reply** reaches the member's inbox and the platform's opt-out address, and recording it is a manual step.
4. **Retention & purging.** ✅ **Built (Phase 15)** — `retention_policies`, one row per team, with two windows and an off switch; `compliance:purge` runs nightly at 03:40 and queues one job per team. **Two branches, and the split is the policy:** a lead **nobody ever touched** (no approach, no drafted message, no note, no task, no owner, still in the default column) is **deleted** after the uncontacted window; **everything else** is **anonymised** after the contacted window, measured from the newest of `updated_at`, the last logged approach and the last timeline entry. The two cover every lead between them, because a rule with a gap keeps somebody's data for ever and nothing fails.
    - Anonymising removes the contacts, the text and recipient of every message, every attempt note, the team's notes and tasks, the free-text values in timeline entries, the converted news signals and the provider payload in `raw` — **keeping the outreach restriction key**, since clearing `raw` wholesale would quietly reopen cold email on a lead that must never have it (§5a: a restriction may be added but never dropped). What stays is the company's facts, its score, its stage and one row per approach with the channel, outcome and date: the team's evidence of who they contacted and when.
    - **Signals are deleted, not scrubbed**, in both branches. The foreign key is `nullOnDelete` and `signals:prune` never sweeps a converted signal, so one left behind would outlive its lead with a named individual on it and nothing would ever remove it.
    - `leads.anonymised_at` makes the sweep idempotent and tells the screen what happened. `--dry-run` reports and changes nothing, and the settings screen shows the same counts — retention is irreversible, so a team must see what a window change costs before it costs it. A team with **no policy row is still swept** from the configured defaults. Audited with no actor: nobody did it, the policy did.
5. **Data-subject rights (DSAR / erasure).** ✅ **Built (Phase 15, MVP-light)** — locate by address, phone number or name **within one team** (a request is answered per controller; a platform-wide search would disclose one customer's data to another), across contact records, company records whose own name matches, drafted and sent messages, logged approaches, news signals (via `Signals\Services\SignalsPersonalDataExporter` — Phase 15 is its first caller, closing that module's one open dependency) and the do-not-contact list. An address match is exact and normalised through the same code the suppression list uses; a **name match is candidates for a human to confirm**, never an automatic erasure.
    - Erasure deletes contacts, clears message text and recipients, clears attempt notes, hands signals back to Signals' own exporter, and **anonymises** a company matched by name rather than deleting it — the match is fuzzy and deleting a real business on a fuzzy match would be its own failure. ⚖️
    - **It then suppresses the identity** — the part that is easy to leave out and makes the rest pointless. Without it the next discovery run re-sources the same company, AI re-derives the same address, and the person who asked to be forgotten hears from us again. The entry is on the **team's own** list (the request was made to that controller) with reason `erasure`, holds a hash and a mask, and **cannot be lifted**: `SuppressionReason::isLiftable()` is now a second condition beside scope. ⚖️ whether this should instead be platform-wide is for counsel.
    - A **do-not-contact entry is reported and never erased.** A subject asking for it gone is asking for something that works against them, and the screen says so beside the row. Both the search and the erasure are audited with counts and the match type — never the identity, which would put their address in an append-only table no retention sweep touches (§3.9). ⚖️ the identity-verification process for people who are not our users remains open.
6. **Team data export.** ✅ **Built (Phase 15)** — a ZIP of twelve CSVs plus a README: leads, contacts, scores, timeline, notes, tasks, messages, approaches, signals, the team's own do-not-contact entries, its assessments and its retention policy. CSV because the people who ask for this open it in a spreadsheet — a UTF-8 BOM so Arabic reads, and every cell neutralised so a company called `=HYPERLINK(...)` is text rather than a formula. The do-not-contact list is exported **masked**, and platform-wide opt-outs are left out: they belong to the people who made them, not to one tenant's export. Audited **before** the file is sent, metered per member, and a POST rather than a GET so nothing prefetches a copy of a whole tenant's prospect database.
7. **Privacy notice for prospects.** ✅ **Built (Phase 15)** — public, bilingual, at `/privacy`, and linked from every outreach footer (`SenderIdentityResolver` falls back to it when no notice is configured, so a deployment has a working one without setting a variable). It is written for somebody who has just been cold-emailed, in that order: who has your details, where they came from, how to make it stop. It **names Alima as the processor** and explains that the business which made contact is the controller — a per-tenant notice would mean a URL that discloses which team is looking at whom. The particulars (contact address, hosting location, version) come from `config/compliance.php` so counsel can change them without touching code. ⚖️ the wording is part of the pre-launch review.
8. **Audit trail.** Security- and privacy-relevant actions (exports, erasures, suppression changes, impersonation, consent changes) are written to the immutable audit log (Core module).
9. **Data minimisation & security.** Store IDs not secrets; encrypt sensitive columns at rest where warranted; structured logs never contain full personal data (log IDs). Least-privilege roles.
10. **Cross-border transfer.** Record where data is hosted (Hetzner EU) and document the UK↔UAE↔EU transfer position. ⚖️
11. **A restriction follows the subject, not the search.** ✅ **Built (Signals S8, ADR 0019).** Where a lead is created from something the platform _found_, the outreach restriction stamped on it is derived from **where the person is**, not from where the team was looking. In Signals that is `signals.jurisdiction` / `signals.resolved_market`, resolved at extraction from the article's own place by `SignalJurisdictionResolver`; the watch's declared `market` is only a backstop, and the regions it searches only ever **add** a restriction those two did not supply. Three rules make it safe:
    - **Unknown fails toward the restriction.** The resolver returns null rather than guessing, and null is not "unrestricted" — the candidates are then the watch's own market and regions, and the most restrictive of those wins. A guess that fails _away_ from the restriction is the failure mode this requirement exists to prevent.
    - **Most restrictive is asked, not hard-coded.** The ordering comes from `OutreachRestriction::allowsDirectMarketing()`, so a new restriction joins it by answering that question instead of by somebody remembering to edit a `match`.
    - **Withdrawing a region stops the searching, never the restricting.** The switch that decides where a watch may look is a statement about future spending, not about the leads a region has already produced. A lead found under a regime is held to it whatever the configuration says later, so the restriction reads the regions the watch _stored_ and only the query builder reads the ones still offered (ADR 0019 §2a). A compliance switch that relaxes a restriction when you turn it off is worse than no switch.
    - **An ambiguous place resolves to the most restrictive candidate.** Where the source names two jurisdictions, the stricter regime wins, and the team's own configuration is consulted only between candidates that carry the same restriction either way. It must never be the thing that picks — a restriction that depends on the order somebody ticked boxes in is not a rule (ADR 0019 §2b). Specificity is settled first: one place name sitting inside another ("Wales" inside "New South Wales") is one place matched twice, not two places named.
    - **A region with no legal position contributes nothing, and is not offered.** `SearchRegion` knows seven regions because a country code and a language are search facts with no legal content; `config('signals.regions.*.offered')` is true only for the two regimes §1 covers. Mapping an un-positioned region to the UAE flag would have Outreach do the right thing for a reason that is false, and tell a member that reason. Enabling one is a question for §5, not a config change.
      This requirement is the general form of a defect S8 closed before it could be reached: keyed on the watch's market alone, a UK-market watch catching a Dubai event produced a lead with **no** restriction at all. Any future source that infers a person from a published place (S13's registry sources, any lead provider) is held to the same rule.

12. **An automation may prepare an approach. It may never send one.** ✅ **Built (Signals S14, ADR 0024).** A Signals **playbook** is a rule a team owns that fires when a signal matches and does the first few steps unattended — making the lead, assigning it, moving it on, writing an approach. The last of those is the one with a legal consequence, and the position is absolute: the rule **drafts and queues for human review**, and nothing in the platform lets an unattended rule transmit.
    - **It is structural, not a policy somebody could forget.** Signals reaches Outreach through `Signals\Contracts\SignalOutreachDrafter`, which has `channels()`, `tones()` and `draftForReview()` and **no `send`**. `Outreach\Services\PlaybookOutreachDrafter` implements it over `DraftMessageAction`; `SendOutreachEmailAction` is not reachable from any playbook step. A test asserts the interface's method list, so adding a send path fails the suite rather than shipping.
    - **The guard is asked before a token is spent, and a refusal is recorded.** The drafter calls `OutreachGuard::decide()` and returns a refusal carrying the guard's own block reason, label and guidance (never a sentence Signals wrote) when the prospect may not be approached. §3.2's suppression and §3.11's subject-derived restriction therefore both bite on an unattended rule exactly as they bite on a member pressing Draft — a UAE-jurisdiction signal carries `UaePriorConsent`, which nothing published can satisfy, so the step is refused outright. The refusal is written to `signal_playbook_runs` with its reason and shown in the run log, because a compliance decision taken at three in the morning that nobody can see is indistinguishable from one that was never taken.
    - **A configuration refusal does not block a draft**, for §3.3's own reason: no recipient chosen, no verified sender and sending switched off are things the team can fix, and the draft is still text a member may copy into their own mailbox. Only the compliance half is final. That is `OutreachGuard::assertCompliance()`'s split, and a rule is held to the same one a person is.
    - **A rehearsing rule calls no model and reaches nobody** (`PlaybookMode::DryRun`), so the mandatory brake on an automation's blast radius is also the thing that makes it free to test.
      The general rule for anything added later: **anything that reaches a prospect goes through Outreach, and nothing that runs without a person goes past the draft.** A future playbook step, a scheduled digest that offers to "send on your behalf", or a webhook that asks for a message to go out are all held to this; if one of them needs to transmit, it needs a human in the loop and a §5 question answered first, not a new code path.

13. **A restriction shown outside the app travels with the thing it restricts.** ✅ **Built (Signals S15).** The signal round-up is the first thing this product sends that names a specific prospect **where no screen of ours is present** — an inbox, a phone's lock screen — and the next action a member takes after reading one is often taken before they are back in Alima at all.
    - **The approach note is part of the line, not a footnote.** `Notifications\Data\DigestHighlight` carries `approachNote` beside the headline and the email renders them together, so a UAE acquisition cannot be named to somebody without also telling them how that prospect may be approached. A digest that listed the event and left the restriction on the screen they have not opened would be the most effective way this product has of causing an unlawful first contact.
    - **It is read from the signal, never from the team.** `Market::outreachRestriction()` on the signal's own `resolved_market` — the same call `ConvertSignalToLeadAction` makes, so the note in the email and the flag on the lead it becomes cannot disagree. §3.11's rule, applied one surface further out.
    - **Alima's words are keys; the article's are not.** The headline is stored as prose because a journalist wrote it; everything the product says about it is a translation key, so an Arabic reader gets the guidance in Arabic even though the headline is in English.
      The general rule for anything added later: **a surface that can name a prospect must be able to carry the restriction on approaching them, or it does not get to name one.** That applies to a future SMS, a Slack delivery or a webhook as much as it does to this email.

14. **A public register is a register of companies _and_ of people, and we take only the first.** 📋 **Decided (L0, ADR 0025 §3); enforced per source as each is built.** The free sources this plan adds are public by law, which answers whether we may read them and says nothing about which parts we may keep.
    - **A `LeadSourceProvider` stores no named individual, ever.** Officers, PSCs, trustees, registered managers, partners, approved persons and licence holders are dropped **at the mapping step**, where a field is never populated, rather than filtered downstream — a filter is something a later change can forget. This is stricter than the law requires and it is deliberate: a company register's value to us is the company.
    - **A `SignalSourceProvider` may still store a name, a public role and a source URL** and nothing else. ADR 0011's rule, unchanged, and §5a is still the lawful-basis analysis for it. What is new is that a **structured** source can now assert an event, and two of them name people — Companies House filings (already live since S13b) and corporate insolvency notices (L11) — so the §5a minimisation applies to a registry extraction exactly as it does to a journalist's sentence.
    - **Never ingested at all, by either seam:** personal (non-corporate) insolvency notices; individual planning applicants; residential proprietors; any register's named-individual endpoint; and any buyer or seller identity from a property transaction dataset (Dubai Pulse publishes none, and that is why it is usable as market context).
    - **Three sources make this a licence condition rather than a preference.** The Charity Commission's terms oblige us to delete an individual's personal data **on notice** and forbid using it for marketing without consent; the **Open Government Licence does not cover personal data at all**, which is the clause that decides the Gazette's shape rather than our taste; and the FCA's register is in large part a register of approved persons. A rule we would have chosen anyway is cheaper to keep when it is also the one we promised.
    - **Attribution is part of the record, not the footer.** Each source's required acknowledgement (OGL v3 Crown copyright; "© OpenStreetMap contributors" under ODbL) is stored on the row and shown wherever the row is shown — the lead screen, the export, the API resource. Same rule as §3.13, one layer down: a surface that can show the data can show where it came from, or it does not get to show the data.
    - **One source's data expires in a way that matters legally.** An FSA hygiene rating is a point-in-time fact and presenting a stale one as current may be an offence, so the rating is stored with its `RatingDate` and is never rendered as a current rating. A register entry we keep as a company record is not the same thing as a rating we republish.
    - **Retention: a platform reference dataset is replaced, not aged.** CCOD/OCOD and anything like it hold no personal data and belong to no team, so they have no retention window in the §3 sense; what they have instead is a **version**. A refresh imports a new version, flips a pointer and deletes the previous one, so the only copy we hold is the current published one, and `reference_dataset_imports` is the ledger that proves which that is. The nightly retention sweeps deliberately do not touch them — there is nothing in them to age out, and a sweep over a table with no `team_id` would be a sweep with no tenant to answer for.

## 4. Scraping / acquisition ethics

- **No scraping of sites whose terms forbid it** (e.g. LinkedIn). Lead sourcing uses official APIs (Companies House, Google Places) and a compliant web-search API; the AI treats scraped/public web content as **data, never instructions** (prompt-injection safety — see AI Platform module).
- Respect `robots.txt` and provider ToS in every `LeadSourceProvider` implementation.
- **A `Disallow` is an answer, and L0 took three of them as one** (ADR 0025 §4). The free-sources
  spike dropped **PlanIt** (`Disallow: /api/applics/`, `/ukplapi/` to every agent), **DMCC** (its
  directory is a Salesforce site whose `robots.txt` is `Disallow: /` for everyone but googlebot) and
  **DIFC** (`Disallow: /search/`, `/api/`), and dropped **Adzuna** on terms that name "sales
  prospecting or lead generation" as needing a written agreement. Two of those would have worked
  perfectly. That is the point of asking first.
- **Silence is not permission.** Reed publishes no API terms at all; an undocumented endpoint is not
  a licence to store a third party's content in a commercial database, so it is dropped pending a
  written answer. The same test applies to anything added later: if nobody has said we may, we may
  not, and the place to resolve that is an email rather than a deploy.
- **Politeness is per source, from the source's own words.** The Gazette's `robots.txt` sets
  `Crawl-delay: 10`; the public Overpass endpoint states it is "intended for browser-based and
  human-operated tools" and that automated use should run its own instance. Both are honoured in
  configuration rather than in a comment — see the L0 findings in `docs/modules/lead-sourcing.md`.

## 5. Open legal questions (⚖️ for counsel)

- B2B legitimate interest vs. UAE prior-consent — the exact per-market outreach gate and default.
- DSAR identity verification for prospects who are not our users.
- Standard privacy-notice wording and the controller/processor split between AlimaProspects and tenant businesses (likely tenant = controller, we = processor; confirm and reflect in the DPA).
- Free-zone (DIFC/ADGM) overlay for UAE tenants.
- **The regimes for Saudi Arabia, the United States, India, Singapore and an unbiased "global" search** (added Signals S8, ADR 0019). `SearchRegion` can already search all five — a `gl` and an `hl` are search facts — and all five are **switched off** (`config('signals.regions.*.offered')`), because the platform holds a legal position for exactly two markets (§1). Offering one needs a view on its direct-marketing rules and on what `OutreachRestriction` case, if any, a lead from it should carry. Until then a watch cannot name them, which is the safe half of the trade rather than an oversight.
- **Whether "jurisdiction unknown" should be its own `OutreachRestriction` case.** Today an unresolvable place falls back to the most restrictive restriction the watch could imply, and where that is none, none. The cleaner answer is a case meaning "approach by introduction only, basis unestablished" — but what it would _oblige_ is a question for counsel, and inventing its semantics in code would be a compliance claim made by a programmer. Recorded in ADR 0019 §3.
- **The HM Land Registry CCOD/OCOD licence's commercial terms** (added L0, ADR 0025 §2). The
  dataset listing says "See individual licence" rather than the OGL, and the licence is readable only
  inside an account we have not yet created. Third-party summaries describe audit rights, an
  obligation to keep records of end users, and a restriction on the **address** fields because they
  derive from Ordnance Survey and Royal Mail data. Until counsel has read the actual licence, L3
  plans on the **count** of titles a company owns and not on their addresses — the conservative half
  of the trade, chosen because the permissive half would be a licence claim made by a programmer.
- **The FCA's silence on commercial reuse** (added L0). Its Register API is free and its terms say
  nothing about commercial use or attribution, while the FCA separately sells register data extracts.
  Silence beside a paid product is not the same as silence beside an open dataset. A written answer
  from `RegisterAPISupport@fca.org.uk` is a precondition of shipping that source, not a follow-up.
- **Companies House has never stated a licence to us** (noted L0, not new). The developer guidelines
  set rate limits and a ban-on-abuse clause and say nothing about commercial use, storage or
  attribution; the data is Crown copyright. We have operated the REST API on that basis since Phase 7
  and the streaming API (L7) adds no new question — but it is an unanswered one rather than a settled
  one, and it is written down here so nobody mistakes the silence for a permission.
- Retention defaults that satisfy both "storage limitation" and tenants' commercial needs.
- **Sentry as a sub-processor** (added Phase 20, ADR 0017). Error reporting is a processing activity
  and Sentry is a sub-processor, so it needs listing in the processing record and in the DPA's
  sub-processor schedule, with its data region chosen deliberately. What it holds is bounded by
  design and that bound is worth stating in the DPA rather than assuming: `send_default_pii` is off,
  and `Core\Observability\SentryOptions` plus the shared browser scrubber redact the value of every
  key that looks like a credential or personal data **at any depth** — request data, extra context,
  breadcrumb metadata and the user bag — leaving a user **id**, a `team_id`, a `request_id` and a
  stack trace. Session replay, which would record the screen of somebody working a list of named
  prospects, is off; turning it on is a decision for this section, not a configuration change.
  Residual risk, honestly: a breadcrumb or exception **message** is free text and no key-based
  scrubber can inspect a sentence, which is why the logging rule (IDs, never prose or payloads —
  CLAUDE.md §2) is a compliance control and not a style preference.

## 5a. Signals module — personal data from published news (⚖️)

The **Signals** module (ADR 0011) processes a new, small category of personal data:
the **name and public role** of a named individual mentioned in a published business-
news item (a founder, executive or company owner tied to a wealth/liquidity event).
It is designed to the same "enforced-by-system" standard as the rest of this document.

**Status: built (S1–S6, 2026-10-29).** Everything below is implemented and tested, with
the two deviations from the original design noted inline.

- **What is stored.** A signal holds the person's **name, public role and source
  URL** only, plus the AI summary, event metadata and the clamped news snippet. It
  **never** stores an email address or phone number. (Data minimisation, §3.9.)
- **Lawful basis.** **Legitimate interest.** The data is already published business
  news, processed to alert a team to a commercially relevant event, with no contact
  details and no automated outreach. A **dedicated Signals section is added to the
  per-team LIA** (purpose: event-based prospecting; necessity: public news is the only
  source of these events; balancing test: minimal data, published source, no contact
  details, person can object/erase). The LIA must be recorded before watches run,
  mirroring the discovery-run gate (§2).
- **Outreach is gated, not performed.** Signals is **not** an outreach channel. On
  conversion, a UAE-derived lead is flagged **`leads.raw.outreach_restriction =
  'uae_prior_consent'`** and the lead screen shows "Get consent first — reach out on
  LinkedIn or through a referral" — never cold email/SMS — reflecting UAE PDPL / TDRA
  prior-consent expectations (ADR 0007, §1–2). Outreach (Phase 11) must read this flag
  and block non-compliant channels; `docs/modules/outreach.md` records exactly what it
  must do, and `LeadResource` sends the flag on **every** lead so no client can miss it.
  ✅ **Done (Phase 11).** `OutreachGuard` reads it through `allowsDirectMarketing()` rather than by
  comparing cases, and refuses email at **draft** time as well as at send time — the restriction is
  the basis on which the lead was allowed to exist, not a warning to click past. The permitted
  channels are offered instead, the closed one stays on screen **with its reason beside it**, the
  drafting prompt is told to keep its suggested next step to a LinkedIn approach or a referral, and
  `GET /api/v1/leads/{lead}/outreach/decisions` reports the decision per channel so a mobile client
  knows before it offers a compose screen.
    - **Deviation from the original design:** the flag is **not** keyed on the converted
      lead's country, and never was. The restriction protects the person being
      approached, and the company they own may be registered elsewhere — keying it on
      the company's country silently dropped the restriction the moment a Dubai
      founder's lead was filed under a UK holding company, which is precisely the case
      it exists for. It is also **re-asserted after de-duplication**, because a merge
      fills `raw` only when empty and would otherwise lose it. A restriction may be
      **added** to a lead but never dropped from one.
    - **Corrected in Signals S8 (ADR 0019), and this is the current behaviour.** v1 keyed
      the flag on the **watch's market**, which was indistinguishable from "where the
      person is" only because every watch was UAE-only. S8 let a watch span places and
      the conflation became wrong in both directions — over-restrictive for a London
      founder found by a UAE watch, and **permissive** for a Dubai founder found by a UK
      watch, who would have been stamped with nothing at all. The flag now comes from
      the signal's own `resolved_market` first, the watch's `market` as a backstop, then
      the most restrictive across the watch's regions, each step adding and none
      removing (§3.11 states the general rule, and `leads.raw.jurisdiction` records the
      code the flag was derived from so a lead can explain itself later). The fix shipped
      in the same phase that made the defect reachable rather than after it.
- **Retention.** Dismissed signals are hard-deleted after **90 days**; signals nobody
  acts on after **180 days**; converted signals persist as long as their lead does,
  because they are that lead's provenance. The **`signals:prune`** command enforces
  this — scheduled nightly at 03:20, chunked (500 rows a pass), idempotent, sweeping
  every team, and logging **counts and windows only**. `--dry-run` reports what it
  would delete without deleting it, because retention is irreversible. Windows are
  configurable via `SIGNALS_RETENTION_DISMISSED_DAYS` / `_UNACTIONED_DAYS`.
    - Measured on **`created_at`, not `published_at`**: the promise is about how long
      Alima holds the record, and an article's own date is outside our control (and
      sometimes a best-effort parse of "3 days ago").
    - The feed shows **"Deletes in N days"** on every unconverted signal, computed from
      the same window the sweep uses, so retention never removes something a team
      assumed was kept.
    - **S11's two tables are swept by the same command, and neither holds a person.** A
      `signal_rejections` row is two hashes, a verdict and a tally — no headline, no URL, no outlet,
      no name — kept for the same **30 days** the run honours the rejection for, so the sweep
      cannot delete a memory the run still trusts or keep one it has stopped trusting
      (`SIGNALS_REJECTION_RETENTION_DAYS`, read by both through
      `SignalRejectionLedger::window()`). A `signal_clusters` row carries a **company-name**
      fingerprint and has **no window of its own**: it is deleted when the last signal in it has
      gone, swept **after** the signals for that reason. That is the control that matters here —
      without it a subject fingerprint would outlive every row it was derived from. The fingerprint
      is deliberately a company name and never a person's, which is why a signal naming only an
      individual is left unclustered rather than given a pseudonymous key nothing would sweep.
    - **S16 gave a window to every other table the feature has** — ten in all, swept by the same
      command. The windows are three different lengths because the tables answer three different
      questions, and the **group** a table is in is what matters rather than the number:
        - _A record about a person or a company_ gets the shortest window the product can live with.
          That is the two signal windows above, plus **`signal_playbook_runs` at 180 days** — the
          record of how an automation handled a prospect's event is data about that prospect. It
          already dies **with** its signal by cascade; the window exists for the one case the
          cascade cannot reach, which is a **converted** signal, never swept, whose log would
          otherwise be kept for as long as the lead.
        - _Cost history_ is kept in arrears: **`signal_runs` and `signal_definition_spend` at 400
          days**. Both hold counts and money and name nobody. Only **finished** runs are swept, at
          any age — a queued row is live state a worker is about to claim. `signals.signal_run_id`
          is `nullOnDelete`, so a signal whose run is swept keeps its row and loses the link: the
          signal is the record about a company and the run is the cost of having found it.
        - _Working state_ is swept when the thing it was about goes, or on a rolling window.
          **`signal_previews` at 30 days** (a draft hash, three counts and a cost — no headline, no
          result); **`signal_source_stats` at 180 days from `last_seen_at`**, so an outlet the team
          still reads is never swept however old the row is; **`signal_watchlist_entries`** on no
          clock at all — an entry goes when the lead it mirrors goes, which the nightly pipeline
          sync already does for a live list and deliberately does not for a **paused** one;
          and **`signal_inbound_events` at 30 days** (S13b), the customer's own pushed body.
        - Every sweep re-applies its eligibility query **at the `DELETE`**, through one shared
          helper. Reading ids and then deleting by id alone is the bug this command has already had
          once, and one helper is what stops the eleventh table reintroducing it.
- **Data-subject rights.** `Signals\Services\SignalsPersonalDataExporter` locates
  signals by `subject_person_name` **within one team** (case-insensitive, with `LIKE`
  wildcards escaped so a name containing `%` searches for itself), for the Compliance
  module's DSAR tooling (§3.5) to call. `export()` returns the whole of what Signals
  holds — there is no email or phone to report, because the module stores neither —
  with each row flagged `erasable`. `erase()` deletes the erasable matches and
  reports the rest.
    - **Three tables since S16, not one**, because the feature grew two more places a name can sit
      and a DSAR that searched one of three would be a false answer given confidently:
        - **`signal_watchlist_entries`** can hold a _person_ (S10). A named individual on a
          watchlist is a standing instruction to search the news for them, which is squarely the
          sort of processing a subject would want stopped — and erasing the signals while leaving
          the entry means the next run goes and finds them again, which is the one erasure outcome
          worse than refusing. **Person entries only:** a company entry's name is a company, and
          "J. Okafor Consulting" is a business whose name happens to contain a person's. Aliases are
          searched too, because a subject held only under the name this team gave them is exactly
          what a DSAR exists to surface.
        - **`signal_inbound_events`** holds the body a customer pushed us, whole, for thirty days
          (S13b). Alima did not choose what is in it, which is why it has to be searched: a request
          saying "Jane Okafor has been appointed CFO" is personal data we are processing whether or
          not our own classifier ever read it. The match is a contains-search over the serialised
          JSON and therefore **over-reports** — the safe direction for candidates a human confirms,
          and the right trade for erasure as well, since the row is a record of an HTTP request and
          the signal it produced is a separate row with its own retention.
          Each row carries a `record` key naming its table, and Compliance maps the three to **three
          categories** — a news signal, a watched account and a reported event — because what a subject
          needs to be told is the difference between something we found, something we are looking for,
          and something somebody else told us about them.
    - **An erasure repairs the cluster it broke, immediately.** Taking the article a cluster was
      being shown as leaves the feed showing every remaining member as a separate card until the
      nightly sweep reconciles it, and a subject access request is answered to a statutory deadline
      rather than overnight. A cluster the erasure emptied is deleted, which is what stops its
      company-name fingerprint outliving every row it came from.
    - **Verified on MySQL, not only SQLite.** `tests/Feature/Signals/SignalsMysqlDialectTest.php`
      runs the matching and the sweeps against the real engine, gated on an env var naming a
      throwaway schema. It exists because the escape character in these very queries could not be a
      backslash — in MySQL `'\'` is an unterminated string literal and in SQLite it is fine — a bug
      that passed every test and would have failed every production query.
    - **Two limits of the payload search, stated plainly.** Laravel's `array` cast serialises with
      `json_encode`'s defaults, so on a driver with no native JSON type — SQLite, which the suite
      runs on — an **Arabic** name is stored as `\u` escapes and a `LIKE` over the column matches
      nothing. MySQL's `json` type normalises that away, so production is correct and only the
      env-gated MySQL test demonstrates it; the three flat columns matched beside the payload
      (`title`, `summary`, `source_name`) are plain strings and match either way, so the gap is an
      event naming somebody only in a nested key **and** only in Arabic. And the export returns the
      body **whole**, so an export answering for one person may contain a sentence about a
      colleague — the alternative, redacting to the matching fragment, would mean telling a subject
      less than we hold while claiming to have answered, which is the worse of the two failures, and
      the human review step before anything is sent is what the design relies on instead.
    - **One more limitation stated plainly:** erasing a _synced_ watchlist entry is undone by the
      next pipeline sync if the `leads` row behind it survives. That row is Compliance's to erase, and
      the same request's Leads Workspace pass is what handles it — so a request answered through
      `EraseSubjectDataAction` covers both and one made by calling this exporter alone does not.
    - **Deviation from the original design:** a **converted** signal is reported but
      **not erased**. It is the provenance of a lead someone is actively working, and
      removing it would leave the team unable to say where that lead came from — the
      opposite of the accountability the request is exercising. `erase()` returns
      `retained` and `retained_signal_ids` so the decision is explicit; Phase 15 decides
      what happens to the lead, and the signal goes with that decision.
    - Matching is **by name and therefore imprecise**; results are candidates for a human
      to confirm, never an automatic erasure ("J. Smith" is not reliably "John Smith").
      Names shorter than 3 characters are refused outright.
    - Identity verification for non-users remains an open legal question (§5).
- **Acquisition ethics.** Signals uses the **Serper News API** (ADR 0010/0011), not
  scraping. The AI treats article text as **data, never instructions** (§4, AI Platform
  prompt-injection safety). Property portals and aggregators are excluded globally, and
  each team can exclude its own list of sources (`signal_watches.ignore_domains`).
    - **Snippet-only was the v1 position; since S12 it is the default rather than the
      rule.** `SIGNALS_ARTICLE_FETCH_ENABLED` (off) lets the module read the **whole
      article** for the few signals at the top of a team's feed, to correct an amount, a
      role or a company name that a search snippet could only guess at. Five things bound
      it: the flag, a heat floor, a per-run count, a per-team monthly cost ceiling, and
      **`robots.txt`** (`Core\Support\RobotsPolicy` — fetched per host, cached, and
      failing **toward** refusal when it cannot be read). The fetch itself goes through
      `Core\Contracts\WebContentFetcher`, the same SSRF-safe seam Enrichment uses.
    - **Data minimisation survives it, because the body is not stored.** The article is
      read, used to re-extract the same five fields the snippet produced, and dropped:
      `signals.article_fetched_at` records _that_ a page was read and nothing of what it
      said. A signal still holds a name, a role, a company and a URL (ADR 0011), which is
      what the retention sweep deletes and what the DSAR exporter exports. Keeping prose
      would make both harder and is the one thing this feature deliberately does not do.
    - Reading a published page a team was already shown the headline of does not change the
      lawful basis, the LIA or the UAE prior-consent flag — the subject, the purpose and
      the source are the same. ⚖️ Worth confirming with counsel before the flag is turned
      on for a customer, because "we read the article" is a sentence a regulator may read
      differently from "we read the search result".
- **Audit trail.** Watch create/update/delete, convert, dismiss and restore all write
  audit entries carrying **ids, enum values and counts only** — never a headline, a
  person's name, or a team's competitor list. A watch deletion records how many signals
  and runs went with it, since that is the only place those counts survive the cascade.

⚖️ This section forms part of the **pre-launch legal review already required by
ADR 0007** (UK-B2B-legitimate-interest vs UAE-prior-consent, and the
controller/processor split). Confirm the LIA wording, the UAE outreach gate, and the
DSAR identity-verification process for named individuals who are not our users.

## 6. Mapping to modules

Compliance & Privacy module owns suppression, retention jobs, DSAR tooling, privacy-notice hosting and the LIA record. Enforcement hooks live in Lead Sourcing (ToS/robots — and since L0, per-source attribution on the row and a no-named-individuals rule at the mapping step, ADR 0025 §3), Enrichment (source attribution), **Signals** (data minimisation — name/role/URL only, no article body stored even behind S12's full-article fetch, a retention window on **every** one of its ten tables since S16 and a DSAR exporter reaching three of them, UAE prior-consent flag on conversion — §5a), Outreach (suppression + opt-out + sender ID — **all built in Phase 11**, including the Signals UAE flag, which it honours at draft time as well as send time), Core (audit log), and Billing (nothing directly, but plan limits must not incentivise non-compliant volume — and since Signals S16 a plan is also what bounds how much news a team may have searched about named individuals, which makes the allowance a data-minimisation control as well as a margin one).
