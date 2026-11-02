import { Preferences } from '@capacitor/preferences';

/**
 * Durable key/value storage, the same call on every platform.
 *
 * Capacitor's Preferences plugin is `UserDefaults` on iOS, `SharedPreferences` on Android and
 * `localStorage` in a browser — which is why `npm run dev` behaves like the handset rather than
 * like a different app with the same screens.
 *
 * It is **not** a secret store, and the access token lives here. That is a deliberate call rather
 * than an oversight: the token is scoped to what the app's screens need, it is revocable from
 * Settings on either surface, and reading these files needs the device unlocked and either root or
 * a backup of it. The Keychain/Keystore upgrade is a Phase 20 item, recorded in the module doc,
 * and the shape of this module is what makes it a one-file change when it comes.
 *
 * Every call swallows its own failure. Storage genuinely is unavailable sometimes — a browser with
 * site data blocked, a WebView in a weird state — and an app that cannot remember a preference
 * should still run, whereas one that throws out of `get` on launch shows a blank screen.
 */
export const storage = {
    async get(key: string): Promise<string | null> {
        try {
            const { value } = await Preferences.get({ key });

            return value;
        } catch {
            return null;
        }
    },

    async set(key: string, value: string): Promise<void> {
        try {
            await Preferences.set({ key, value });
        } catch {
            // Nothing to do: the caller's in-memory state is already correct, and this was only
            // the part that would have survived a relaunch.
        }
    },

    async remove(key: string): Promise<void> {
        try {
            await Preferences.remove({ key });
        } catch {
            // As above.
        }
    },
};

/** Every key the app stores, in one place, so a sign-out can clear the right ones. */
export const storageKeys = {
    token: 'alima.token',
    user: 'alima.user',
    locale: 'alima.locale',
    appearance: 'alima.appearance',
    /** Development only: the API origin typed on the sign-in screen. */
    apiUrl: 'alima.api_url',
    /** The last FCM/APNs registration token, so sign-out can deregister it. */
    pushToken: 'alima.push_token',
} as const;
