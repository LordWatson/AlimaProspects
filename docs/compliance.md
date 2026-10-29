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
2. **Suppression / do-not-contact.** A suppression list exists at **two scopes**: global (platform-wide, e.g. a person who opted out anywhere) and per-team. Any opt-out is honoured **across all teams** for that identity (email/domain/phone hash). Outreach actions check suppression at send time and block.
3. **Opt-out handling.** Every outbound message includes an unambiguous opt-out and sender identity. Processing an opt-out writes to the global suppression list immediately.
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
  conversion, a UAE-derived lead is flagged **`outreach_restriction =
  'uae_prior_consent'`** and the UI shows "Approach via LinkedIn or a referral" — never
  cold email/SMS — reflecting UAE PDPL / TDRA prior-consent expectations (ADR 0007,
  §1–2). Outreach (Phase 11) must read this flag and block non-compliant channels.
- **Retention.** Dismissed signals are hard-deleted after **90 days**; signals nobody
  acts on after **180 days**; converted signals persist as long as their lead does. A
  scheduled, chunked `signals:prune` sweep enforces this and logs counts only
  (configurable per team, built in S6). The UI shows "auto-deletes in N days" on
  dismissed signals.
- **Data-subject rights.** A `SignalsPersonalDataExporter` / eraser locates signals by
  `subject_person_name` within a team, for the Compliance module's DSAR tooling
  (§3.5) to call (built in S6). Identity verification for non-users remains an open
  legal question (§5).
- **Acquisition ethics.** Signals uses the **Serper News API** (ADR 0010/0011), not
  scraping, and is **snippet-only** (no full-page fetch in v1). The AI treats article
  text as **data, never instructions** (§4, AI Platform prompt-injection safety).

⚖️ This section forms part of the **pre-launch legal review already required by
ADR 0007** (UK-B2B-legitimate-interest vs UAE-prior-consent, and the
controller/processor split). Confirm the LIA wording, the UAE outreach gate, and the
DSAR identity-verification process for named individuals who are not our users.

## 6. Mapping to modules

Compliance & Privacy module owns suppression, retention jobs, DSAR tooling, privacy-notice hosting and the LIA record. Enforcement hooks live in Lead Sourcing (ToS/robots), Enrichment (source attribution), **Signals** (data minimisation — name/role/URL only, snippet-only, own retention sweep + DSAR exporter, UAE prior-consent flag on conversion — §5a), Outreach (suppression + opt-out + sender ID, and it must honour the Signals UAE flag), Core (audit log), and Billing (nothing, but plan limits must not incentivise non-compliant volume).
