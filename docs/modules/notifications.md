# Notifications

> Status: **MVP (in-app + email); push with the mobile app** · Last updated: 2026-10-28

## Purpose
Delivers notifications across channels — **in-app (Reverb)**, **email**, and **mobile push (FCM/APNs via Capacitor)** — with user-level preferences and daily/weekly digests. It owns notification types, delivery and preferences; other modules raise notifications through it rather than sending directly.

## Planned scope
- Laravel notifications + a `notifications` table; broadcast channel via **Reverb** for in-app/toasts.
- Channels: database/in-app, mail, broadcast (Reverb), and push (FCM/APNs) wired when the mobile app lands.
- **Per-user preferences** (which events, which channels) + **digest** scheduling (daily/weekly).
- Notification types raised by: Lead Sourcing (run complete), Scoring (new A-grade leads), Leads Workspace (task due, assignment), Outreach (reply — post-MVP), AI Insights (daily digest), Billing (limit reached, payment).
- Progress events for long jobs also flow over Reverb (job→Reverb→web/mobile).

## MVP vs post-MVP
MVP: in-app (Reverb) + email + preferences + digests. Push (FCM/APNs) ships with the mobile app (post-MVP). Post-MVP: SMS, Slack/Teams, notification center UX polish.

## Dependencies
Core, Reverb, Auth & Users (prefs). Consumed by most modules. FCM/APNs credentials required for push.
