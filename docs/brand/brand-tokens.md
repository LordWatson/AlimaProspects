# Alima — Brand Tokens

> Extracted from the brand PDFs — **`Alima — Brand Guidelines.pdf`** (Edition 01, October 2026, V1.0) and **`Alima.pdf`** — which live in **`.claude/docs/brand/`** (originals) and **`docs/brand/`** (mirror). Those PDFs are the fixed, authoritative reference; this file is their engineering extraction. **Any frontend (web or mobile) must obey them — see `CLAUDE.md §2a`.** **Build with tokens, never raw hex.** If something you need isn't here, open the PDFs and extract it. Values proposed by us (not in the book) are marked **PENDING APPROVAL**.

Tagline: **Marketing brighter together.** (always with the full stop) · Promise: **Bold ideas. Real impact.**

The brand is bilingual (English + Arabic) and markets to the UK and UAE — plan for RTL and `Tajawal` from day one.

---

## 1. Colour

### 1.1 Palette — masters (HEX + RGB are authoritative, taken from the logo artwork)

| Token name | Brand name    | HEX       | RGB         | Role                                                |
| ---------- | ------------- | --------- | ----------- | --------------------------------------------------- |
| `teal`     | Deep Teal     | `#205557` | 32 85 87    | Primary · structure, links                          |
| `ink`      | Midnight Ink  | `#181723` | 24 23 35    | Primary · text, dark sections, main buttons         |
| `cream`    | Cloud Cream   | `#F5F2E8` | 245 242 232 | Primary · page background (lead with cream, ~50%)   |
| `orange`   | Signal Orange | `#F03D2A` | 240 61 42   | Accent · **decorative only, never a status colour** |
| `blue`     | Electric Blue | `#4E91D4` | 78 145 212  | Accent                                              |
| `lilac`    | Soft Lilac    | `#C9A4D8` | 201 164 216 | Accent                                              |
| `lime`     | Acid Lime     | `#D2E842` | 210 232 66  | Accent                                              |

CMYK (coated stock, press-proof before use): Teal 85/40/50/40 · Ink 75/70/45/80 · Cream 3/3/8/0 · Orange 0/85/90/0 · Blue 70/35/0/0 · Lilac 20/35/0/0 · Lime 20/0/85/0. Pantone values are TBD in the book (`PMS ____ C`).

### 1.2 Proportion

Lead with **Cloud Cream ~50%**, Teal ~20%, Ink ~15%, Accents ~15% (Orange 5 · Blue 4 · Lilac 3 · Lime 3). **One hero accent per layout**, a second only to support it.

### 1.3 Tints (mix with Cloud Cream, not white — keep them warm; never tint the logo)

| Base          | 100       | 80        | 60        | 40        | 20        |
| ------------- | --------- | --------- | --------- | --------- | --------- |
| Deep Teal     | `#205557` | `#4B7474` | `#759491` | `#A0B3AE` | `#CAD3CB` |
| Midnight Ink  | `#181723` | `#44434A` | `#706F72` | `#9D9A99` | `#C9C6C1` |
| Signal Orange | `#F03D2A` | `#F16150` | `#F28576` | `#F3AA9C` | `#F4CEC2` |
| Electric Blue | `#4E91D4` | `#6FA4D8` | `#91B8DC` | `#B2CBE0` | `#D4DFE4` |
| Soft Lilac    | `#C9A4D8` | `#D2B4DB` | `#DBC3DE` | `#E3D3E2` | `#ECE2E5` |
| Acid Lime     | `#D2E842` | `#D9EA63` | `#E0EC84` | `#E7EEA6` | `#EEF0C7` |

### 1.4 Spectrum gradient

90° linear, fixed stop order **Ink, Orange, Blue, Lilac, Lime, Teal, Cream** (0·16·33·50·66·83·100%). Never reorder. Use for hero bands / motion / loaders. Never behind body text, as a type fill, or inside the logo.

---

## 2. Semantic digital tokens (product + web — pages 48–49)

> Signal Orange is decorative in UI, **never a status colour**. Contrast ratios are text-on-`bg.canvas`.

### 2.1 Colour tokens

