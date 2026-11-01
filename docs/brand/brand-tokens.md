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

### 7g. Added 2026-11-01 (platform reporting and operations, Phase 19c)

Phase 19c added seven operator screens made almost entirely of **state** — open and closed circuits,
failed jobs, lapsed assessments, bounce rates — which is the hardest shape for this palette, because
Signal Orange is decorative only and must never carry one. Nothing new was invented; three things
are recorded.

1. **The chart set is reused, not extended.** `StatTile`, `BarListChart`, `FunnelChart` and
   `ReportTableView` from `components/charts` draw every figure on these pages, with the validated
   `--chart-*` tokens and single-series bars (§7.7). `ReportTableView`'s prop widened to the table
   shape _without_ its key so a platform report renders through it, and it takes a `utc` flag —
   because `useFormat().date()` formats in the **active team's** timezone, and an operator who has
   just been impersonating somebody would otherwise read the platform's figures on that customer's
   clock. Thirteen reports, no fourteenth chart.
2. **Status badge contrast, measured.** §7f.3 left this open; the Phase 19c review worked it out
   against the 12% tints these pages use: `warning` ≈ 4.7:1, `error` ≈ 5.4:1, `success` > 5:1 — all
   passing AA at the 12px eyebrow size they are set in. **`info` ≈ 4.4:1 and would fail**, so it is
   used only as a non-text icon on these screens and never as a badge. That remains the rule until
   `status.info` is approved (§7.6).
3. **An em dash is a brand rule here, not a convention.** Every figure on these screens renders `—`
   rather than `0` where the value is unavailable: a failure rate over no calls means the provider
   was unused, a margin percentage over no revenue would read as break-even, a blank run duration
   means the run never finished. It is Reporting's own honesty rule (`Ratio::of()` returns null) and
   the review found two places that had quietly broken it, so it is written down rather than
   remembered.

Two existing decisions were tightened rather than added to: the status-panel tint is `/12` with a
`/30` border everywhere now, as §7f.2 already documented (two Phase 19c panels had drifted to `/8`
and `/40`), and the panel's **UTC** timestamp rule gained a date-only form, `useFormat().utcDate()`,
for the rollup's "figures as at" lines — a rolled-up day is a day, and padding it with a midnight it
never had and then shifting that midnight into a team's timezone is two mistakes rather than one.

**Not a panel decision, raised for the design system:** `components/ui/table/TableHead.vue` sets
`tracking-wide` rather than `tracking-eyebrow` and carries no `scope="col"`, so a table header reads
with slightly different tracking from the DM Mono eyebrow beside it. Product-wide and pre-existing;
worth a ticket rather than a local override.

### 7h. Added 2026-11-01 (the list and form rebuild)

The screens members live in — every list, and the create/edit forms behind them — were rebuilt onto
two shared sets of components, `resources/js/components/list/` and `resources/js/components/forms/`
(catalogued on `/design`). Nothing in the book covers list furniture, so the decisions are recorded
here rather than taken silently per screen. They are **product-wide**: a filter, a column header and
a required field now look the same wherever a member meets one.

1. **A filter panel folds away, and what it is doing is said in words.** The leads list opened with
   every filter it has permanently expanded — six chip groups, about 300px, above the first row. The
   panel is now behind a disclosure that carries the count of filters in force, and the filters
   themselves are summarised as removable chips (`ActiveFilters`) in the toolbar. The chips are the
   non-negotiable half: a folded panel that hid what it was doing would be worse than an open one.
   Each chip names its **group** as well as its value ("Fit A", "Stage In conversation"), because
   values are not self-explanatory side by side. Chip tint is `bg-brand/10` with `text-brand`
   (7.5:1 on canvas, and the tint is 10% of the same hue rather than a new colour).
2. **The table header band is opaque `--accent`, not `--accent/50`.** The header is sticky, so rows
   pass underneath it; a 50% tint let them show through. This is the one place the band's weight
   changed, and it is a consequence of the stickiness rather than a taste. It cost contrast, and
   the **text moved rather than the band**: `text-muted-foreground` is 4.8:1 on Cloud Cream but
   **4.47:1 on the band**, under AA, so a column header is `text-foreground/80` (7.9:1 light,
   8.5:1 dark) and the ordered column is full `text-foreground`. For the same reason the
   table card is `overflow-clip` rather than `overflow-hidden` (`hidden` makes the card its own
   scroll container and the sticky header then has nothing to stick to), and the table is
   `border-separate` with borders on the cells (`border-collapse` loses a sticky header's own rule
   as soon as it lifts).
