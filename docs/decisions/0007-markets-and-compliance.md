# ADR 0007 — Target markets (UK + UAE), bilingual, compliance baseline

- **Status:** Accepted
- **Date:** 2026-10-28

## Context
The kickoff confirmed primary markets are the **United Kingdom and the United Arab Emirates**. The Alima brand itself ships Arabic (Tajawal) typography and UAE (`+971`) details.

## Decision
- **Compliance baseline = UK GDPR + PECR *and* UAE PDPL (Decree-Law 45/2021) + UAE TDRA marketing rules.** When a prospect's jurisdiction is UAE, apply the stricter (prior-consent-leaning) outreach gate. Full approach in `docs/compliance.md`.
- **Bilingual EN/AR with RTL** is a first-class requirement, designed in from the **brand design-system / app-shell phase**, not retrofitted. Arabic uses Tajawal, set 10–15% larger, mirrored layouts (never mirror the logo).
- **Lead sourcing:** Companies House covers UK only; UAE discovery leans on Google Places + web search (no free UAE registry).

## Consequences
- i18n scaffolding (locale files, RTL-aware components, `dir` handling) is part of the design-system phase.
- Compliance features (suppression, opt-out, retention, DSAR, LIA, privacy notice) are **MVP**, not post-MVP, and enforced by the system.
- Legal review (⚖️) required before launch on the UK-B2B-LI vs UAE-prior-consent question and the controller/processor split.
