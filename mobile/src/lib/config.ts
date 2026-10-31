import { ref } from 'vue';
import { storage, storageKeys } from '@/lib/storage';

/**
 * Where the app looks for `/api/v1`.
 *
 * Baked in from `VITE_API_URL` at build time, which is how a release build works: a shipped app
 * talks to production and to nowhere else.
 *
 * A **development** build also reads an override the sign-in screen can set, because during this
 * phase one bundle has to reach the API by four different names — `alimaprospects.test` from a
 * desktop browser and the iOS simulator, `10.0.2.2` from the Android emulator, a LAN address from
 * a physical handset, and whatever the tunnel is called when someone demos it. Re-flashing a
 * build to change a hostname is the kind of friction that ends with the URL hard-coded in a
 * branch nobody merges.
 *
 * The override is gated on `import.meta.env.DEV` in both directions: it is never read and never
 * written in a release build, so there is no way to point a shipped app at another server.
 */
const fallbackUrl = 'http://alimaprospects.test';

const configured = (import.meta.env.VITE_API_URL ?? '').trim();

const apiUrl = ref(
    stripTrailingSlash(configured === '' ? fallbackUrl : configured),
);

export const isDev = import.meta.env.DEV;

/** The origin in force now. Reactive, so the sign-in screen's field reflects a change. */
export function apiBaseUrl(): string {
    return apiUrl.value;
}

export const apiUrlRef = apiUrl;

/** Load the development override, if there is one. Called once at boot. */
export async function restoreApiUrl(): Promise<void> {
    if (!isDev) {
        return;
    }

    const stored = await storage.get(storageKeys.apiUrl);

    if (stored !== null && stored.trim() !== '') {
        apiUrl.value = stripTrailingSlash(stored.trim());
    }
}

/**
 * Point the app at a different origin (development only).
 *
 * Returns false for anything that is not an absolute `http(s)` URL, rather than storing it and
 * failing on the next request with a message about the network.
 */
export async function setApiUrl(value: string): Promise<boolean> {
    if (!isDev) {
        return false;
    }

    const trimmed = stripTrailingSlash(value.trim());

    if (!/^https?:\/\/[^\s/]+/iu.test(trimmed)) {
        return false;
    }

    apiUrl.value = trimmed;
    await storage.set(storageKeys.apiUrl, trimmed);

    return true;
}

function stripTrailingSlash(value: string): string {
    return value.replace(/\/+$/u, '');
}
