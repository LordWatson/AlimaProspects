# Runbook — running and testing the mobile app

> For whoever has to get `/mobile` onto a screen, from a machine that has never built a mobile app
> before. It assumes you know Laravel and the web app, and nothing at all about Android Studio or
> Xcode.
>
> See `docs/modules/mobile-app.md` for what the app is and how it is built, and `docs/api/README.md`
> for the API it consumes.

---

## 0. The one thing to understand first

**There is no "mobile build" in the way there is a Laravel build.** The app is an ordinary web
bundle — Vue, built by Vite — plus a native shell (Capacitor) whose only job is to load that bundle
in a full-screen WebView and give it access to the phone's hardware.

That is why there are three different ways to run it, and why the first one needs nothing
installed:

| How                     | What you need                | What it proves                                                              |
| ----------------------- | ---------------------------- | --------------------------------------------------------------------------- |
| **1. Desktop browser**  | Nothing you don't have       | Every screen, every API call, RTL, dark mode. **Not** push, not the splash. |
| **2. Android emulator** | Android Studio (~8 GB, free) | The real app, including push and the native shell.                          |
| **3. iPhone**           | A **Mac** with Xcode         | The same, on iOS. Cannot be done from Windows — see §6.                     |

Start at 1. It is three commands and covers roughly ninety percent of what there is to look at.

---

## 1. Run it in a browser (start here — 3 minutes)

### 1.1 Install the dependencies, once

```bash
cd mobile
npm install
```

npm 11 will say that `esbuild` has an install script it has not run. It needs to run — it is how
Vite gets its binary:

```bash
npm install-scripts approve esbuild
```

### 1.2 Make sure the API is up

In a **separate terminal**, at the repository root:

```bash
composer dev
```

Then check it is actually answering, which takes two seconds and saves twenty minutes:

```bash
curl -i http://alimaprospects.test/api/v1/auth/token -X POST \
  -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{"email":"nobody@example.com","password":"x","device_name":"probe"}'
```

You want a **422** with `{"error":{"code":"validation_failed",…}}`, an `X-Api-Version: 1` header and
an `Access-Control-Allow-Origin: *`. A 422 means the API is alive and rejecting bad credentials,
which is the correct answer. A connection error means Herd is not serving the site.

### 1.3 Start the app

```bash
cd mobile
npm run dev
```

It prints a URL — `http://localhost:5174/`, or the next free port if the web app's own Vite server
already has that one. Open it in Chrome.

### 1.4 Make it look like a phone

In Chrome, press **F12**, then **Ctrl+Shift+M** (the device-toolbar icon), and pick **iPhone 14 Pro**
or **Pixel 7** from the dropdown at the top. Reload. You now have the app at phone size, with touch
emulation.

This is not a cosmetic nicety: several things — the tab bar, the safe-area padding, the way the
keyboard pushes content — only make sense at that size.

### 1.5 Sign in

Use an account that exists in your local database. The seeded ones are listed by:

```bash
php artisan tinker --execute="App\Models\User::pluck('email')"
```

(on Windows, `C:/Users/alexa/.config/herd/bin/php.bat artisan …` — see CLAUDE.md §1).

The sign-in screen has a collapsed **Server** section at the bottom. It is there only in development
builds, and it is how you point the app at a different API without rebuilding — you will need it in
§2. Leave it alone for now.

---

## 2. What to actually test

Work down this list. It is the same journey `tests/Feature/Api/V1/MobileJourneyTest.php` walks
server-side, so if a step fails here and that test passes, the fault is in the app rather than the
API — which is most of the diagnosis done.

### 2.1 Signing in

- [ ] A wrong password puts the message **under the email field**, not in a toast.
- [ ] A correct password lands on the dashboard, and the back gesture does **not** return to the
      sign-in form.
- [ ] Force-quit and reopen (in a browser: reload). You are still signed in, and you do **not** see
      the sign-in form flash first.
- [ ] **Two-factor.** Turn 2FA on for your account from the web app (Settings → Security), then
      sign out of the mobile app and sign in again. You should get a second screen asking for the
      six-digit code — not an error. Check both paths: the authenticator code, and
      **Use a recovery code instead** with one of the codes the web app showed you.
- [ ] A wrong 2FA code shows under the code field and lets you try again. It does not send you back
      to the password screen.

### 2.2 The dashboard

