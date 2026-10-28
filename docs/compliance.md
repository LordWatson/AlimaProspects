# Compliance & Privacy — UK & UAE

> Prospect data is **personal data**. AlimaProspects processes it on behalf of tenant businesses across the **United Kingdom and the United Arab Emirates**. This document records the design approach so the rules are **enforced by the system, not by user discipline**. Items marked ⚖️ need a qualified lawyer's review before launch.

## 1. Applicable regimes

| Regime | Scope | What it governs for us |
|---|---|---|
| **UK GDPR + Data Protection Act 2018** | UK data subjects | Lawful basis, data-subject rights, retention, security, records of processing |
| **PECR** (Privacy and Electronic Communications Regs) | UK electronic marketing | Rules for email/SMS marketing; B2B corporate-subscriber nuances; opt-out |
| **UAE PDPL** — Federal Decree-Law No. 45 of 2021 | UAE data subjects | Consent/lawful basis, cross-border transfer, data-subject rights, breach notice (to the UAE Data Office) |
| **UAE TDRA / CRA marketing rules & anti-spam** | UAE electronic marketing | Sender identification, prior-consent expectations, opt-out, prohibited content |
| *(watch)* DIFC DP Law 2020 / ADGM DP Regs | DIFC / ADGM free zones | Some UAE businesses sit under free-zone regimes with their own DP law ⚖️ |

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

## 6. Mapping to modules
Compliance & Privacy module owns suppression, retention jobs, DSAR tooling, privacy-notice hosting and the LIA record. Enforcement hooks live in Lead Sourcing (ToS/robots), Enrichment (source attribution), Outreach (suppression + opt-out + sender ID), Core (audit log), and Billing (nothing, but plan limits must not incentivise non-compliant volume).
