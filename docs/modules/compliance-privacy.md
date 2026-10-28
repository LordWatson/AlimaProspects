# Compliance & Privacy

> Status: **MVP** · Last updated: 2026-10-28 · See also `docs/compliance.md`

## Purpose

Makes UK/UAE data-protection obligations **enforced by the system**: the suppression / do-not-contact list (global + per team), opt-out handling, data-retention policies and automated purging, data-subject access/erasure (DSAR) tooling for prospects, lawful-basis (LIA) records, team data export, and the prospect privacy notice. It owns these guarantees; other modules call its hooks.

## Planned scope

- `suppressions` (scope: global|team, identity hash: email/domain/phone, reason, source) — checked by Outreach at send time; an opt-out writes **global** suppression honoured across all teams.
- `retention_policies` (per team) + `PurgeExpiredDataJob` (scheduled): purge/anonymise expired leads/contacts, logged to audit.
- **DSAR tooling:** locate all records for an identity within a team → export or verified erasure, with audit trail. ⚖️ identity verification.
- **LIA record** per team gating discovery (see Lead Sourcing); market-aware outreach consent gate (UK legitimate interest vs UAE prior-consent default).
- **Team data export** (all own data, portable format).
- Hosted **privacy notice** for prospects; sender-identity enforcement in Outreach.

## MVP vs post-MVP

MVP: suppression (both scopes) + opt-out + retention purge + team export + LIA gate + privacy notice. DSAR tooling MVP-light (locate + manual erase). Post-MVP: automated DSAR portal, consent-receipt records, per-field redaction.

## Dependencies

Core (audit), Outreach (enforcement point), Lead Sourcing (LIA/ToS), Enrichment (source attribution). Legal sign-off required before launch (⚖️ throughout `docs/compliance.md`).