| Token                | Value     | Notes                         |
| -------------------- | --------- | ----------------------------- |
| `bg.canvas`          | `#F5F2E8` | Page background (Cloud Cream) |
| `bg.surface`         | `#FBFAF6` | Cards, sheets                 |
| `bg.inverse`         | `#181723` | Dark sections                 |
| `text.primary`       | `#181723` | 15.8:1                        |
| `text.secondary`     | `#4A4857` | 8.0:1                         |
| `text.muted`         | `#6B6977` | 4.8:1                         |
| `border.control`     | `#8C8A8F` | 3.1:1 inputs                  |
| `action.primary`     | `#181723` | Main buttons                  |
| `action.brand`       | `#205557` | Links · 7.5:1                 |
| `action.brand.hover` | `#173F41` | Hover, pressed                |
| `focus.ring`         | `#2C6CAD` | 3 px ring · 4.9:1             |
| `status.error`       | `#B52A1B` | 5.7:1                         |
| `status.warning`     | `#8A5A00` | 5.3:1                         |
| `status.success`     | `#276B43` | 5.7:1                         |

### 2.2 Shape · depth · motion

- **Radius:** `sm 8` · `md 16` · `lg 24` · `pill` (inputs & cards 8–24, chips/buttons pill).
- **Depth:** `shadow.sm` = `0 1 2` @ 6% Ink · `shadow.md` = `0 8 24` @ 10% Ink.
- **Motion:** `fast 150ms` · `base 250ms`, easing `cubic-bezier(0.2, 0.8, 0.2, 1)`.

### 2.3 Spacing & grid

- **Spacing scale (4px base):** 4 · 8 · 16 · 24 · 32 · 48 · 64 · 96 · 128. Sections breathe at 128/96/64.
- **Grid:** Desktop ≥1280 → 12 col, 80 margin, 24 gutter, 1280 max. Tablet 768+ → 8 col, 40 margin, 20 gutter, fluid. Mobile <768 → 4 col, 20 margin, 16 gutter, fluid.
- Web header 88px, logo 160px, one primary CTA per view.

---

## 3. Typography

| Family      | Token                          | Use                                                         | Source                 |
| ----------- | ------------------------------ | ----------------------------------------------------------- | ---------------------- |
| **Poppins** | `font.base` (fallback Arial)   | Everything Latin — geometric, rounded, matches the wordmark | Google Fonts · SIL OFL |
| **DM Mono** | `font.mono`                    | Labels, deck labels, code/metadata                          | Google Fonts           |
| **Tajawal** | `font.arabic` (fallback Arial) | Arabic — shares Poppins' geometric build                    | Google Fonts           |

**Weights (max two per layout, usually SemiBold over Regular):** 300 Light (large pull quotes only) · 400 Regular (body/captions) · 500 Medium (lead paragraphs, UI labels) · 600 SemiBold (headlines, buttons) · 700 Bold (campaign lines, big numbers).

**Type scale** (perfect fourth ×1.333 — desktop / mobile / print):

- DISPLAY 96/56/72pt · lh 1.05 · tracking −2%
- H1 64/40/48pt · lh 1.1 · −1%
- H2 44/32/32pt · lh 1.2
- H3 28/24/20pt · lh 1.3
- BODY 18/16/10pt · lh 1.6 (LEAD 22 Medium)
- EYEBROW 13/12/8pt · CAPS · tracking +25%
- Body never below 16px mobile / 9pt print.

**Punctuation across scripts (2026-10-30):** a statement's final full stop is often set in Signal
Orange, and it is written as a literal in the template because `.` is the same mark in both
scripts. **A question mark is not** — Arabic ends a question with `؟` — so where the orange mark is
a question mark it goes through `t('?')` like any other string. The same applies to any mark that
differs between the two scripts (`،` for a comma, `؛` for a semicolon) if one is ever set in accent
colour.

**Setting rules:** sentence case (caps only for tracked eyebrows); flush left / ragged right; tight headline leading 0.95–1.1 with negative tracking; statements end in a full stop (sometimes orange); one rounded orange dash/rule per layout; measure 45–75 chars, no widows. **Arabic:** set 10–15% larger than Latin, Bold headlines / Regular text, mirror layouts (never mirror the logo), never letter-space Arabic.

---

## 4. Logo

