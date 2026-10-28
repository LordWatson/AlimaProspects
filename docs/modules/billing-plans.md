# Billing & Plans

> Status: **MVP (late phase)** · Last updated: 2026-10-28

## Purpose
Monetisation via **Stripe (Laravel Cashier)**: plans, trials, seat counts, monthly **lead/AI credit allowances**, metered overage, the Stripe customer portal, and **usage-enforcement middleware** that gates discovery runs and AI calls by plan. It owns subscription + entitlement state; other modules **read** entitlements from it.

## Planned scope
- Cashier wiring: `customers`, `subscriptions`, `subscription_items` (Stripe); plans defined in config.
- **Entitlements:** per-plan monthly lead-discovery allowance + AI-credit allowance + seat count; `UsageMeter` tracking consumption per team per period.
- **Enforcement:** `EnforcesPlanLimits` middleware/guards read by Lead Sourcing (runs), AI Platform (credits) and Auth (seats on invite).
- Metered **overage** billing; trials; customer portal; webhooks (Cashier) for state sync.
- Inertia billing settings (web only — not in mobile); invoices.

## MVP vs post-MVP
MVP: plans, trial, seats, lead + AI allowances with enforcement, portal, overage. Post-MVP: annual plans, discounts/coupons UI, usage-based pricing experiments, tax/VAT handling per market (UK VAT, UAE VAT) ⚖️.

## Dependencies
Core, Auth & Users (seats). Read by AI Platform, Lead Sourcing. Stripe account + keys required.
