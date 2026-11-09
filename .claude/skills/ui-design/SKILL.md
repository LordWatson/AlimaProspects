---
name: ui-design
description: Design and build UI in this repo (Vue 3 + Inertia + Tailwind v4) to a production standard — brand-correct, accessible, EN/AR, with every state handled. Use before writing or changing any screen, component, email or mobile view, and when asked to make something look better, redesign a page, or fix a layout.
---

# UI design & build (AlimaProspects)

Craft rules for frontend code live in the `agent-skills:frontend-ui-engineering` skill — read it
for component architecture, state and a11y patterns, but ignore its React/TS examples: this is
Vue 3 SFC + Inertia + Tailwind v4 + shadcn-vue.

## 1. Before you design anything

- `docs/brand/brand-tokens.md` — §1 colour, §2 semantic tokens, §3 type, §4 logo, §5 elements,
  §6 voice (copy), §7 what is _not_ decided. If a detail is missing, open the PDFs in
  `docs/brand/`, then write what you extracted back into §1–§6.
- `resources/css/app.css` — the `@theme` block is the only source of colour/type/spacing classes.
  **Never a raw hex, never an arbitrary value** (`text-[#f60]`, `p-[13px]`).
- The page you are changing, plus one finished neighbour page. Match it. A screen that invents
  its own spacing rhythm is the bug, even when it is prettier alone.

## 2. Reuse before you build

The kit is `resources/js/Components/UI/<name>/`, imported as `@/components/ui/<name>`:
alert · avatar · badge · breadcrumb · button · card · checkbox · collapsible · dialog ·
dropdown-menu · empty-state · input · input-otp · label · native-select · navigation-menu ·
progress · select · separator · sheet · sidebar · skeleton · sonner · table · textarea · tooltip

Order: existing kit component → compose two of them → extend the kit component itself (so every
caller gains it) → only then a new one. A one-off styled `<div>` that duplicates `card` is debt.
Feature components go in `resources/js/Components/<Module>/`.

## 3. The four states, every time

Design **loading, empty, error, full** before you write markup — most "AI-looking" UI is the
happy path only. Use `skeleton` for loading, `empty-state` for empty (with the action that fixes
it), `alert`/`sonner` for errors. A table with 1 row and one with 200 are two layouts.

## 4. Non-negotiables (CLAUDE.md §2a is the law here)

- Lead with Cloud Cream, **one** hero accent per layout. **Signal Orange is decorative only** —
  status is always `status.*`.
- Poppins (Latin) / Tajawal (Arabic) / DM Mono (labels, figures, codes).
- WCAG AA: contrast, a real focus ring, labels tied to inputs, keyboard path through every
  interaction, and an accessible name on every icon-only button.
- **EN/AR + RTL**: all copy through `t()` — no hard-coded strings. Use logical properties
  (`ms-`/`me-`/`ps-`/`pe-`, `start`/`end`), never `ml-`/`pl-`/`left`. Directional icons flip.
- Mobile (`/mobile`) shares code by alias (`@shared`), never by copy.

## 5. Finish

1. `npm run check && npm run types:check && npm run build` (in `mobile/` too if touched).
2. Run the **`brand-reviewer`** and **`ux-reviewer`** agents on the diff (in one message, so they run concurrently) and fix what they report.
3. Check the screen at 375px wide, and once with `dir="rtl"`.