- [ ] The KPI figures match the web dashboard for the same window. Open both side by side.
- [ ] Switching **Last 7 days / Last 30 / Last 90** changes the numbers.
- [ ] Pull down to refresh (drag from the top of the content).
- [ ] An account with no leads at all shows "Nothing sourced yet" rather than a grid of zeros.

### 2.3 Leads

- [ ] The list loads, and scrolling to the bottom loads more rather than stopping at 20.
- [ ] Typing in the search box filters after a short pause.
- [ ] The **A / B / C / D** chips filter by grade; **All** clears it.
- [ ] A lead with a legal restriction (UK/UAE marketing rules) shows an amber line in its row.

### 2.4 One lead

- [ ] Opening a lead shows the company, its fit score and its timeline.
- [ ] Tapping a different **stage** chip moves it. Go back to the list — the stage has changed
      there too.
- [ ] Adding a **note** makes it appear in the timeline.
- [ ] Adding a **task** with a due date works; leaving the date empty also works.
- [ ] **Draft a message** is disabled, with a sentence explaining why, on a lead the compliance
      guard has shut. It is enabled on one it has not.

### 2.5 Outreach

- [ ] **Draft a message** → pick a tone → **Write a draft**. A subject and body come back.
- [ ] Switching between **Variants** changes the body instantly, with no spinner — they all arrived
      with the first draft and cost nothing.
- [ ] Editing the body shows a **Save draft** button; sending saves first.
- [ ] **Log an approach** → **Not interested** or **Opted out** shows the do-not-contact warning
      _before_ you save. Everything else does not.

### 2.6 Notifications

- [ ] The tab bar shows an unread count when there is one.
- [ ] Tapping a row marks it read and follows it where it points.
- [ ] **Mark all read** clears the badge.

To generate one to look at, from the repository root:

```bash
php artisan tinker --execute="App\Modules\Notifications\Services\NotificationDispatcher::class"
```

…or more easily, run a discovery from the web app and wait for the "strong new matches"
notification.

### 2.7 Arabic and right-to-left

This is the one most likely to be broken and the one nobody checks.

- [ ] **Settings → Language → العربية.** The whole layout mirrors: the tab bar, the back chevron,
      the list arrows, every margin.
- [ ] The **logo is not mirrored.** It never is, in any language.
- [ ] A lead's **company number, website and score** still read left-to-right inside the Arabic
      layout. They are identifiers, not prose.
- [ ] Arabic text is **not letter-spaced** anywhere, including the small caps labels.
- [ ] Switch back to English. Everything returns.

### 2.8 Dark mode

- [ ] **Settings → Appearance → Dark.** Nothing is unreadable; no white boxes.
- [ ] **System** follows the OS, and keeps following it if you change the OS theme with the app open.

> Dark mode is still marked **pending brand-owner approval** (`docs/brand/brand-tokens.md` §7.1).
> Note anything that looks wrong, but it is not a defect against an approved spec yet.

### 2.9 The failure cases

Worth ten minutes, because they are what a member actually hits on a train.

- [ ] **Stop the API** (Ctrl+C the `composer dev` terminal) and pull to refresh. You should get
      "Alima could not be reached. Check your connection and try again." — not a blank screen and
      not a raw error.
- [ ] Start it again and retry. The screen recovers.
- [ ] **Revoke the app's token** from the web app (Settings → API tokens — it will be called
      "Alima in Chrome"), then use the app. It should sign you out rather than showing empty screens.

---

## 3. Android (the real app) — about an hour, mostly downloading

You need this to test **push notifications** and to see the actual app. Everything else is faster
in a browser.

### 3.1 Install Android Studio

1. Download it from <https://developer.android.com/studio> (free, ~1 GB download, ~8 GB installed).
2. Run the installer and accept the defaults. It installs the Android SDK and a **JDK** — you do
   not need to install Java separately, and you should not.
3. Open it once and let the setup wizard finish. Choose **Standard** when it asks.

### 3.2 Create an emulator

In Android Studio: **More Actions → Virtual Device Manager → Create Device**.

- Pick **Pixel 7** (or anything recent).
- Pick a system image — the one marked **Recommended** with a recent API level. If it says
  **Download** next to it, click that first.
- Finish, then press ▶ to boot it. The first boot takes a few minutes.

Leave the emulator running.

