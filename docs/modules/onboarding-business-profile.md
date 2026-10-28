# Onboarding & Business Profile

> Status: **MVP** · Last updated: 2026-10-28

## Purpose

The guided wizard a new team completes to describe itself, and the stored **Business Profile** that result feeds the AI background check, ICP suggestions, scoring and outreach personalisation. Owns the wizard state (progress saved per step) and the canonical self-description of the tenant business. It does **not** own the AI-researched profile (Business Intelligence) — this is the business's own words; BI augments it.

## Planned scope

- Multi-step wizard with **progress saved per step** (resumable): company details, website, industry, size, location(s), regions served (UK/UAE/other), products/services, value proposition, pricing range, existing customer examples.
- `business_profiles` table (one per team, versionable) + `onboarding_progress`.
- Actions: `SaveOnboardingStepAction`, `CompleteOnboardingAction` (dispatches the BI background-check job `afterCommit`).
- Inertia wizard pages + shared validation; mobile shows read-only summary.
- Bilingual fields where relevant; market selection drives compliance defaults.

## MVP vs post-MVP

MVP. Post-MVP: multiple business profiles per team, logo/brand capture.

## Dependencies

Core, Auth & Users. Triggers Business Intelligence; read by ICP, Scoring, Outreach.
