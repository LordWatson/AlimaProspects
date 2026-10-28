# Alima — Brand Tokens

> Extracted from `Alima — Brand Guidelines.pdf` (Edition 01, October 2026, V1.0). This file is the engineering source of truth that mirrors the brand book. **Build with tokens, never raw hex.** Where a value is proposed by us (not in the book), it is marked **PENDING APPROVAL**.

Tagline: **Marketing brighter together.** (always with the full stop) · Promise: **Bold ideas. Real impact.**

The brand is bilingual (English + Arabic) and markets to the UK and UAE — plan for RTL and `Tajawal` from day one.

---

## 1. Colour

### 1.1 Palette — masters (HEX + RGB are authoritative, taken from the logo artwork)

| Token name | Brand name | HEX | RGB | Role |
|---|---|---|---|---|
| `teal` | Deep Teal | `#205557` | 32 85 87 | Primary · structure, links |
| `ink` | Midnight Ink | `#181723` | 24 23 35 | Primary · text, dark sections, main buttons |
| `cream` | Cloud Cream | `#F5F2E8` | 245 242 232 | Primary · page background (lead with cream, ~50%) |
| `orange` | Signal Orange | `#F03D2A` | 240 61 42 | Accent · **decorative only, never a status colour** |
| `blue` | Electric Blue | `#4E91D4` | 78 145 212 | Accent |
| `lilac` | Soft Lilac | `#C9A4D8` | 201 164 216 | Accent |
| `lime` | Acid Lime | `#D2E842` | 210 232 66 | Accent |

CMYK (coated stock, press-proof before use): Teal 85/40/50/40 · Ink 75/70/45/80 · Cream 3/3/8/0 · Orange 0/85/90/0 · Blue 70/35/0/0 · Lilac 20/35/0/0 · Lime 20/0/85/0. Pantone values are TBD in the book (`PMS ____ C`).

### 1.2 Proportion
Lead with **Cloud Cream ~50%**, Teal ~20%, Ink ~15%, Accents ~15% (Orange 5 · Blue 4 · Lilac 3 · Lime 3). **One hero accent per layout**, a second only to support it.

### 1.3 Tints (mix with Cloud Cream, not white — keep them warm; never tint the logo)

| Base | 100 | 80 | 60 | 40 | 20 |
|---|---|---|---|---|---|
| Deep Teal | `#205557` | `#4B7474` | `#759491` | `#A0B3AE` | `#CAD3CB` |
| Midnight Ink | `#181723` | `#44434A` | `#706F72` | `#9D9A99` | `#C9C6C1` |
| Signal Orange | `#F03D2A` | `#F16150` | `#F28576` | `#F3AA9C` | `#F4CEC2` |
| Electric Blue | `#4E91D4` | `#6FA4D8` | `#91B8DC` | `#B2CBE0` | `#D4DFE4` |
| Soft Lilac | `#C9A4D8` | `#D2B4DB` | `#DBC3DE` | `#E3D3E2` | `#ECE2E5` |
| Acid Lime | `#D2E842` | `#D9EA63` | `#E0EC84` | `#E7EEA6` | `#EEF0C7` |

### 1.4 Spectrum gradient
90° linear, fixed stop order **Ink, Orange, Blue, Lilac, Lime, Teal, Cream** (0·16·33·50·66·83·100%). Never reorder. Use for hero bands / motion / loaders. Never behind body text, as a type fill, or inside the logo.

---

## 2. Semantic digital tokens (product + web — pages 48–49)

> Signal Orange is decorative in UI, **never a status colour**. Contrast ratios are text-on-`bg.canvas`.

### 2.1 Colour tokens

| Token | Value | Notes |
|---|---|---|
| `bg.canvas` | `#F5F2E8` | Page background (Cloud Cream) |
| `bg.surface` | `#FBFAF6` | Cards, sheets |
| `bg.inverse` | `#181723` | Dark sections |
| `text.primary` | `#181723` | 15.8:1 |
| `text.secondary` | `#4A4857` | 8.0:1 |
| `text.muted` | `#6B6977` | 4.8:1 |
| `border.control` | `#8C8A8F` | 3.1:1 inputs |
| `action.primary` | `#181723` | Main buttons |
| `action.brand` | `#205557` | Links · 7.5:1 |
| `action.brand.hover` | `#173F41` | Hover, pressed |
| `focus.ring` | `#2C6CAD` | 3 px ring · 4.9:1 |
| `status.error` | `#B52A1B` | 5.7:1 |
| `status.warning` | `#8A5A00` | 5.3:1 |
| `status.success` | `#276B43` | 5.7:1 |

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

| Family | Token | Use | Source |
|---|---|---|---|
| **Poppins** | `font.base` (fallback Arial) | Everything Latin — geometric, rounded, matches the wordmark | Google Fonts · SIL OFL |
| **DM Mono** | `font.mono` | Labels, deck labels, code/metadata | Google Fonts |
| **Tajawal** | `font.arabic` (fallback Arial) | Arabic — shares Poppins' geometric build | Google Fonts |

**Weights (max two per layout, usually SemiBold over Regular):** 300 Light (large pull quotes only) · 400 Regular (body/captions) · 500 Medium (lead paragraphs, UI labels) · 600 SemiBold (headlines, buttons) · 700 Bold (campaign lines, big numbers).