### 3.3 Tell the app where the API is

**This is the step everyone gets wrong.** Inside the emulator, `localhost` means _the emulator
itself_, and `alimaprospects.test` does not resolve at all — Herd's domain only exists on your
machine's hosts file. The emulator reaches your machine at the special address **`10.0.2.2`**.

Herd serves on port 80, so:

```bash
cd mobile
cp .env.example .env
```

and set:

```
VITE_API_URL=http://10.0.2.2
```

(You can also leave `.env` alone and type it into the **Server** box on the sign-in screen once the
app is running. Same effect, no rebuild.)

> Plain HTTP works here because `android/app/src/debug/AndroidManifest.xml` permits cleartext in
> **debug** builds only. A release build cannot send your token over plain HTTP however the URL is
> set, which is deliberate.

### 3.4 Build and run

```bash
cd mobile
npm run build      # compile the web bundle
npx cap sync       # copy it into the native project
npm run android    # build the APK, install it, launch it
```

The first `npm run android` downloads Gradle and a lot of Android dependencies. Expect ten minutes
and a great deal of output. Subsequent runs take under a minute.

If it cannot find the SDK, tell it where:

```bash
echo "sdk.dir=C:\\Users\\<you>\\AppData\\Local\\Android\\Sdk" > android/local.properties
```

**A faster loop while you are iterating on screens:** `npx cap run android --live-reload --external`
points the WebView at the Vite dev server, so a save re-renders on the emulator without rebuilding.

### 3.5 Push notifications

Push needs Firebase, and Firebase needs an account. Skip this unless you are specifically testing
push.

1. Create a Firebase project at <https://console.firebase.google.com> (free).
2. **Add app → Android.** The package name must be exactly **`app.alima.prospects`**.
3. Download the `google-services.json` it gives you and put it at
   `mobile/android/app/google-services.json`. It is gitignored, deliberately.
4. In the Firebase console: **Project settings → Service accounts → Generate new private key**.
   Save the JSON somewhere outside the repository and point the **backend** at it, in the root
   `.env`:

    ```
    FCM_CREDENTIALS=C:/path/to/service-account.json
    ```

5. Rebuild and run (`npm run build && npx cap sync && npm run android`).
6. In the app: **Settings → Push → on**. Android will ask for permission; allow it.
7. Check it registered — from the repository root, the device should now be on the account:

    ```bash
    php artisan tinker --execute="App\Modules\Notifications\Models\PushDevice::count()"
    ```

8. Trigger a notification (a discovery run, or a task reminder) and watch it arrive.

If nothing arrives: the usual cause is step 3 or step 4 missing. The app registering successfully
only proves the **device** side; `FCM_CREDENTIALS` is what lets the server send.

---

## 4. A physical Android phone

Much the same, with two changes.

1. On the phone: **Settings → About phone → tap "Build number" seven times**, then
   **Settings → Developer options → USB debugging → on**. Plug it in and accept the prompt.
