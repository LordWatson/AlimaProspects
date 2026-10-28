# ADR 0003 — Frontend lint/format via vite-plus (not standalone ESLint/Prettier)

- **Status:** Accepted
- **Date:** 2026-10-28

## Context
The kickoff spec asked for **ESLint + Prettier + vue-tsc**. The official Laravel Vue starter kit (the stack mandated by ADR 0001) ships a different, integrated frontend toolchain: **`vite-plus`** (`vite` aliased to `@voidzero-dev/vite-plus-core`) exposing `npm run check` (lint + format), `check:fix`, and `vue-tsc` via `types:check`.

## Decision
Adopt the starter kit's toolchain as-is rather than ripping it out for standalone ESLint/Prettier:
- `npm run check` / `check:fix` — lint + format (the ESLint+Prettier equivalent).
- `npm run types:check` — `vue-tsc --noEmit` (unchanged from spec).
- CI runs `check` + `types:check` + build.

## Consequences
- Satisfies the **intent** (automated lint, format and type-check in CI) with less config drift and fewer moving parts than re-adding ESLint/Prettier on top.
- If the team later needs specific ESLint rules not covered by `vp check`, revisit and add ESLint alongside (new ADR).
- Documented so the deviation from the literal spec is intentional and traceable.
