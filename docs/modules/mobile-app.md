# Mobile App

> Status: **Built (Phase 18)** — Android runs; iOS needs a Mac for the final steps (§"iOS") ·
> Last updated: 2026-10-31

## Purpose

The iOS + Android app in `/mobile` (Capacitor + Ionic Vue + TypeScript), consuming the versioned
REST API (`/api/v1`) with Sanctum token auth.

It is a **field companion, not a second web app.** What it owns is the four things a member needs
with a phone in one hand: today's figures, their leads, what they are allowed to say to one, and
the bell. What it deliberately does **not** own: discovery runs, ICPs, scoring configuration, saved
views, the pipeline board, templates, the do-not-contact list, notification preferences, team
members, API tokens, billing and compliance. All of those stay on the web, and the app links out
rather than reproducing them — a settings screen duplicated across two surfaces is a decision that
can be made differently in two places.

**No business logic lives here.** Every write goes through the same Action the web controller calls,
over the API, so a lead worked from a phone produces the same timeline entry, the same audit row
and the same compliance decision as one worked from a browser.

To **run and test it**, read `docs/runbooks/mobile-dev.md` — including from a machine that has never
built a mobile app.

## Code map

### The app

- **`mobile/`** — its own npm project, with its own `vite.config.ts`, `tsconfig.json`, oxlint and
  oxfmt configuration (set to the root project's rules and style). Separate rather than part of the
  root project because Capacitor resolves its platform packages from the nearest `package.json`, and
  because nothing here is served by Laravel: the build output is a folder of static files that
  Capacitor copies into the native projects.
- **`mobile/src/lib/`** — everything that is not a screen:
    | File            | What it owns                                                                            |
    | --------------- | --------------------------------------------------------------------------------------- |
    | `api.ts`        | The one `ApiClient` instance. Resolves base URL, token and locale **per call**.         |
    | `session.ts`    | Who is signed in; `requestedAbilities` (the scopes the app asks for); `sessionReady()`. |
    | `signOut.ts`    | Sign-out in the order that survives a bad connection.                                   |
    | `storage.ts`    | Capacitor Preferences, with every call's failure swallowed.                             |
    | `config.ts`     | `VITE_API_URL`, plus the **development-only** origin override.                          |
    | `i18n.ts`       | `t()`, the locale, the document direction. Reads `lang/ar.json` through an alias.       |
    | `format.ts`     | Money (minor units), dates (team timezone), relative times, initials.                   |
    | `appearance.ts` | Light / dark / system, and the native status bar.                                       |
    | `push.ts`       | FCM/APNs registration → `POST /push-devices`; deregistration at sign-out.               |
    | `bell.ts`       | The unread count behind the tab-bar badge.                                              |
    | `feedback.ts`   | Toasts and haptics; the code-based mapping of a failure to a sentence.                  |
    | `device.ts`     | The name the token is issued under.                                                     |
- **`mobile/src/views/`** — `SignInView` (credentials **and** the two-factor challenge),
  `TabsShell`, `DashboardView`, `LeadsView`, `LeadDetailView`, `LeadDraftView`, `LeadLogView`,
  `NotificationsView`, `SettingsView`.
- **`mobile/src/components/`** — `BrandMark`, `KpiTile`, `Sparkline`, `FunnelList`, `GradeBadge`,
  `LeadListItem`, `StateMessage`.
- **`mobile/src/theme/`** — `brand.css` (the brand as Ionic's `--ion-*` variables; the only place a
  hex literal appears) and `app.css` (the shared utilities, the Arabic type treatment, the
  accessibility floors).
- **`mobile/src/router/`** — four tabs plus three pushed lead screens, and one guard that waits on
  `sessionReady()` before deciding anything.

### Shared with the web app

Reached through Vite path aliases, **not copied** — this is what Phase 17 put in place:

- **`@shared` → `resources/js/shared/`** — `api/` (the typed client and the error envelope),
  `types/` (every API payload type), `tokens.ts` (the brand tokens), and `brand/artwork.ts` (the
  master logo path data, **moved** here from `components/brand/` in this phase so the mobile app
  draws the same mark from the same path).
- **`@lang` → `lang/`** — the product's own translation bundles. `ar.json` is lazily imported;
  `en.json` is not imported at all, because it is an identity map and `t()` falls through to the
  key.

### Native

- **`mobile/capacitor.config.ts`** — `appId: app.alima.prospects`, the WebView scheme, the
  background colours, the push presentation options.
- **`mobile/android/`** — the committed Gradle project. Brand colours in `res/values/colors.xml`
  (plus `values-night/`), the launch screen and the adaptive launcher icon as vector drawables
  generated from the master path, the `POST_NOTIFICATIONS` permission and the FCM notification
  icon/colour meta-data, and a **debug-only** manifest that permits cleartext HTTP.
- **`mobile/ios/`** — the committed Xcode project. `AppDelegate.swift` carries the two APNs
  callbacks the push plugin needs, `Info.plist` declares `en` + `ar`, and the launch screen is the
  brand canvas via a colour asset with a dark variant.
- **`mobile/scripts/generate-native-artwork.mjs`** (`npm run artwork`) — regenerates the Android
  vector drawables from `@shared/brand/artwork.ts`, so the logo exists once.

### Backend (this phase's only server-side work)

- **`app/Modules/Auth/Services/TwoFactorChallenge.php`** — the second factor for a caller with no
  session.
- **`app/Modules/Auth/Exceptions/TwoFactorRequiredException.php`** — `423 auth.two_factor_required`.
- **`app/Modules/Auth/Http/Controllers/Api/V1/ApiTokenController.php`** — `issue()` now settles the
  challenge instead of refusing a 2FA account outright.
- **`app/Modules/Auth/Http/Requests/IssueApiTokenRequest.php`** — `code` and `recovery_code`.
- **`config/cors.php`** — published, with `exposed_headers`.

### Tests

- **`mobile/src/lib/*.test.ts`** (vitest) — the shared API client's contract, the formatting layer,
  the language layer. 30 tests.
- **`tests/Feature/Auth/ApiTokenTest.php`** — the whole 2FA exchange.
- **`tests/Feature/Api/V1/CorsTest.php`** — the preflight and the exposed headers.
- **`tests/Feature/Api/V1/MobileJourneyTest.php`** (Phase 17) — the server-side walk of this app's
  journey over one token.

### Config / env

| Key                    | Where                 | What                                                         |
| ---------------------- | --------------------- | ------------------------------------------------------------ |
| `VITE_API_URL`         | `mobile/.env`         | The API origin. No trailing slash, no `/api/v1` suffix.      |
| `FCM_CREDENTIALS`      | root `.env`           | The Firebase service-account JSON the **server** sends with. |
| `google-services.json` | `mobile/android/app/` | The Firebase **client** config. Gitignored.                  |

## Data model

None. The app owns no tables and persists nothing but its own session, language and theme, in the
platform's key-value store (`storage.ts`).

## Key flows

### Signing in, with or without a second factor

The web app's two-factor challenge is a two-request dance over the session: `/login` parks the
user's id in `login.id` and redirects, `/two-factor-challenge` reads it back. A phone has neither
the session nor the redirect, so the exchange has to settle the password **and** the second factor
inside one request.

```mermaid
sequenceDiagram
    participant App as Mobile app
    participant API as POST /auth/token
    participant TFC as TwoFactorChallenge
    App->>API: email + password + device_name + abilities[8]
    API->>API: Hash::check — wrong password answers 422 on `email`
    API->>TFC: verify(user, code: null, recovery: null)
    TFC-->>API: TwoFactorRequiredException
    API-->>App: 423 auth.two_factor_required, details.methods
    Note over App: Same screen swaps to the challenge;<br/>credentials stay in memory
    App->>API: the same credentials + code
    API->>TFC: verify(user, code, null)
    TFC->>TFC: Fortify's TwoFactorAuthenticationProvider
    TFC-->>API: ok (a recovery code is spent and replaced)
    API-->>App: 201 { token, user }
```

Three properties of that worth keeping:

- **The password is checked first**, so a wrong password answers exactly as it does for an account
  without 2FA. The 423 is never a way to probe which accounts have it switched on.
- **There is no intermediate state.** Nothing is parked server-side between the two requests, so
  possession of a half-finished challenge is not itself a credential.
- **A wrong code is a 422 on `code`**, not another 423. The client already asked; what came back
  was wrong, and that belongs under the input the person typed it into.

### The journey, and why it composes

Sign in → `teams/current` → `reports/dashboard` → `leads` → `leads/{id}` → `leads/stages` →
`leads/{id}/stage` → notes/tasks → `outreach/decisions` → `outreach/draft` → `outreach/attempts` →
`push-devices` → `notifications`. One token, the eight scopes in `requestedAbilities`, no session
cookie anywhere.

That exact sequence is pinned server-side by `MobileJourneyTest`, which is why the app was built
against a path already proven to work rather than discovering it a screen at a time.

### Push

Registration is a three-party handshake, and the middle party is the one that is easy to forget:

1. Settings → Push asks the OS for permission (`enablePush()`), then calls
   `PushNotifications.register()`.
2. The OS answers **asynchronously**, on the `registration` listener that `listenForPushRegistration()`
   installs at boot — which is why the token is posted from there and not from `enablePush()`, and
   why a token the OS rotates on its own is still sent up.
3. `POST /push-devices` stores it against the **person**, with no `team_id`: a handset belongs to
   someone, not to a team, so switching teams does not re-register. Whether to send at all is the
   per-team preference's decision, which `NotificationDispatcher` makes.

Sign-out deregisters first and is best-effort, because a member signing out on a phone with no
signal must still get signed out. A device row left behind is swept by `notifications:prune`, and
sends to a removed app come back `UNREGISTERED` and are deleted by `PushResult::$invalidTokens`.

**Permission is asked for on a deliberate tap, never at launch.** Asking before the member has seen
anything worth being told about is how an app collects a permanent "no" on its first screen.

## External dependencies

| Dependency              | For                         | Notes                                                                                    |
| ----------------------- | --------------------------- | ---------------------------------------------------------------------------------------- |
| `/api/v1`               | everything                  | Bearer token, cross-origin. `config/cors.php` exposes `X-Api-Version` and `Retry-After`. |
| FCM (Android)           | push                        | Server-side already (Phase 12). Client needs `google-services.json`.                     |
| APNs (iOS)              | push                        | Through FCM. Needs an APNs key uploaded to the Firebase project.                         |
| Apple Developer Program | iOS builds                  | £79/year. Required for push; a free account runs on your own device only.                |
| Bunny Fonts             | Poppins / DM Mono / Tajawal | A CDN rather than bundled — see "Known limitations".                                     |

Nothing is called directly except the API. The app holds no third-party keys.

## Permissions

The app enforces nothing of its own; it shows what the API lets it see. Three layers decide, in
this order, and they answer different questions:

1. **The token's scopes** — what this _credential_ may do. The app asks for eight
   (`requestedAbilities`) and nothing more, so a member looking at Settings → API tokens on the web
   sees an honest answer to "what can my phone do with my account". A `403
   insufficient_token_ability` names what would have been enough.
2. **The policies** — what this _member_, on this team, may do. A read-only member's writes fail
   server-side, and the app reports the message.
3. **`OutreachGuard`** — whether this _prospect_ may be approached. Not a permission check, and
   deliberately consulted **before** a compose screen is offered rather than after it is attempted
   (`GET /leads/{lead}/outreach/decisions`).

## Brand

Built from the tokens, never raw hex (CLAUDE.md §2a). `src/theme/brand.css` is the one file with
literals in it, and it is the CSS face of `@shared/tokens.ts`.

- Cloud Cream canvas, cream surfaces, Ink as text. Deep Teal for structure and links, Midnight Ink
  for the primary action.
- **Signal Orange appears nowhere as a status.** It is defined as `--alima-orange` for decoration
  and is deliberately not mapped to any Ionic status role.
- The grade badge is one hue in four steps with the letter always present — a four-step scale of one
  thing, not four statuses. An **excluded** lead is drawn as a state, because that is what it is.
- The funnel is a labelled bar list, not a funnel graphic: a tapering shape distorts the comparison
  it is drawn to make, and every row carries its own name and figure so identity never depends on a
  colour.
- Every KPI delta carries an arrow glyph and the server's own wording beside the colour.
- The logo is drawn from the master path (`@shared/brand/artwork.ts`), kept above its minimum sizes,
  and forced `dir="ltr"` so it is never mirrored.
- EN/AR with full RTL (ADR 0007). Arabic is Tajawal, ~10% larger, never letter-spaced; identifiers
  (scores, company numbers, scope strings) stay LTR inside it.

## Known limitations / TODO

- **The asset pass.** The iOS `AppIcon` is still Capacitor's placeholder artwork, and the legacy
  Android launcher PNGs (API 23–25) are too. Both need a rasterised export of the signature at a
  dozen sizes, which is a design deliverable rather than something to synthesise from the vector —
  see `mobile/ios/App/App/Assets.xcassets/AppIcon.appiconset/README.md`. Everything that _can_ be
  generated from the master path already is (`npm run artwork`): the Android adaptive icon, its
  launch screen and its notification icon. **This blocks a store submission.**
- **iOS needs a Mac** for `pod install`, the Push Notifications capability and the signing team.
  Listed step by step in `docs/runbooks/mobile-dev.md` §6.
- **The token is in Preferences, not the Keychain/Keystore.** A considered trade: the token is
  scoped, revocable from either surface, and reading those files needs the device unlocked plus
  either root or a backup of it. The Keychain upgrade is a Phase 20 item and is a one-file change
  given the shape of `storage.ts`.
- **Fonts come from a CDN.** `display=swap` means the first paint falls through the token's own
  Arial/system chain rather than blocking, and the app needs a network to show anything anyway —
  but bundling the `woff2` files is the right move before submission (one less third-party request
  on a cold launch, and a real offline first paint).
- **No offline cache.** The module's plan called for "offline-tolerant reads where sensible"; what
  shipped is honest failure instead — a clear "could not be reached" with a retry, and the last
  loaded screen left on screen rather than blanked. A read-through cache is worth doing, and is
  worth doing deliberately rather than as a side effect of a service worker.
- **No realtime.** The web app has Reverb; the app refreshes on view-enter and on pull-to-refresh,
  and push covers the things that actually need to interrupt someone. Deliberate for now: a
  WebSocket held open on a phone is a battery cost that has to earn itself.
- **Dark mode is pending brand-owner approval** (`brand-tokens.md` §7.1), the same caveat the web app
  carries. The values here match the web app's proposal so the two surfaces at least agree.
- **One lead at a time.** No bulk actions, no board drag-and-drop, no saved views. All three are
  desk work.
- **No "add a lead" screen**, although `POST /leads` exists and the API doc argues the phone is
  exactly where a business card gets typed in. Left out to keep this phase's surface to the journey
  the contract test pins; it is the obvious next screen.

## Review (2026-10-31)

Reviewed at the end of Phase 18 by `laravel-module-reviewer` (the two-factor exchange and CORS)
and `brand-reviewer` (the app). Fixed from their findings:

**Security and correctness**

- **The second factor had no per-account cap.** The route's `auth-token` limiter is 5/min keyed on
  email **+ IP** — the right shape for guessing a password, the wrong one for a six-digit code,
  where an attacker who already holds the password buys five more guesses with every rotated IP.
  `TwoFactorChallenge` now counts failures against the **user** (10 per 15 minutes), where
  rotating addresses buys nothing, and answers `429` with a `Retry-After`. The 423 challenge is
  deliberately **not** counted: nothing was guessed.
- **A recovery code could be spent twice, or spent for nothing.** Fortify's `replaceRecoveryCode`
  is a read-modify-write on an in-memory model, so two concurrent requests with the same code could
  both pass the match before either saved. The spend now re-reads the row `FOR UPDATE` inside a
  transaction. And the whole exchange — challenge _and_ mint — is now one transaction in the new
  `ExchangeCredentialsForTokenAction`, so a failure while minting no longer burns a one-time code
  the member cannot get back.
- **The controller had grown to three steps.** Credential check, challenge and mint are one Action
  now, which is both the repo's rule (thin controllers call one Action) and what makes the
  transaction above possible.
- **An unknown email answered faster than a known one with a wrong password** — an enumeration
  oracle, pre-existing. The Action now verifies against a fixed throwaway hash when there is no
  user, equalising the dominant cost.
- **CORS was wider than it needed to be.** `sanctum/csrf-cookie` is in the framework's default
  paths for a cookie-authenticated cross-origin SPA; this app's web client is same-origin Inertia
  and the mobile app carries a bearer token, so nothing needs it from another origin.

**Brand and accessibility**

- **The logo appeared in three forbidden colourways.** Teal on Ink on the dark sign-in screen
  (§4's "Never" list, 1.9:1); a Teal 60 _tint_ in the Settings footer (§1.3: never tint the logo);
  and a Teal 60 tint again on the Android night splash, because its drawable referenced
  `colorPrimary`, which the night theme redefines. Each now has its own approved pairing, and the
  splash has a dedicated `alima_splash_mark` colour so the theme cannot retint the mark.
- **The choice chips were not keyboard- or switch-reachable.** The stage, tone, language, variant,
  channel and outcome pickers were `ion-chip` with a click handler — no role, no tab stop, no
  pressed state, and selection carried by fill colour alone. They are real `<button>`s now, in
  labelled groups, with `aria-pressed`, `aria-current` on the live stage, and 44px targets. These
  are the app's main write interaction, so this was WCAG 2.1.1 and 4.1.2 failing on the thing
  people actually do.
- **Four raw hexes had escaped `brand.css`** — the dark data-mark teal in two components and two
  dark neutrals in the grade badge that were duplicates of existing tokens. The data mark is now
  `--alima-chart-teal`, which carries both themes; the neutrals use the tokens they duplicated.
- **The unread badge was `danger`.** An unread notification is a count, not a failure, and the
  status ramp's red is reserved. It is `primary` now, `aria-hidden`, with the count folded into
  the tab's own accessible name — an `aria-label` on a bare span is routinely ignored.
- **The native `<select>`s were under 16px**, which makes iOS zoom the whole page on focus, and
  under 44px tall. Both fixed through one `.alima-select`.
- **The eyebrow was 11px** against the book's 12px, and the focus ring 2px against the token's 3px.
- **The Arabic size bump did not reach Ionic's own text.** `font-size: 110%` on the root scales
  what is sized in `rem` — all of this app's CSS — but Ionic sizes its components in `px` inside
  shadow DOM, so the chrome a member touches most stayed at Latin size. The toolbar, tabs, buttons,
  segments, chips, inputs and overlays are now scaled explicitly under RTL.
- **Buttons were 8px radius under a comment that said pill.** The book says pill; they are pill.
- **The KPI delta assumed a rise is good.** The server sends no polarity, and `ai_spend` is a tile
  where a rise is bad — it has no delta today, so nothing was wrong on screen, but it would have
  been the day it got one. Colour is now limited to an explicit set of higher-is-better keys;
  everything else keeps the arrow and the server's wording in neutral ink.
- **`BrandMark` enforced one minimum for two lock-ups**, so the signature's 120px floor was
  unguarded. Also: the KPI tile could be pushed wider than its grid column by a long currency
  figure, and the "scored against your ideal customer" eyebrow competed with its heading for the
  same row at phone width.

Accepted as-is, with reasons:

- **`ValidationException` from a service** rather than a `DomainException`. It bends "validation
  only in FormRequests" — but a wrong code belongs under the input the person typed it into, which
  means field-keyed `details`, and it is what Fortify itself throws. The envelope is identical
  either way (`ApiExceptionRenderer` renders both).
- **`allowed_origins: ['*']`.** Narrowing it would add no control — a native client sends whatever
  `Origin` it likes — while breaking the WebView origins, which differ per platform and per
  Capacitor version. It is safe only because `supports_credentials` is false, which is now asserted
  rather than assumed.
- **Mixed font weights per layout** (400/500/600/700 against the book's two). Pre-existing, matches
  the web app, and recorded as an open question in `brand-tokens.md` §7c. The mobile app should
  follow whatever that decision becomes rather than diverge now.
- **Captions and metadata at 13px.** The book's floor is for body text; a one-word label or a
  timestamp is not body, and raising everything to 16px on a 360px-wide screen costs more than it
  gives. Prose is at 14px minimum, and the native controls are at 16px where it actually matters.
- **"Log out" beside "Sign in".** Inconsistent, and both keys are shared with the web app — fixing
  it here alone would make the two surfaces disagree, which is worse. One for a copy pass.
- **The development-only server field's labels are hard-coded English.** It does not exist in a
  release build.

## Changelog

| Date       | Phase | Change                                                                                                                                                                                                                                                                                                                                                                                                                                                                                | Files                                                                                                                                                                                                                                                                                                                                                                                                                                                                       |
| ---------- | ----- | ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| 2026-10-31 | 18    | Built the app: `/mobile` scaffold (Capacitor + Ionic Vue + TS) importing `resources/js/shared/` by alias; sign-in with the two-factor exchange; dashboard; leads list and detail; stage moves, notes and tasks; AI draft, edit and send; logged approaches with the suppression warning; the bell; settings (language, appearance, push, sign-out); push via FCM/APNs; EN/AR + RTL; Android and iOS projects with brand icon, splash and colours generated from the master logo path. | `mobile/**`, `resources/js/shared/brand/artwork.ts` (moved), `resources/js/components/{AppLogo,BrandArtwork,PandaFace}.vue`, `lang/{en,ar}.json`, `vite.config.ts`                                                                                                                                                                                                                                                                                                          |
| 2026-10-31 | 18    | **Two-factor token exchange** — `POST /auth/token` now settles a 2FA account in one request (`423 auth.two_factor_required`, then `code` or `recovery_code`) instead of refusing it. Fortify's own provider, encrypter and one-time recovery codes do the verifying; the whole exchange is one Action and one transaction, with a per-account failure limiter over the route's per-IP one.                                                                                            | `app/Modules/Auth/Actions/ExchangeCredentialsForTokenAction.php`, `app/Modules/Auth/Services/TwoFactorChallenge.php`, `app/Modules/Auth/Exceptions/TwoFactorRequiredException.php`, `app/Modules/Auth/Data/{TokenExchangeData,IssuedApiToken}.php`, `app/Modules/Auth/Http/{Controllers/Api/V1/ApiTokenController,Requests/IssueApiTokenRequest}.php`, `app/Modules/Core/Support/ApiDocs/DocumentsApiContract.php`, `tests/Feature/Auth/ApiTokenTest.php`, `tests/Pest.php` |
| 2026-10-31 | 18    | **The review round** (see Review above): the per-account 2FA limiter, the atomic recovery-code spend, three forbidden logo colourways, keyboard-reachable choice chips, the raw hexes, the 16px native controls, the Arabic bump reaching Ionic, and the KPI delta’s polarity assumption.                                                                                                                                                                                             | `app/Modules/Auth/**`, `config/cors.php`, `mobile/src/**`, `mobile/android/app/src/main/res/values{,-night}/colors.xml`, `mobile/scripts/generate-native-artwork.mjs`                                                                                                                                                                                                                                                                                                       |
| 2026-10-31 | 18    | **CORS published.** `exposed_headers` was empty, so cross-origin the shared client could not read `X-Api-Version` or a `429`'s `Retry-After` — not missing, _unreadable_, and only ever on a non-same-origin caller.                                                                                                                                                                                                                                                                  | `config/cors.php`, `tests/Feature/Api/V1/CorsTest.php`                                                                                                                                                                                                                                                                                                                                                                                                                      |
| 2026-10-31 | 17    | Shared client types, payload types and the generated OpenAPI contract made ready for this phase; `MobileJourneyTest` added.                                                                                                                                                                                                                                                                                                                                                           | `resources/js/shared/**`, `docs/api/**`                                                                                                                                                                                                                                                                                                                                                                                                                                     |
