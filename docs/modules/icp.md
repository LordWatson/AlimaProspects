# Ideal Customer Profile (ICP)

> Status: **MVP** · Last updated: 2026-10-28

## Purpose

Lets a team define one or more **Ideal Customer Profiles** — the structured, weighted criteria that drive lead discovery and scoring. Owns ICP records, their criteria and weights, and exclusions. It does **not** compute scores (Scoring & Matching) or source leads (Lead Sourcing); it provides the definition both consume.

## Planned scope

- `icps` table (team-scoped, name, active flag) + `icp_criteria` (type, value, **weight**).
- Criteria: target industries, company size bands, geography (UK/UAE/regions), decision-maker job titles/seniority, technographics, buying signals.
- **Exclusions:** competitors, existing customers, specific domains, blocklists.
- Per-criterion **weighting** that feeds the deterministic score.
- Actions: `CreateIcpAction`, `UpdateIcpAction`, `SetActiveIcpAction`. Updating criteria/weights **triggers re-scoring** (event consumed by Scoring).
- AI-assisted draft: accept suggested ICP from Business Intelligence (`icp.refine_suggestions`).
- Inertia editor with weight sliders; mobile read-only.

## MVP vs post-MVP

MVP: one active ICP per team with full criteria + weights + exclusions. Multiple saved ICPs MVP-if-cheap. Post-MVP: ICP templates library, A/B ICPs.

## Dependencies

Core, Onboarding/Business Profile, AI Platform (suggestions). Drives Lead Sourcing, Scoring.