3. **Row density is the member's choice, not ours**, remembered in `localStorage` like the
   appearance toggle: `comfortable` keeps the 16/12 rhythm (`px-4 py-3`), `compact` tightens the
   vertical to `py-1.5` and leaves the horizontal alone. Two people use a leads list completely
   differently — twice a day, readable; or for an hour, forty rows on screen — and there is no
   single right row height. Both steps are already product-wide (§7e).
4. **A row is clickable across its whole width, and still holds one link.** The primary link
   stretches its hit area with an absolute `<span>` against the row; the focus ring stays on the
   _text_, so a keyboard user sees where they are rather than a ring around the row. A trailing
   chevron appears on hover as the affordance. A cell with its own control is lifted clear
   (`ListCell raised`).
5. **A list says when it is thinking.** Filtering is a server round trip, so the table dims to
   `bg-background/55` behind a pointer-transparent veil while a request is in flight, `aria-busy`
   on the table, and the record count is a live region announced once the answer lands. 55% is not
   a token; it is the lightest veil that reads as "not yet" on Cloud Cream without hiding the rows.
6. **Selection is never colour alone.** A filter chip that is on carries a tick as well as the
   brand tint (WCAG 1.4.1) — the old chips differed only by hue. The same rule already applied to
   the signals event-type chips and now comes from the one component.
7. **The `/` shortcut was built and then withdrawn.** The search box focused on `/`, with the key
   shown in a `<kbd>` at the end of the field so it was discoverable rather than folklore. It is
   gone: a single-character shortcut bound to the document fails **WCAG 2.1.4 (Level A)** unless it
   can be turned off, remapped, or is active only while the component has focus, and guarding it
   against fields and dialogs — which it did — is none of those three. Recorded rather than
   quietly dropped, because it is worth having: it comes back the way GitHub's does, as a
   preference a member can switch off, which needs a settings surface this pass did not have.
8. **"Required" and "optional" are words.** `FormField` sets them as a muted `text-xs` note beside
   the label, never a red asterisk: Signal Orange is decorative only (§2.1), a colour is not a
   label, and an asterisk is a convention people have to have been taught.
9. **A form section's number is a solid Deep Teal mono square** (`bg-brand`,
   `text-brand-foreground`, `size-7`, `rounded-sm`) — the page hero's index device, one level
   quieter. It is what tells somebody halfway down a four-section form that there are two sections
   left. It began as `text-brand` on `bg-brand/10` and was changed: that pair is **4.3:1 on the
   dark card**, under AA at 12px, because the dark `--brand` is the unapproved §7.10 value. Cream
   on Teal is 7.5:1 light and 5.5:1 dark.
10. **The error summary follows the status-panel rules** already written down in §7f.2–3: a 12%
    tint of `status.error` with a `/30` border, the icon in `destructive`, and **the words in
    `text-foreground`** because `destructive` over a tint of itself does not clear AA at this size.
    It is `role="alert"`, focused and scrolled to when it appears, and each failure links into its
    field.
