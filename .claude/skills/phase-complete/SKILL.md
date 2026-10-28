---
name: phase-complete
description: The definition-of-done checklist for finishing an mvp.md phase in AlimaProspects — quality gates, tests, docs, status table, and the summary. Use at the end of any phase before reporting it complete.
---

# Completing an mvp.md phase

A phase is **not done** until every box below is ticked. Work the list top to bottom.

## 1. Code quality (run the `quality-gates` skill)
- [ ] `composer analyse` clean (Larastan level 7, 0 errors).
- [ ] `composer test` green (Pint --test + Larastan + Pest).
- [ ] `npm run check`, `npm run types:check`, `npm run build` all pass.
- [ ] No suppressed Larastan errors, no weakened tenancy/transactions/tests to make something pass.

## 2. Tests exist (not just pass)
- [ ] Every Action has a unit/feature test.
- [ ] Every endpoint has a feature test covering auth, validation, tenancy and the happy path.
- [ ] Every tenant-owned model has a test proving cross-tenant access fails.
- [ ] External providers (AI, lead sources, Stripe) are faked — no real network calls in tests.

## 3. Review (optional but recommended)
- [ ] Backend changes reviewed by the `laravel-module-reviewer` agent.
- [ ] UI changes reviewed by the `brand-reviewer` agent.

## 4. Docs updated
- [ ] The module doc(s) `docs/modules/<module>.md`: Code map, Data model, Key flows refreshed + a **Changelog row** appended.
- [ ] `docs/architecture.md` updated if the structure/module map changed.
- [ ] New ADR in `docs/decisions/` for any non-obvious decision made.
- [ ] `docs/ai-prompts.md` updated if a prompt was added/changed; `docs/brand/brand-tokens.md` if brand interpretation changed.
- [ ] `CHANGELOG.md` entry added.

## 5. Plan bookkeeping
- [ ] Phase task checkboxes ticked in `mvp.md`.
- [ ] The **status table** at the top of `mvp.md` updated (status + completion date).

## 6. Commit
- [ ] Small commits with **conventional commit messages**; branch off the default branch (commit/push only when the user asks).
- [ ] Commit message ends with the attribution line from the session reminder.

## 7. Summary to the user
State what was done, anything deferred, and anything the user must provide (API keys, accounts, approvals — e.g. dark-mode palette sign-off, brand master SVGs, provider keys). Then stop and await the next dispatch.
