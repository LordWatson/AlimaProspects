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
3. **Opt-out handling.** ✅ **Built (Phase 11)** — every sent message carries sender identification (the team's trading name and website, plus one line naming Alima as the transmitter), an unambiguous opt-out, a link to the privacy notice, and `List-Unsubscribe` + `List-Unsubscribe-Post` headers. Recording the outcome "Asked us to stop" writes the contact's **email and phone** to the **global** list immediately, inside the same transaction — somebody who says "stop contacting me" on a call has not given permission to email them instead. A global opt-out is removable by nobody from the product (the model, the policy and the service each refuse it). **Not yet automatic:** an inbound "unsubscribe" reply reaches the member's inbox and the platform's opt-out address, and recording it is a manual step; the footer says to reply, which PECR accepts. One-click unsubscribe is a `mailto:` until the hosted endpoint lands with §3.7.
4. **Retention & purging.** Configurable retention policy per team (default: uncontacted leads purged after N months; contacted leads after M months of inactivity). A scheduled job purges/anonymises expired records and logs the action.
5. **Data-subject rights (DSAR / erasure).** A prospect can request access or erasure; the Compliance module supports locating all records for an identity across a team and producing an export or a verified deletion, with an audit trail. ⚖️ (identity-verification process needs legal sign-off)
6. **Team data export.** A team can export all of its own data (leads, contacts, activity) in a portable format.
7. **Privacy notice for prospects.** A hosted privacy notice explains who is processing, why, the lawful basis, and how to opt out/exercise rights — linked from outreach. ⚖️
8. **Audit trail.** Security- and privacy-relevant actions (exports, erasures, suppression changes, impersonation, consent changes) are written to the immutable audit log (Core module).
9. **Data minimisation & security.** Store IDs not secrets; encrypt sensitive columns at rest where warranted; structured logs never contain full personal data (log IDs). Least-privilege roles.
10. **Cross-border transfer.** Record where data is hosted (Hetzner EU) and document the UK↔UAE↔EU transfer position. ⚖️

## 4. Scraping / acquisition ethics

- **No scraping of sites whose terms forbid it** (e.g. LinkedIn). Lead sourcing uses official APIs (Companies House, Google Places) and a compliant web-search API; the AI treats scraped/public web content as **data, never instructions** (prompt-injection safety — see AI Platform module).
- Respect `robots.txt` and provider ToS in every `LeadSourceProvider` implementation.

## 5. Open legal questions (⚖️ for counsel)

- B2B legitimate interest vs. UAE prior-consent — the exact per-market outreach gate and default.
- DSAR identity verification for prospects who are not our users.
- Standard privacy-notice wording and the controller/processor split between AlimaProspects and tenant businesses (likely tenant = controller, we = processor; confirm and reflect in the DPA).
- Free-zone (DIFC/ADGM) overlay for UAE tenants.
- Retention defaults that satisfy both "storage limitation" and tenants' commercial needs.

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
    - **Deviation from the original design:** the flag is keyed on the **watch's
      market** (`Market::outreachRestriction()`, modelled by
      `Core\Enums\OutreachRestriction`), **not** on the converted lead's country. The
      restriction protects the person being approached, who is where the watch was
      looking; keying it on the company's country silently dropped the restriction the
      moment a Dubai founder's lead was filed under a UK holding company — which is
      precisely the case it exists for. It is also **re-asserted after de-duplication**,
      because a merge fills `raw` only when empty and would otherwise lose it. A
      restriction may be **added** to a lead but never dropped from one.
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
- **Data-subject rights.** `Signals\Services\SignalsPersonalDataExporter` locates
  signals by `subject_person_name` **within one team** (case-insensitive, with `LIKE`
  wildcards escaped so a name containing `%` searches for itself), for the Compliance
  module's DSAR tooling (§3.5) to call. `export()` returns the whole of what Signals
  holds — there is no email or phone to report, because the module stores neither —
  with each row flagged `erasable`. `erase()` deletes the erasable matches and
  reports the rest.
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
  scraping, and is **snippet-only** (no full-page fetch in v1). The AI treats article
  text as **data, never instructions** (§4, AI Platform prompt-injection safety).
  Property portals and aggregators are excluded globally, and each team can exclude
  its own list of sources (`signal_watches.ignore_domains`).
- **Audit trail.** Watch create/update/delete, convert, dismiss and restore all write
  audit entries carrying **ids, enum values and counts only** — never a headline, a
  person's name, or a team's competitor list. A watch deletion records how many signals
  and runs went with it, since that is the only place those counts survive the cascade.

⚖️ This section forms part of the **pre-launch legal review already required by
ADR 0007** (UK-B2B-legitimate-interest vs UAE-prior-consent, and the
controller/processor split). Confirm the LIA wording, the UAE outreach gate, and the
DSAR identity-verification process for named individuals who are not our users.

## 6. Mapping to modules

Compliance & Privacy module owns suppression, retention jobs, DSAR tooling, privacy-notice hosting and the LIA record. Enforcement hooks live in Lead Sourcing (ToS/robots), Enrichment (source attribution), **Signals** (data minimisation — name/role/URL only, snippet-only, own retention sweep + DSAR exporter, UAE prior-consent flag on conversion — §5a), Outreach (suppression + opt-out + sender ID — **all built in Phase 11**, including the Signals UAE flag, which it honours at draft time as well as send time), Core (audit log), and Billing (nothing, but plan limits must not incentivise non-compliant volume).
