# Outreach

> Status: **MVP (draft + copy + manual log + basic send)** · Last updated: 2026-10-29

## Purpose

Helps users contact leads: AI-personalised message drafts (email, LinkedIn message text to copy, call script) built from the business profile + lead data, with templates and tone controls, and an activity log of every contact attempt and outcome. It owns messages, templates and contact-attempt logging; it does **not** own pipeline state (Leads Workspace) or suppression rules (Compliance — which it must honour).

## Planned scope

**MVP**

- `outreach.draft_message` (AI, reasoning tier): channel + tone controls → `MessageDraft` (subject/body/variants). Injection-safe (lead data is data).
- `outreach_templates` (team, channel, tone), `outreach_messages` (draft → sent/logged), `contact_attempts` (channel, outcome, timestamp).
- Draft → **copy** (LinkedIn/call script) and manual **log**; **send email** via the team's connected mailbox or SMTP (feasibility-gated).
- **Suppression + opt-out + sender-ID checks at send time** (Compliance). Per-market (UK/UAE) gate on whether a contact may be messaged.
- **Honour `leads.raw.outreach_restriction`** — see "Per-lead outreach restrictions" below. Non-negotiable, and the one thing in this module that already has a caller waiting on it.
- Bilingual drafting (EN/AR).

**Post-MVP**

- Multi-step sequences, Gmail/Outlook OAuth sending, open/reply tracking, unsubscribe link handling, A/B message testing.

## Per-lead outreach restrictions (required — added by Signals, S4)

Some leads arrive carrying a **legal limit on how they may be approached**, recorded by whichever
module created them. The flag lives on the lead itself, as
`leads.raw.outreach_restriction`, and is modelled by `App\Modules\Core\Enums\OutreachRestriction`
(in Core, not in Signals: it is a property of the lead, not of where the lead came from).

**What this module must do:**

1. Read the restriction with `OutreachRestriction::fromRaw($lead->raw)` — never by spelling the
   `raw` key by hand — and branch on `allowsDirectMarketing()`, never on the case itself, so a
   restriction added later can't be silently missed by a `match` that still compiles.
2. When it returns `false`, **refuse email and SMS outright** at draft time as well as at send
   time. Not a warning the member can click past: the restriction is the basis on which the lead
   was allowed to exist at all.
3. Offer the permitted channels instead — the LinkedIn message text and the call script — and keep
   the drafting prompt's suggested next step to a LinkedIn approach or a referral introduction.
4. Surface it in the API and on mobile. `LeadResource` already sends `outreach_restriction` on
   **every** lead (value, label, guidance, `allows_direct_marketing`), so no client has to ask for
   it separately; the lead detail screen shows it as a note already.

**The one case in v1:** `uae_prior_consent`, set on every lead converted from a UAE signal. UAE PDPL
requires the person's prior consent for direct marketing, and a news article plainly isn't consent
(ADR 0007, ADR 0011). The guidance string is "Approach via LinkedIn or a referral — not cold email."

A restriction may be **added** to a lead but never dropped from one: `ConvertSignalToLeadAction`
re-asserts it after de-duplication precisely so a merge can't lose it. This module must treat it the
same way — the absence of a restriction means "no limit beyond the team's normal basis", never
"cleared to cold-email", which stays the Legitimate Interest Assessment's call (Compliance).

## Dependencies

Core, Business Intelligence/Business Profile, Leads Workspace, AI Platform, Compliance, Notifications. Feeds Reporting, AI Insights.