- **Primary signature:** panda mark + wordmark locked together, Deep Teal on Cloud Cream by default. Proportion 3.44:1. Master: `alima_logo_horizontal_teal.svg`.
    - _Discrepancy, 2026-10-30:_ the book says **3.44:1**; the delivered vector is `933.51 × 277.89`, which is **3.359:1**. The artwork wins in code — §4 forbids redrawing it, and stretching it to the quoted ratio is exactly what "never stretch" rules out. Minimum **widths** are therefore what the sizes in §4.1 are derived from, not heights. **Flagged for the brand owner** to reconcile the figure in the book.
- **Lockups:** A Horizontal (primary — web/decks/signage), B Stacked (square/tall), C Mark (avatars/app icons once known), D Wordmark (tight spaces). Choose by space, not taste.
- **Clear space:** X = height of the wordmark's "a" (0.42 × logo height) on every side; mark alone X = one ear width.
- **Minimum sizes:** Horizontal 120px / 30mm · Stacked 64px / 18mm · Wordmark 80px / 20mm · Mark 24px / 8mm. Favicon = 16px Teal tile.
- **Approved colourways (8, all ≥3:1):** 01 Teal/Cream (primary) · 02 Cream/Ink · 03 Cream/Teal · 04 Ink/Lime · 05 Ink/Lilac · 06 Ink/Blue · 07 Cream/Orange · 08 Ink/Cream (mono).
- **Icons/avatars:** app icon 1024px master (mark 70% width), avatar 800px circle (mark 64%, 2% above centre), favicon SVG + 32/16px PNG on Teal, corner radius 22.5% (OS mask wins in apps).
- **Never:** stretch, rotate, gradient, add effects, rearrange, outline, low-contrast, Teal-on-Ink, retype the wordmark, crop the ears, turn the pupils, or animate without an approved motion file.

#### 4.1 How the logo is implemented (2026-10-30)

The master artwork lives **once**, in `resources/js/components/brand/artwork.ts` (`logoArtwork`,
`markArtwork`), generated from the delivered vector source and never hand-edited. `BrandArtwork`
draws it inline with `fill="currentColor"`; `AppLogo` is the lock-up and `PandaFace` the mark.

- **Inline, not `<img>`.** A file carries a baked-in fill, so each of the eight colourways would
  need its own. An inline path inherits the text colour of whatever it sits on, which is also what
  keeps the eyes and the snout true cut-outs over any surface.