2. `VITE_API_URL` must be your machine's **LAN address** — `10.0.2.2` is an emulator-only alias.
   Find it with `ipconfig` (the IPv4 address on your Wi-Fi adapter), and make sure the phone is on
   the same network:

    ```
    VITE_API_URL=http://192.168.1.50
    ```

    Windows Firewall will probably block the first attempt. Allow `nginx` (Herd's server) on
    private networks when it asks.

Then `npm run android` and pick the phone when it asks which device.

---

## 5. Checking the brand and accessibility

Run these in the browser, at phone size.

- **Keyboard only.** Tab through a screen. Every control should be reachable and show a visible
  focus ring.
- **Contrast.** Chrome DevTools → the Elements panel → hover any text → the contrast ratio is in
  the tooltip. Body text wants 4.5:1, large text and controls 3:1.
- **Signal Orange.** It should appear nowhere as a status. If something that means "error",
  "warning" or "success" is orange, that is a brand breach (CLAUDE.md §2a).
- **The logo.** Never below its minimum size, never recoloured outside the approved colourways,
  never mirrored.

---

## 6. iOS — what you can and cannot do from Windows

**You cannot build or run the iOS app on Windows.** Not a configuration problem: Xcode is macOS-only
and Apple does not license it elsewhere. There is no workaround worth having.

What is already done, and committed, so that a Mac can pick it up:

- The Xcode project exists at `mobile/ios/`, with the plugins wired up.
- `AppDelegate.swift` carries the APNs callbacks the push plugin needs — the step Capacitor cannot
  do for you, and the one whose absence looks like a working app until a notification is due.
- `Info.plist` declares English and Arabic.
- The launch screen is the brand canvas, with a dark variant.

What a Mac still has to do, in order:

1. `sudo gem install cocoapods` (once), then `cd mobile && npm install && npm run build && npx cap sync`.
2. `npx cap open ios` to open Xcode.
3. **Signing & Capabilities** → pick a Team (an Apple Developer account, £79/year — a free account
   works for running on your own device but not for push).
4. **+ Capability → Push Notifications.** This is what creates the entitlement; it cannot be
   committed usefully because it is tied to your team.
5. Create an **APNs key** in the Apple Developer portal and upload it to the same Firebase project
   under **Project settings → Cloud Messaging → Apple app configuration**. The server talks to FCM
   for both platforms; FCM talks to APNs.
6. Run on a **physical iPhone** — the simulator cannot receive push.

For the simulator, `VITE_API_URL` can stay as the Herd domain: a simulator shares the Mac's own DNS.
On a physical iPhone, use the Mac's LAN address, and note that iOS's App Transport Security blocks
plain HTTP — run `herd secure` and use `https://`.

---

## 6a. Secrets: what must never be committed

Four kinds of file turn up during the steps above, and all four are gitignored in
`mobile/.gitignore`. Check before you commit anyway, because the cost is asymmetric.

| File                                 | Where it lands        | Why it is ignored                                                                                                                                                                                                                                                           |
| ------------------------------------ | --------------------- | --------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `*.jks`, `*.keystore`                | `mobile/android/`     | The Android **upload key**. Whoever holds it can publish an update to the app under our name, and Google will not rotate it without a support request. Capacitor's own template lists these and leaves them commented out, which is why `mobile/.gitignore` adds them back. |
| `keystore.properties`                | `mobile/android/`     | Where that key's passwords conventionally go.                                                                                                                                                                                                                               |
| `google-services.json`               | `mobile/android/app/` | Firebase's Android client config — each developer downloads their own (§3.5).                                                                                                                                                                                               |
| `*.p12`, `*.p8`, `*.mobileprovision` | `mobile/ios/`         | Apple signing certificates, the APNs key and provisioning profiles.                                                                                                                                                                                                         |

The **server's** Firebase credential is a service-account JSON and does not live in the repository
at all: it sits anywhere on disk and the root `.env` points at it with `FCM_CREDENTIALS`.

If one of these ever does get committed, rotating the credential is the fix — removing the file in
a later commit does not remove it from history.

## 7. The gates, before you call it done

```bash
cd mobile
npm run check         # lint + formatting
npm run types:check   # vue-tsc
npm run test          # vitest
npm run build
```

And at the repository root, the usual `composer test` and `npm run check`. See the `quality-gates`
skill, which runs all of it with the right binaries.

---

## 8. When something is wrong

| Symptom                                                     | Almost always                                                                                                        |
| ----------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------- |
| Blank white screen in the browser                           | A JavaScript error. Open the console (F12).                                                                          |
| "Alima could not be reached" in the browser, but curl works | Wrong `VITE_API_URL`, or the API is on a different host than the one in the **Server** box.                          |
| Everything 401s straight after signing in                   | The token was revoked, or you are pointed at a different database than the one you created the account in.           |
| 403 with `insufficient_token_ability`                       | The app asked for a scope it no longer has. Sign out and in again; see `requestedAbilities` in `src/lib/session.ts`. |
| Works in the browser, blank in the emulator                 | You changed the source but did not re-run `npm run build && npx cap sync`.                                           |
| Emulator cannot reach the API at all                        | `localhost` or `alimaprospects.test` instead of `10.0.2.2`. See §3.3.                                                |
| Push permission granted but no device row appears           | `google-services.json` missing from `mobile/android/app/`.                                                           |
| Device row appears but no notification arrives              | `FCM_CREDENTIALS` not set on the **server**.                                                                         |
| Gradle fails with "SDK location not found"                  | `android/local.properties` missing. See §3.4.                                                                        |
| `npm run dev` picks a different port                        | The web app's Vite server already has 5174. Harmless — use the URL it prints.                                        |