11. **"Unsaved changes" is not a status.** The sticky `FormActions` bar marks it with a Deep Teal
    dot and muted words. It is the normal condition of a form being filled in; spending the amber
    on it is how a real warning stops being noticed (the same argument as §7f.5's override badge).
    Leaving a dirty form opens a `Dialog`, never `window.confirm` — a native prompt cannot be
    translated, cannot be set in the brand's type, and says "Leave site?" about a navigation that
    never leaves the site. The leaving button is `outline`, not `destructive`: discarding a draft
    nobody has saved is not destructive to anybody's data.
12. **`ScoreDial` colours follow the grade, from the status tokens** — the same mapping
    `GradeBadge` uses, so a grade is one colour everywhere, with **D as a neutral Ink 60** rather
    than `error`: a lead that does not match your profile is nobody's fault (§7.7). The ring is
    `stroke-width 8` on a 100-unit viewBox at `size-24`/`size-32`; `size-32` is off the spacing
    scale and is there because the arc needs to read at a glance beside an H3. Grade B inherits
    §7.7's open dependency on **`--info`**, still PENDING under §7.6.
13. **`MeterBar` tones are states, not decoration**: `success` for a criterion that matched,
    `neutral` for one that did not, `brand` for a plain magnitude with no verdict attached. It
    replaced bare weight figures on the ideal-customer screens and in the score breakdown, where
    the number alone made the reader do the comparison. The neutral tone is
    **`--muted-foreground`, not Ink 60**, and the difference is the surface: §7.7's Ink 60 figures
    are measured against the **card**, while a bar sits on `--border`, where Ink 60 is **2.3:1 on
    the dark track** — under the 3:1 non-text minimum. Muted is 3.7:1 light and 5.4:1 dark. The
    meaning is unchanged: a criterion a lead fails is neutral, never `error`. A bar whose value
    changes while it is on screen (a discovery run's progress) passes `live` and becomes a real
    `role="progressbar"`, because a static `sr-only` phrase is read once and a climbing bar then
    says nothing.
14. **The leads hero now has one primary.** It carried five buttons of equal weight against §2.3's
    one-CTA rule: discovery is the primary (`lime`), the two ways into the list are `hero`
    outlines, and import/export moved into a `More` menu — occasional, operational actions that
    read as two more ways to _look_ at leads when they sit in the row.
15. **Relative time, and days grouped as "Today"/"Yesterday".** `useFormat().relative()` and
    `dayKey()` (team timezone, `numeric: 'auto'`). For a list of twenty notifications the
    _distance_ is the point, and a column of absolute timestamps makes the reader do the
    arithmetic twenty times; the exact instant stays as the element's `title`, because for "did
    anyone know about this?" it is the only thing that matters.

Two things this pass **resolved** rather than added:

- §7g's loose end on table headers is fixed for every tenant list: `ListHead` sets
  `tracking-eyebrow` (not `tracking-wide`) and carries `scope="col"` by construction.
  `components/ui/table/TableHead.vue` still has the original values and is still used by the admin
  panel, so the ticket stands for that surface.
- The ~15 call sites still hand-rolling a `<select>`, recorded as an app-wide follow-up in
  `signals.md`, are down to a handful: the leads filter bar, the bulk-action bar, the ICP editor,
  the discovery form and the do-not-contact form all use `NativeSelect` now.

**Off-scale values introduced, listed for the record** (§7c already holds the product-wide ones):
`size-32` on the large dial, `opacity-55` on the busy veil, `line-clamp-5` on a folded brief,
`min-w-56` on the toolbar's search column, and `w-6`/`w-8`/`w-20`/`w-24`/`w-28` label columns beside
bars and figures. Each is a measurement of a specific piece of type, not a new step.

#### 7h.2 Added 2026-11-01 (the lead record page)

The lead detail screen was rebuilt on the same pass. Four decisions sit outside the book.

1. **A record opens with a header card, not a hero.** `leads/Show.vue` uses the hero's own
   composition — a tracked mono eyebrow with a right-hand meta, a hairline rule, then the name —
   on the **cream card surface** rather than the Ink panel. The reason is §5's own scope: the hero
   belongs to the numbered destinations in the sidebar, and a record reached _from_ one has no
   number, which is the same reading §7a already applied to `leads/Create` and `leads/Import`. (It
   is **not** about avoiding a second peek: a screen with no hero carries no peek, which §5 allows
   perfectly well.) The card takes none of the hero's Lime or Orange accents, so §1.2's one-hero-
   accent rule is untouched. The name is the page's one `<h1>`, as the hero's statement is
   elsewhere, and it carries **no Orange full stop**: a company name is a data value, not a
   statement, and one that already ends in a stop would read "Acme Ltd..". `scoring/Show.vue` still
   sets the same company name _with_ the stop — see §7h.1's open item 3, which should be decided
   once for both screens.
2. **A read-only rail of five figures under the name**: fit, stage, who's on it, contacts, and the
   state of the data, as `dt`/`dd` pairs with DM Mono eyebrow labels. The stage and the owner are
   **also** in the work panel, which is where they are changed. That duplication is deliberate: the
   summary is what somebody needs before they scroll, and one control in one place beats the same
   control in two. The rail's chips are the product's existing ones — `GradeBadge`, the enrichment
   `status.*` badge — so a grade and a data state look the same here as in the list.
3. **The warnings are one list of tinted rows**, each `/12` with a `/30` border and the words in
   `text-foreground` (§7f.2–3). The `info` level is used for "still filling this in", and — per
   §7g.2 — `status.info` appears only as the row's icon and border, never as text or a badge. Each
   row carries a visually hidden level word, because colour alone is not a distinction.
4. **A copy button** (`components/CopyButton.vue`) beside an email address, a phone number and the
   registry number. It renders only where the browser will allow the clipboard, confirms with a
   toast **and** a two-second tick, and its accessible name is the value's _kind_ ("Copy the email
   address") rather than the value, so twenty rows do not produce twenty unreadable button names.
   The glyph is 24px — WCAG 2.5.8's minimum exactly, and under the 44px touch guidance — so the hit
   area is taken to about 40px by an invisible `before` pseudo-element rather than by growing the
   button and unsettling the row. The tag-remove button in `LeadWorkPanel` is the same 24px and
   should get the same treatment when it is next touched.

The review of this screen found two things that were **fixed product-wide** rather than locally,
and both are worth knowing about before writing the next tinted panel:

- **Body copy on a status tint is `text-foreground/80`, not `text-muted-foreground`.** Muted is
  4.8:1 on Cloud Cream but about **4.4:1** on a 12% warning or info tint — under AA at 14px. §7f.3
  already said the _words_ on a tint are the foreground colour; only the titles had been obeying it.
  Fixed here and in `leads/Create`'s compliance note.
- **`enriching` is no longer an `info` badge.** `status.info` over its own 12% tint is about 4.4:1,
  which is why §7g.2 restricts info to a non-text icon until §7.6 approves the token — and the
  leads list and the lead's rail were both setting it as a badge. Both are now an `outline` badge
  with a `Spinner`, which says "in progress" without leaning on the colour at all. **`GradeBadge`
  still maps grade B to `info`**; it is shared, pre-existing, and already recorded under §7.7 and
  §7h.1, and it is the last thing in the product waiting on that token.

One judgement left standing: the rail shows **five** figures, and two of them repeat something
nearby — the data state is also in the eyebrow and the notices, and the contacts count is also the
section below. The count was made an **anchor** to that section so it earns its place; the data
state stays, because "is this lead's data all here" is the question the eyebrow's date only hints
at. Confirm, or the rail drops to four.

#### 7h.1 What the brand review changed, and what it left standing

The `brand-reviewer` pass over this work found no brand violation — Signal Orange is on no state,
there is no raw hex and the 12px floor holds — and four contrast failures, all now fixed and
recorded above: the header text on the opaque band (4.47:1), the step square on its own tint
(4.3:1 dark), the neutral meter fill on the track (2.3:1 dark), and the filter chip. The chip is
worth its own line, because the fix is a **rule** rather than a value:

- **A brand tint does not carry its own words.** The active-filter chip set the group name in
  `text-brand/70` (3.4:1) and the value in `text-brand` on `bg-brand/10` (4.3:1 on the dark card).
  Both words are now `text-foreground`, with the brand kept to the tint and the remove button —
  exactly what §7f.3 already says about the status tints, applied to the brand. A filter's group
  name is information, not decoration.

Four things were left as judgements rather than changed, and are flagged here for the brand owner:

1. **The busy veil lets text fall below AA while it is up** (`bg-background/55` over the rows is
   about 2:1). It is transient, the table is `aria-busy`, and the rows it covers are about to be
   replaced — dimming them is the point. Confirm, or the veil becomes a spinner beside the count.
2. **The full-row click target has two side effects**: text in a non-raised cell cannot be
   selected, and a cell's own `title` tooltip is covered unless it is raised (the notifications
   timestamp is, so hovering the time still shows the exact instant). Both are the usual price of
   a row-sized target, and the row still holds exactly one link.
3. **An orange full stop after a record's name.** `scoring/Show.vue` sets the lead's company name
   as the page statement and closes it with the Orange stop, as the sibling screens do with their
   own statements. A company name is not a statement, so §3's rule is being stretched; it is
   pre-existing and consistent with its siblings, so it was left. **Confirm, or those headings lose
   the stop.**
4. **Grade B still depends on `--info`** (§7.6, §7.7) and the dark `--brand`, `--warning`, `--info`
   and `--destructive` values remain unapproved (§7.10). Nothing new was built on them; the two
   places that had been were moved off (items 9 and 13 above).
