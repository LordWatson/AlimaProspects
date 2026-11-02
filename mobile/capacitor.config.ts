import type { CapacitorConfig } from '@capacitor/cli';

/*
 * Capacitor's native shell configuration — the bridge between the Vite bundle in `dist/` and the
 * iOS and Android projects.
 *
 * `appId` is the bundle identifier Apple and Google key everything off: the signing certificate,
 * the APNs topic, the Firebase Android app. It cannot be changed after a store listing exists,
 * so it is settled here rather than at submission time.
 */
const config: CapacitorConfig = {
    appId: 'app.alima.prospects',
    appName: 'Alima Prospects',
    webDir: 'dist',

    // iOS defaults to `capacitor://localhost`; Android to `https://localhost`. Left alone — the
    // API is reached cross-origin with a bearer token (see `config/cors.php`), so there is no
    // cookie that would need the WebView to share the API's origin.
    server: {
        androidScheme: 'https',
    },

    ios: {
        // The WebView's own background, visible for the instant before Vue paints and behind a
        // rubber-band scroll. Cloud Cream, so the overscroll is brand rather than white.
        backgroundColor: '#f5f2e8',
        contentInset: 'always',
    },

    android: {
        backgroundColor: '#f5f2e8',
        // The WebView serves the bundle from `https://localhost`, so a call to a plain-HTTP API
        // is mixed content and is blocked without this. It is inert in a release build: cleartext
        // is permitted only by `android/app/src/debug/AndroidManifest.xml`, so Android's network
        // layer refuses it before mixed-content policy is consulted at all.
        allowMixedContent: true,
    },

    plugins: {
        PushNotifications: {
            // The badge is the one presentation option that is state rather than an event: it
            // persists after the alert is dismissed, and nothing in the app currently clears it,
            // so claiming it would leave a number on the icon that never goes away.
            presentationOptions: ['alert', 'sound'],
        },
        Keyboard: {
            // Push the content up rather than resizing the WebView, so a focused input in a long
            // form does not reflow the whole page under the keyboard.
            resize: 'native',
        },
    },
};

export default config;
