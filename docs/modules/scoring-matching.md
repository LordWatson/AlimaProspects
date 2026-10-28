# Scoring & Matching

> Status: **MVP** · Last updated: 2026-10-28

## Purpose
Scores each lead against the active ICP with a **hybrid** method: a deterministic rule-based fit score from ICP weights **plus** an AI qualitative assessment. Stores the score, a grade (A–D), a per-criterion breakdown, and a plain-English "why this lead matches" explanation. Re-scores when the ICP changes and collects user good/bad feedback. It does **not** source or enrich.

## Planned scope
- `DeterministicScorer` service: evaluates `icp_criteria` weights against enriched lead fields → normalised fit score + per-criterion breakdown.
- `ScoreLeadAction`: combines deterministic score with `scoring.qualitative_fit` (AI, reasoning tier) → final score + grade (A–D) + explanation.
- `lead_scores` table (lead, icp, score, grade, breakdown JSON, explanation, scored_at, model/prompt version).
- Re-score on ICP change (event from ICP module) and on enrichment update — queued, idempotent.
- **Feedback loop:** users mark a score good/bad (`score_feedback`) to tune weights / future prompts.
- Inertia: grade badges, breakdown panel, explanation; mobile shows grade + explanation.

## MVP vs post-MVP
MVP: hybrid score, grades, breakdown, explanation, re-score, feedback capture. Post-MVP: learning-to-rank from feedback, confidence intervals, bulk re-score tuning.

## Dependencies
Core, ICP, Enrichment, AI Platform. Feeds Leads Workspace, Reporting, AI Insights.
