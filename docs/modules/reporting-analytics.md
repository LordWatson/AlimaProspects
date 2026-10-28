# Reporting & Analytics

> Status: **MVP (core dashboard)** · Last updated: 2026-10-28

## Purpose
Shows a team how it is doing: dashboard KPIs (leads found, by grade, contacted, replies, meetings, conversions), a funnel by pipeline stage, source performance, ICP performance, team-member activity, and AI usage/credits. Date-range filters and CSV export. It reads from other modules; it owns only aggregation/reporting read-models and saved report config.

## Planned scope
- Read-model/aggregation services over leads, scores, pipeline, outreach, AI usage (no new source-of-truth tables; optional rollup tables for performance).
- Dashboard KPIs + funnel; **source performance** (which provider yields best grades), **ICP performance**, **team activity**, **AI usage/credits** (from AI Platform).
- Date-range filters; **CSV export** of any report.
- Inertia dashboard (uses the dataviz guidance for charts); mobile shows headline KPIs.

## MVP vs post-MVP
MVP: dashboard KPIs, funnel, source + ICP performance, AI usage, CSV export. Post-MVP: scheduled email reports, custom report builder, cohort analysis.

## Dependencies
Core, Scoring, Leads Workspace, Outreach, AI Platform. Pairs with AI Insights.
