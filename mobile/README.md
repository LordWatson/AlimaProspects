# Alima Prospects — mobile

The iOS + Android field companion. Capacitor + Ionic Vue + TypeScript, consuming `/api/v1`.

**Start here:** [`docs/runbooks/mobile-dev.md`](../docs/runbooks/mobile-dev.md) — how to run and test
it, written for someone who has never built a mobile app. What the app is and why it is shaped this
way is in [`docs/modules/mobile-app.md`](../docs/modules/mobile-app.md).

## The short version

```bash
npm install
npm install-scripts approve esbuild   # npm 11 gates install scripts; Vite needs its binary
npm run dev                           # then open the URL it prints, in Chrome's device mode
```

The API has to be running (`composer dev` at the repository root). The sign-in screen has a
development-only **Server** field for pointing the app elsewhere without rebuilding — you will need
it on an emulator, where `localhost` is the emulator and the Herd domain does not resolve.

## Gates

```bash
npm run check         # oxlint --deny-warnings + oxfmt --check
npm run types:check   # vue-tsc
npm run test          # vitest
npm run build
```

The root project's gates do **not** cover this directory, and its lint and format passes skip it
deliberately — see the note in the root `vite.config.ts`.

## Layout

| Path | What |
| ---- | ---- |
| `src/views/` | One file per screen. |
| `src/components/` | The shared pieces: the logo, a KPI tile, a grade badge, a list row. |
| `src/lib/` | Everything that is not a screen — the API client, the session, i18n, formatting, push. |
| `src/theme/` | `brand.css` (the brand as Ionic variables — the only hex literals here) and `app.css`. |
| `android/`, `ios/` | The committed native projects. |
| `scripts/` | `generate-native-artwork.mjs` (`npm run artwork`). |

## Two things not to get wrong

**Shared code is aliased, never copied.** `@shared` → `resources/js/shared/` and `@lang` → `lang/`
reach up into the Laravel app, so the API client, the payload types, the brand tokens, the logo path
and the translations are the same files the web app compiles. A copy is a copy that drifts.

**The native artwork is generated.** The Android launcher icon, launch screen and notification icon
are vector drawables written from the master logo path by `npm run artwork`. Change the logo in
`resources/js/shared/brand/artwork.ts` and rerun it; do not hand-edit a drawable.