**Type scale** (perfect fourth ×1.333 — desktop / mobile / print):
- DISPLAY 96/56/72pt · lh 1.05 · tracking −2%
- H1 64/40/48pt · lh 1.1 · −1%
- H2 44/32/32pt · lh 1.2
- H3 28/24/20pt · lh 1.3
- BODY 18/16/10pt · lh 1.6 (LEAD 22 Medium)
- EYEBROW 13/12/8pt · CAPS · tracking +25%
- Body never below 16px mobile / 9pt print.

**Setting rules:** sentence case (caps only for tracked eyebrows); flush left / ragged right; tight headline leading 0.95–1.1 with negative tracking; statements end in a full stop (sometimes orange); one rounded orange dash/rule per layout; measure 45–75 chars, no widows. **Arabic:** set 10–15% larger than Latin, Bold headlines / Regular text, mirror layouts (never mirror the logo), never letter-space Arabic.

---

## 4. Logo

- **Primary signature:** panda mark + wordmark locked together, Deep Teal on Cloud Cream by default. Proportion 3.44:1. Master: `alima_logo_horizontal_teal.svg`.
- **Lockups:** A Horizontal (primary — web/decks/signage), B Stacked (square/tall), C Mark (avatars/app icons once known), D Wordmark (tight spaces). Choose by space, not taste.
- **Clear space:** X = height of the wordmark's "a" (0.42 × logo height) on every side; mark alone X = one ear width.
- **Minimum sizes:** Horizontal 120px / 30mm · Stacked 64px / 18mm · Wordmark 80px / 20mm · Mark 24px / 8mm. Favicon = 16px Teal tile.
- **Approved colourways (8, all ≥3:1):** 01 Teal/Cream (primary) · 02 Cream/Ink · 03 Cream/Teal · 04 Ink/Lime · 05 Ink/Lilac · 06 Ink/Blue · 07 Cream/Orange · 08 Ink/Cream (mono).
- **Icons/avatars:** app icon 1024px master (mark 70% width), avatar 800px circle (mark 64%, 2% above centre), favicon SVG + 32/16px PNG on Teal, corner radius 22.5% (OS mask wins in apps).
- **Never:** stretch, rotate, gradient, add effects, rearrange, outline, low-contrast, Teal-on-Ink, retype the wordmark, crop the ears, turn the pupils, or animate without an approved motion file.

---

## 5. Elements

- **The snout** (nose + smile, min 12px/4mm) — smallest signature; stickers, seals, list markers, loaders, sign-offs. Any palette pair ≥3:1. Never next to the full mark or rotated.
- **The squiggle** — one rounded wandering stroke (stroke weight = wordmark stem). Pairs: Lilac/Orange, Ink/Teal, Cream/Lime.
- **The confetti** — chunky loops/crosses/dots, heavier than the squiggle, use big. Pairs: Cream/Ink, Teal/Lilac.
- **Pattern rules:** full-bleed/panels, crop freely, strokes ≥2mm print / ≥6px screen, two colours from an approved pair, text only on a solid panel above. Never recolour to 3+ colours, run body text over it, distort it, mix squiggle+confetti, or trace it.
- **The peek** — panda scaled up and cropped by the frame; both eyes in frame, bleed 1–2 edges, ≥3× any nearby logo, one peek / one colour per layout.
- **Icons:** rounded line icons, 24px grid, 2px stroke, round caps; base set **Lucide** (extended); one colour only; always paired with a label in UI. Feature icons = solid circle at 2× icon size.
- **Imagery:** real people/work/light, candid over posed, bright & warm daylight, one spark of palette colour in frame, room for type, square crops / sharp rectangles. Avoid handshakes, pointing-at-screens, heavy filters, off-palette duotones, generic stock.

---

## 6. Voice

Four principles (rewrite any sentence that breaks one):
- **Bright** — optimistic, lead with the opportunity. "Here's what this could become."
- **Bold** — direct, make a call and explain it. "This will work. Here's why."
- **Clear** — plain words, short sentences, zero jargon. "More people, more often, for less."
- **Together** — warm; write with people (we, you, us). "Let's build it together."

CTAs: *Let's grow · Let's talk · Start a project · See the work*. Headlines like *Ideas that move.* / *Bold marketing for what's next.* Rewrite jargon ("leverage synergistic omnichannel solutions" → "connect every channel so your message lands").

---

## 7. Open items flagged for brand-owner approval

1. **PENDING APPROVAL — Dark mode palette.** The book defines only `bg.inverse` (#181723) sections, not a full dark theme. Proposed dark tokens (to be confirmed):
   - `bg.canvas` `#181723`, `bg.surface` `#211F2E`, `bg.inverse` `#F5F2E8`
   - `text.primary` `#F5F2E8`, `text.secondary` `#C9C6C1`, `text.muted` `#9D9A99`
   - `border.control` `#45434F`, `action.brand` `#4B7474` (Teal 80 tint for contrast on dark), `action.brand.hover` `#759491`
   - `focus.ring` `#6FA4D8`; status on dark: error `#F28576`-adjacent, warning `#D9B15A`, success `#5FB07E` — all to be re-measured for ≥4.5:1 on the dark canvas before use.
2. **PENDING — Pantone values** are blank in the book (`PMS ____ C`); print phase must fill them.
3. **PENDING — favicon/app-icon source SVGs** are referenced (`/Alima_Brand_Library`) but not delivered in `docs/brand/`; need the master files before the app-shell phase.
4. **Confirm** whether the product UI uses Signal Orange purely decoratively (per book) given how often SaaS UIs reach for orange as a warning — we will honour the book and use `#8A5A00` for warnings.
