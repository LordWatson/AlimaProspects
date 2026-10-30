/**
 * Alima brand tokens — the single TypeScript source of truth, mirrored from
 * `docs/brand/brand-tokens.md` (the engineering extraction of the brand PDFs)
 * and kept in step with the Tailwind `@theme` tokens in `resources/css/app.css`.
 *
 * Lives under `resources/js/shared/` so the Capacitor + Ionic Vue mobile app
 * (Phase 18) can import the exact same values via a path alias — build with
 * these tokens, never raw hex. Signal Orange is DECORATIVE ONLY, never a status
 * colour. See CLAUDE.md §2a.
 */

/** Master palette — authoritative hex from the logo artwork, with Cloud-Cream tints. */
export const palette = {
    teal: {
        100: '#205557',
        80: '#4b7474',
        60: '#759491',
        40: '#a0b3ae',
        20: '#cad3cb',
    },
    ink: {
        100: '#181723',
        80: '#44434a',
        60: '#706f72',
        40: '#9d9a99',
        20: '#c9c6c1',
    },
    cream: { base: '#f5f2e8', surface: '#fbfaf6' },
    orange: {
        100: '#f03d2a',
        80: '#f16150',
        60: '#f28576',
        40: '#f3aa9c',
        20: '#f4cec2',
    },
    blue: {
        100: '#4e91d4',
        80: '#6fa4d8',
        60: '#91b8dc',
        40: '#b2cbe0',
        20: '#d4dfe4',
    },
    lilac: {
        100: '#c9a4d8',
        80: '#d2b4db',
        60: '#dbc3de',
        40: '#e3d3e2',
        20: '#ece2e5',
    },
    lime: {
        100: '#d2e842',
        80: '#d9ea63',
        60: '#e0ec84',
        40: '#e7eea6',
        20: '#eef0c7',
    },
} as const;

/** Colour proportion guidance — lead with cream, one hero accent per layout. */
export const proportion = {
    cream: 0.5,
    teal: 0.2,
    ink: 0.15,
    accents: 0.15,
} as const;

/** Semantic digital tokens (product + web), light theme. Contrast is text-on-canvas. */
export const semanticLight = {
    'bg.canvas': '#f5f2e8',
    'bg.surface': '#fbfaf6',
    'bg.inverse': '#181723',
    'text.primary': '#181723',
    'text.secondary': '#4a4857',
    'text.muted': '#6b6977',
    'border.control': '#8c8a8f',
    'action.primary': '#181723',
    'action.brand': '#205557',
    'action.brand.hover': '#173f41',
    'focus.ring': '#2c6cad',
    'status.error': '#b52a1b',
    'status.warning': '#8a5a00',
    'status.success': '#276b43',
} as const;

/** Dark theme — ⚠️ PENDING BRAND-OWNER APPROVAL (brand-tokens.md §7.1). */
export const semanticDark = {
    'bg.canvas': '#181723',
    'bg.surface': '#211f2e',
    'bg.inverse': '#f5f2e8',
    'text.primary': '#f5f2e8',
    'text.secondary': '#c9c6c1',
    'text.muted': '#b4b1ad',
    'border.control': '#514f5c',
    'action.primary': '#f5f2e8',
    'action.brand': '#759491',
    'action.brand.hover': '#a0b3ae',
    'focus.ring': '#6fa4d8',
    'status.error': '#ef9a9a', // red family, off the Signal Orange ramp
    'status.warning': '#d9b15a',
    'status.success': '#5fb07e',
} as const;

/** Type families. Arabic is set 10–15% larger; never letter-space Arabic. */
export const fonts = {
    base: "'Poppins', 'Arial', ui-sans-serif, system-ui, sans-serif",
    mono: "'DM Mono', ui-monospace, monospace",
    arabic: "'Tajawal', 'Arial', ui-sans-serif, system-ui, sans-serif",
    arabicSizeBoost: 1.1,
} as const;

/** Type scale (perfect fourth ×1.333) — desktop px, line-height, tracking. */
export const typeScale = {
    display: { size: 96, lh: 1.05, tracking: '-0.02em' },
    h1: { size: 64, lh: 1.1, tracking: '-0.01em' },
    h2: { size: 44, lh: 1.2, tracking: '0' },
    h3: { size: 28, lh: 1.3, tracking: '0' },
    body: { size: 18, lh: 1.6, tracking: '0' },
    eyebrow: { size: 13, lh: 1.2, tracking: '0.25em', transform: 'uppercase' },
} as const;

/** Radius — inputs & cards 8–24; chips/buttons pill. */
export const radius = { sm: 8, md: 16, lg: 24, pill: 9999 } as const;

/** Depth — Ink-tinted shadows. */
export const shadow = {
    sm: '0 1px 2px 0 rgb(24 23 35 / 0.06)',
    md: '0 8px 24px -4px rgb(24 23 35 / 0.1)',
} as const;

/** Motion. */
export const motion = {
    fast: 150,
    base: 250,
    ease: 'cubic-bezier(0.2, 0.8, 0.2, 1)',
} as const;

/** Spacing scale (4px base). Sections breathe at 128/96/64. */
export const spacing = [4, 8, 16, 24, 32, 48, 64, 96, 128] as const;

/** Responsive grid. */
export const grid = {
    desktop: { min: 1280, columns: 12, margin: 80, gutter: 24, max: 1280 },
    tablet: { min: 768, columns: 8, margin: 40, gutter: 20 },
    mobile: { min: 0, columns: 4, margin: 20, gutter: 16 },
} as const;

export const brand = {
    tagline: 'Marketing brighter together.',
    promise: 'Bold ideas. Real impact.',
    locales: ['en', 'ar'] as const,
} as const;

export type BrandLocale = (typeof brand.locales)[number];