- **`dir="ltr"` is forced on the element**, so the logo never mirrors in Arabic (§4's Never list).
- **Sizes are widths, not tastes.** The lock-up is 3.36:1 and may not render under 120px wide, so
  the three sizes are the legal heights: `sm` 36px → 121px (tight chrome, and the floor), `md`
  40px → 134px (the default — marketing header, sidebar; the size the reference screens in
  `resources/screenshots/` show), `lg` 48px → 161px (§2.3's "logo 160px"; the marketing footer).
  **There is no size below `sm`** — a smaller space takes the mark (lock-up C, min 24px) or the
  wordmark (D, min 80px), per §4's "choose by space, not taste".

---

## 5. Elements

- **The snout** (nose + smile, min 12px/4mm) — smallest signature; stickers, seals, list markers, loaders, sign-offs. Any palette pair ≥3:1. Never next to the full mark or rotated.
- **The squiggle** — one rounded wandering stroke (stroke weight = wordmark stem). Pairs: Lilac/Orange, Ink/Teal, Cream/Lime.
- **The confetti** — chunky loops/crosses/dots, heavier than the squiggle, use big. Pairs: Cream/Ink, Teal/Lilac.
- **Pattern rules:** full-bleed/panels, crop freely, strokes ≥2mm print / ≥6px screen, two colours from an approved pair, text only on a solid panel above. Never recolour to 3+ colours, run body text over it, distort it, mix squiggle+confetti, or trace it.
- **The peek** — panda scaled up and cropped by the frame; both eyes in frame, bleed 1–2 edges, ≥3× any nearby logo, one peek / one colour per layout.
    - _Implementation note (2026-10-30):_ size a peek by the artwork's own ratio —
      `aspect-[383/278]` plus a width — inside a frame that clips it, and push it past an edge with
      a negative offset, as `PageHero` does. A **square** container letterboxes the artwork and
      leaves a small panda floating with margin on every side, which is not a peek; both marketing
      pages had that until it was corrected.
    - _Product application (2026-10-30), set by the brand owner against the reference screens in `resources/screenshots/`:_ every `PageHero` carries exactly one peek, and **it is the same peek on every screen** — Soft Lilac, scaled up, set into the bottom end corner, cropped by the card on the bottom and end edges at roughly three quarters of the face, both eyes in frame. One peek, one colour, one placement.
        - Lilac on Ink is colourway 05. It makes a third accent alongside the statement's Lime and the rule's Orange, which §1.2 would otherwise hold to two; **the brand owner has made that call** for the hero specifically, and the reference screens are the record of it. Do not "fix" it back to Lime or Cream.
        - An earlier build varied the tone and the crop per screen. It was wrong: the app read as eight different products, and §5's "one peek, one colour per layout" is about the mark being consistent, not an invitation to reskin it per page. A screen's identity comes from its number, its statement and its figure.
        - The artwork is never touched. §4's Never list includes turning the pupils, so the eyes are not redirected to vary a screen.
- **Icons:** rounded line icons, 24px grid, 2px stroke, round caps; base set **Lucide** (extended); one colour only; always paired with a label in UI. Feature icons = solid circle at 2× icon size.
- **Imagery:** real people/work/light, candid over posed, bright & warm daylight, one spark of palette colour in frame, room for type, square crops / sharp rectangles. Avoid handshakes, pointing-at-screens, heavy filters, off-palette duotones, generic stock.

---

## 6. Voice

Four principles (rewrite any sentence that breaks one):

- **Bright** — optimistic, lead with the opportunity. "Here's what this could become."
- **Bold** — direct, make a call and explain it. "This will work. Here's why."
- **Clear** — plain words, short sentences, zero jargon. "More people, more often, for less."
- **Together** — warm; write with people (we, you, us). "Let's build it together."

CTAs: _Let's grow · Let's talk · Start a project · See the work_. Headlines like _Ideas that move._ / _Bold marketing for what's next._ Rewrite jargon ("leverage synergistic omnichannel solutions" → "connect every channel so your message lands").

---

## 7. Open items flagged for brand-owner approval

### 7a. Added 2026-10-30 (Phases 11–12)

- **`PageHero` on utility and sub-pages.** §5 says every main screen opens with the hero. Interpreted
  so far as: a **sidebar destination** gets one (`outreach/Templates.vue`, index 08, like
  `signals/Watches.vue` at 07), while a **sub-page reached from a record** uses a plain header —
  `leads/Create.vue` and `leads/Import.vue` set that precedent in Phase 10, and
  `outreach/Show.vue`, `outreach/Suppressions.vue` and `notifications/Index.vue` follow it.
  Settings screens have the settings layout's own `Heading` instead. **Needs confirming**, and the
  answer belongs here rather than being decided per screen.
    - _Phase 15 followed that reading:_ `compliance/Index.vue` is the sidebar destination and carries
      the hero; `compliance/Lia.vue` and `compliance/Dsar.vue` are reached from it and use plain
      headers. The compliance hero carries **no index number**, because the nav item sits in the
      sidebar's **Settings** chapter (the Account group before the nav was chaptered) rather than
      among the numbered chapters — administration, not daily work.
- **The Arabic brand name.** `lang/ar.json` renders Alima as "أليما" in running copy. The book does
  not say whether an Arabic wordmark or a transliteration is approved. **Needs confirming.** The
  logo artwork itself is never altered or mirrored either way (§4).
- **Poppins and Tajawal in email.** Both are named in the mail theme's font stack, and most mail
  clients ignore web fonts and fall through to the system stack. Recorded as an accepted
  limitation: the mail layout is designed to read correctly either way.
- **Transactional email chrome.** Alima's own mails (invitation, digest) use the shared `alima`
  markdown theme — Laravel's default layout recoloured to the palette, not a bespoke one, because
  the `<x-mail::*>` components are built against that layout. An **outreach message to a prospect
  carries no Alima chrome at all**: it is the tenant speaking, and Alima branding on it would
  misattribute the sender, which is what sender-identification law exists to prevent (see
  `docs/modules/outreach.md`). The only brand rule applied there is the Arabic one.

### 7b. Added 2026-10-30 (Phase 15)

- **A bare public shell for the compliance pages.** `MarketingLayout` takes a `bare` prop, and the
  hosted privacy notice and unsubscribe page use it. The marketing shell's "Let's talk" pill would
  otherwise sit beside the opt-out button: a **second primary CTA on one view** (§2.3), the wrong
  thing to say to somebody who has just asked to be left alone, and marketing on the page where
  they ask. Bare keeps the logo, the language switch and the legal line. **Recorded rather than
  invented** — the rule it follows is §2.3's one-CTA rule; confirm the reduced shell is the look you
  want for pages a stranger lands on.
- **Literal hex in the outreach email footer.** `#181723` (Ink) and `#6b6977` (`text.muted`, 5.4:1
  on white) are written out in `emails/outreach/message.blade.php`, because a mail client has no CSS
  variables. The rule hairline is `#dddbd4` and the body background `#ffffff`, neither of which is a
  token: the white is deliberate under §7a (an outreach message is the **tenant** speaking and
  carries no Alima chrome), and the hairline should move to the pending `--border` value when §7.5
  is confirmed. **Needs confirming** along with that token.

1. **PENDING APPROVAL — Dark mode palette.** The book defines only `bg.inverse` (#181723) sections, not a full dark theme. Proposed dark tokens (to be confirmed):
    - `bg.canvas` `#181723`, `bg.surface` `#211F2E`, `bg.inverse` `#F5F2E8`
    - `text.primary` `#F5F2E8`, `text.secondary` `#C9C6C1`, `text.muted` `#9D9A99`
    - `border.control` `#45434F`, `action.brand` `#4B7474` (Teal 80 tint for contrast on dark), `action.brand.hover` `#759491`
    - `focus.ring` `#6FA4D8`; status on dark: error `#F28576`-adjacent, warning `#D9B15A`, success `#5FB07E` — all to be re-measured for ≥4.5:1 on the dark canvas before use.
2. **PENDING — Pantone values** are blank in the book (`PMS ____ C`); print phase must fill them.

### 7c. Added 2026-10-30 (the public marketing pages)

Raised by the brand review of `Welcome.vue`, `About.vue` and `ProductShowcase.vue`. Each is a
judgement the book does not settle, so it is recorded here rather than decided in a component.

- **Three accents on one marketing page.** §1.2 allows one hero accent and a second to support it.
  Both public pages carry **Acid Lime** (the CTA and the statement tails), **Soft Lilac** (the peek,
  and one eyebrow on Ink) and **Signal Orange** (the eyebrow dash, the opening statement's full
  stop, and the one rule inside the showcase). This is the same three-accent combination the brand
  owner approved for `PageHero` under §5, and it is used here for the same reason — but that
  approval was given for the product hero, not for the marketing pages. **Needs confirming.** The
  count was already reduced once: the mid-page section statements now close in Ink, leaving Orange
  on the eyebrow dash, the opening statement and the one invitation.
- **Peek placement on the marketing pages.** §5's product note fixes one placement for `PageHero`.
  These pages are outside it: the landing page puts its peek in the bottom end corner of the Ink
  invitation card, About puts its in the hero, cropped by the hairline rule — which is how the
  reference About screen has it. One peek, one colour (Lilac) per page either way. **Confirm
  whether the two should match each other.**
- **Four weights on one layout.** §3 allows two. The pages use Regular, Medium (mono eyebrows and
  UI labels), SemiBold (buttons, card headings) and Bold (statements) — which is what the whole
  product already does, `PageHero` and the button variants included. Fixing it is a change to the
  design system, not to these pages. **Needs a decision at that level.**
- **Display sizes between the scale's steps.** The scale runs H1 64 / DISPLAY 96. The landing
  statement tops out at 84px (`xl:text-[5.25rem]`), because 96px breaks its two-line setting at the
  1280 container and 64px is too quiet for the opening of the site. Section headings sit at 36/48px
  against H2 44. **Needs confirming**, or the scale needs an intermediate step.
- **Motion longer than the tokens.** §2.2 gives `fast 150ms` and `base 250ms`, which are interaction
  durations. The scroll reveal runs 500ms and the showcase cross-fade 300ms, both on the brand
  easing, because a section arriving is not a button changing state. **Needs confirming**, or §2.2
  needs a third, page-level duration.
- **The showcase's chrome is below the type floor.** §3 sets a 16px body / 12px eyebrow floor. The
  workspace miniature on the landing page draws its UI at 9–12px, because it is a **drawing of the
  app at reduced scale**, not UI a visitor operates — the same reason a screenshot of the app would
  be small. It is labelled as illustrative and marked `aria-hidden` where it is pure chrome.
  Recorded as an accepted exception; say so if it is not.
- **Contrast in the real sidebar.** Chasing this in the showcase surfaced it in the product:
  `AppSidebar.vue` sets its group labels at `text-cream/45` and its item numbers at `text-cream/35`
  on Deep Teal — about **3.0:1 and 2.4:1**, both under AA for text that size. The showcase was
  raised to `/75` (5.0:1). **The real sidebar still has the original values** and is the thing worth
  fixing; it is out of scope for a marketing change.

### 7d. Added 2026-10-30 (the auth split screen)

`AuthSplitLayout` was recomposed to the reference screen in `resources/screenshots/`, measured
rather than eyeballed: the masthead rule, the statement, the strapline and the wordmark now sit
within a point or two of the reference's proportions of the panel. Four judgements came out of it.

- **The statement is 48px, which is not a step on the scale.** §3 gives H1 64 and H2 44; the
  reference measures ~52. 48 was chosen because it is the Tailwind step closest to the reference and
  it keeps "Marketing / brighter together." on **two** lines at the panel's measure — 64 wraps to
  three and loses the line break the statement is built on. **Needs confirming**, or §3 needs a step
  between H2 and H1 for panel statements.
- **The display "alima" is typed, not the artwork.** §4's Never list includes "retype the wordmark",
  and §7.8 records that the typeset stand-in was retired when the master artwork arrived. The lime
  panel still **sets the word in Poppins Bold** as an oversized editorial device, and this change
  made it bigger — it now spans the panel's measure. Either it is a display word rather than a
  signature and the book should say so, or it should become **lock-up D** drawn from
  `logoArtwork`. **Needs a decision**; it is the most exposed instance of the rule in the product.
- **The wordmark's size is a formula, not a number.** `calc((50vw - 5rem) / 2.82)` — the panel is
  half the viewport from `lg` up, and the word sets 2.82em wide at this weight and tracking, so it
  spans the measure exactly at every width instead of leaving a different gap at each breakpoint.
  `leading-[0.7]` puts the baseline on the bottom padding line, where the reference has it: the word
  is **not** bled off the bottom edge.
- **The peek moved to the cream column.** It is cropped by the bottom and end edges at about three
  quarters of the face, both eyes in frame, sized to the reference (~176px wide). The lime panel now
  carries none, so the layout still has **one peek in one colour** (§5) — and the panel's lower half
  belongs to the wordmark, which a second mark was competing with.
- **A mono sign-off on the bottom line** of the form column (`© <year> Alima. Marketing brighter
  together.`) at `text-xs`, tracked, Ink 55. It is the brand line the sidebar already carries, not
  new copy.

3. **PENDING — favicon/app-icon source SVGs** are referenced (`/Alima_Brand_Library`) but not delivered in `docs/brand/`; need the master files before the app-shell phase.
4. **Confirm** whether the product UI uses Signal Orange purely decoratively (per book) given how often SaaS UIs reach for orange as a warning — we will honour the book and use `#8A5A00` for warnings.
5. **PENDING — Warm UI neutrals (Phase 2).** shadcn/the component system needs neutral surfaces the book doesn't name. Proposed, derived warm from Cream/Ink/Teal tints: `--secondary #E9E6DC`, `--muted #ECE9DF`, `--accent #E6ECE9` (soft teal tint for hover/selected), `--border #DDD9CC`. Confirm or replace with exact book tints.
6. **PENDING — `--info` status token.** The book defines only error/warning/success. The product needs a neutral "informational / in-progress" state; proposed `#2C6CAD` light / `#6FA4D8` dark (a blue, distinct from decorative Orange). Confirm, or restrict UI to the three book statuses.
7. **SETTLED IN ENGINEERING 2026-10-30, still needs brand-owner sign-off — chart palette** (was PENDING since Phase 2; settled with the `dataviz` guidance when the dashboard was built). The per-series variants §7 anticipated now exist in `app.css` as `--chart-1…5`, validated with the `dataviz` skill's checker against the card surface in both themes:

    | Token     | Light     | Dark      | Role                                            |
    | --------- | --------- | --------- | ----------------------------------------------- |
    | `chart-1` | `#4E91D4` | `#4E91D4` | Blue — **series 1**                             |
    | `chart-2` | `#205557` | `#2F9E9B` | Teal — single-series fills (funnel, pipeline)   |
    | `chart-3` | `#A86FC4` | `#A86FC4` | Lilac, chroma raised — single-series fills      |
    | `chart-4` | `#7E931B` | `#7E931B` | Lime, darkened — **series 2**                   |
    | `chart-5` | `#F03D2A` | `#F16150` | Signal Orange — **decorative only, never data** |

    Three findings worth keeping:
    - **The base accents do not work as data marks.** Acid Lime `#D2E842` is **1.3:1** on Cloud Cream and base Lilac reads as grey (OKLCH chroma 0.083, below the 0.10 floor). Both are re-stepped above; the brand values stay the brand values everywhere else.
    - **`chart-1` + `chart-4` are a validated pair** — deutan ΔE 23.6, both ≥3:1 on the cream _and_ the ink surface. The same two hexes pass in both themes, so a series never changes identity when the theme flips.
    - **The five accents cannot form a five-way categorical palette.** Blue, Teal and Lilac are all cool and sit too close under deuteranopia (Blue↔Lilac ΔE 2.9). So charts in the product use **at most two series**; everything else is single-series magnitude, where there is nothing to tell apart. Do not add a third and fourth series to one chart without re-validating — facet it or fold the tail into "Other" instead.
    - **A grade (A–D) is a state, not a series**, so the fit-mix bar uses the `status.*` tokens that `GradeBadge` already uses — colour follows the entity. A weak fit is a neutral **Ink 60**, not `error`: a lead that does not match is nobody's fault. (Ink 40 is 2.7:1 on the card surface and fails the 3:1 non-text minimum; Ink 60 is 4.8:1 light / 3.2:1 dark.)

    Two things here are **ours, not the book's**, and should go to the brand owner with everything else in this section:
    - the re-stepped `--chart-*` values themselves, including the dark-mode Teal `#2F9E9B`; and
    - the fit-mix bar's dependency on **`--info`** (grade B), which is still PENDING under §7.6. If `--info` is rejected, grade B needs a replacement that is distinguishable from grade A's `--success` without relying on hue alone.

8. **RESOLVED 2026-10-30 — placeholder logo (was PENDING since Phase 2).** The delivered artwork now ships: the mark and the horizontal lock-up live in `resources/js/components/brand/artwork.ts` and are drawn inline by `AppLogo` / `PandaFace` (see §4.1). The snout stand-in and the typeset wordmark described below are gone. _Original note:_ Until the master SVGs in §7.3 arrive, the app uses: (a) the **snout** element (nose + smile only, Teal tile / Cream glyph = colourway 03) as the mark, and (b) a **typeset lowercase "alima" wordmark** in Poppins SemiBold Teal — a temporary stand-in for the real wordmark (do not treat as the final logo; the lockup is forced `dir=ltr` and carries an `aria-label`). Replace both with the delivered artwork.
9. **PENDING — fonts via Bunny CDN.** Poppins / DM Mono / Tajawal load from `fonts.bunny.net` (GDPR-friendly, EU-hosted — relevant for UK/UAE). Confirm CDN use or switch to self-hosting; Tajawal is loaded 400/500/700 (no 600 — `font-semibold` falls back).
10. **PENDING — dark-theme deviations from §7.1.** The implemented dark theme (in `app.css`, marked PENDING) deviates slightly from the §7.1 proposal for contrast: `--muted-foreground #B4B1AD`, `--brand #759491`, `--brand-hover #A0B3AE`, `--input #514F5C`, and **`--destructive #EF9A9A`** (a red deliberately OFF the Signal Orange ramp — §7.1 had suggested an orange-adjacent value, which would violate "Orange is never a status colour"). Re-measure all for AA before launch.

### 7e. Added 2026-10-31 (the internal admin panel, Phase 19a)

The internal operations panel at `/admin` is **not a customer surface**, and three of its choices sit
outside the book. They are recorded here rather than silently taken (ADR 0014, `docs/modules/admin.md`):

1. **English only, no RTL.** An internal tool carries no EN/AR obligation, so the panel has no `t()`
   and no `dir` handling. The one component that renders on a **tenant** screen — the impersonation
   banner — is therefore an explicit `lang="en" dir="ltr"` island, so an English operator-only
   sentence does not right-align inside an Arabic layout.
2. **A denser type scale.** `text-2xl` (24px) headings and `text-sm` (14px) body, below §3's H3 and
   the 16px body floor. Defensible for a dense operations table; unrecorded until now. The 12px
   eyebrow floor **is** respected (an earlier 10.4px step was corrected).
3. **A Cloud Cream focus ring on Ink.** `focus.ring` (#2C6CAD) clears 3:1 on Ink, but cream is far
   more visible on the dark rail, and a focus ring that cannot be seen is the one token worth
   deviating for. Only on the Ink sidebar; every cream surface uses `focus.ring`.

Separately, and **not** a panel decision: the off-scale spacing steps (`gap-3`, `p-3`, `py-1.5`,
`px-5`) and the Regular/Medium/SemiBold weight mix are inherited from the tenant app shell and are
product-wide — see §7c.

### 7f. Added 2026-10-31 (platform settings, plan capabilities and announcements, Phase 19b)

Phase 19b added the first thing the panel writes that a **customer** reads — the announcement banner
— plus three operator screens. Five choices sit outside the book and are recorded here rather than
taken silently (ADR 0015, `docs/modules/admin.md`):

1. **A three-level announcement scheme.** `info` / `warning` / `critical`, rendered as
   `status.info` / `status.warning` / `status.error`. The book has the status palette but no notice
   taxonomy, so the mapping is ours. **Flagged for brand-owner approval**, with one rule already
   applied: each level has its own icon (`Info`, `TriangleAlert`, `OctagonAlert`) and a visually
   hidden level word, because colour alone is not a distinction (WCAG 1.4.1) and `warning` and
   `critical` otherwise differed only by hue.
2. **A 12% tint for status panels.** `bg-<status>/12` with a `/30` border. Not a book value; it is
   the lightest step that reads as a panel rather than a ruled box on Cloud Cream.
3. **Status colour never carries the words.** On those tints the title and body are
   `text-foreground`, and the status token is kept to the icon, the border and the tint itself.
   `status.info` (#2C6CAD) and `status.warning` (#8A5A00) over a 12% tint of themselves land at
   roughly 4.1:1 and 4.4:1 — under AA for 14px text. The 4.9/5.3 figures in §2 are against plain
   `bg.canvas`, which is not where these sit. **The Badge variants have not been re-measured against
   a tint and should be** before any of them is used for body copy.
4. **`status.info` is still PENDING** (§7.6: #2C6CAD light, #6FA4D8 dark), and the banner's `info`
   level, the provisioning dry-run panel and the grant panel all depend on it. It is also the same
   value as `focus.ring`, so a focused control inside an info panel loses contrast with its
   surroundings — acceptable on an operator screen, worth a second look before it reaches a customer
   surface. The dark-mode `warning`, `info` and `destructive` values remain unapproved (§7.10).
5. **An override badge is not a warning.** `is_overridden` on the settings screen reads
   `variant="outline"` with a pencil, not amber with an alert triangle. "Somebody chose this" is
   information, and spending the amber on a non-problem is how a real warning stops being noticed.

Two things the banner does that the panel does not, because it renders on a tenant screen:

- **Per-line `lang` and `dir`.** The copy is operator-authored per locale with an English fallback,
  so an Arabic reader can be handed an English sentence — which right-aligns with its punctuation
  in the wrong place unless the element says what it is. `AnnouncementBoard` therefore returns the
  locale each line **actually** resolved to (title and body separately, because an operator who
  filled in an Arabic title and an English body produces one of each).
- **Translated chrome.** The dismiss label and the three level words are `t()` keys in `lang/en.json`
  and `lang/ar.json`; only the operator's own words are untranslated.

Timestamps on the panel's screens are formatted in **UTC and labelled UTC** (`useFormat().utcDateTime`),
and the `datetime-local` inputs say "UTC" in their labels because the server reads them as UTC. The
tenant-facing `dateTime()` formats in the team's timezone, which is the wrong answer for an operator —
they are either in no team or, worse, impersonating one, so the same contractual grant date would read
differently depending on whose account they last opened.
