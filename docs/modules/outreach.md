# Outreach

> Status: **MVP (draft + copy + manual log + basic send)** · Last updated: 2026-10-28

## Purpose
Helps users contact leads: AI-personalised message drafts (email, LinkedIn message text to copy, call script) built from the business profile + lead data, with templates and tone controls, and an activity log of every contact attempt and outcome. It owns messages, templates and contact-attempt logging; it does **not** own pipeline state (Leads Workspace) or suppression rules (Compliance — which it must honour).

## Planned scope
**MVP**
- `outreach.draft_message` (AI, reasoning tier): channel + tone controls → `MessageDraft` (subject/body/variants). Injection-safe (lead data is data).
- `outreach_templates` (team, channel, tone), `outreach_messages` (draft → sent/logged), `contact_attempts` (channel, outcome, timestamp).
- Draft → **copy** (LinkedIn/call script) and manual **log**; **send email** via the team's connected mailbox or SMTP (feasibility-gated).
- **Suppression + opt-out + sender-ID checks at send time** (Compliance). Per-market (UK/UAE) gate on whether a contact may be messaged.
- Bilingual drafting (EN/AR).

**Post-MVP**
- Multi-step sequences, Gmail/Outlook OAuth sending, open/reply tracking, unsubscribe link handling, A/B message testing.

## Dependencies
Core, Business Intelligence/Business Profile, Leads Workspace, AI Platform, Compliance, Notifications. Feeds Reporting, AI Insights.
