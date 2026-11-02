import { computed, ref } from 'vue';
import type { LocaleDir, SupportedLocale } from '@shared/types';
import { storage, storageKeys } from '@/lib/storage';

/**
 * The app's language, from the product's own translation bundles (ADR 0007).
 *
 * `@lang/ar.json` is the same file the Laravel app serves to the web client, reached through a
 * Vite alias rather than copied — the words of the product live in one place, and a second copy
 * is a copy that falls behind. It is a JSON string-key bundle (`"Leads": "العملاء المحتملون"`),
 * so **English needs no bundle at all**: the key is the English sentence and `t()` falls through
 * to it. That is why `en.json` is not imported here, and why ~120 KB stays out of the bundle.
 *
 * Arabic is loaded on demand, for the same reason: an English-speaking member never downloads it.
 *
 * Interpolation matches the server's: `:name` placeholders, replaced positionally by key. Keep it
 * identical — these are the same strings, and a mobile screen that invented `{name}` would
 * silently print the placeholder for every line that came from a notification row.
 */
const bundles: Partial<Record<SupportedLocale, Record<string, string>>> = {};

const locale = ref<SupportedLocale>('en');

export const currentLocale = computed<SupportedLocale>(() => locale.value);

export const dir = computed<LocaleDir>(() =>
    locale.value === 'ar' ? 'rtl' : 'ltr',
);

export const isRtl = computed(() => dir.value === 'rtl');

export const locales: { value: SupportedLocale; label: string }[] = [
    { value: 'en', label: 'English' },
    { value: 'ar', label: 'العربية' },
];

/**
 * Translate a key. Unknown keys return themselves, which is the contract the server relies on:
 * every `*_label`, notification `title` and KPI `label` it sends is an English source string,
 * and a missing Arabic line should read in English rather than show a dotted identifier.
 */
export function t(
    key: string,
    replacements: Record<string, string | number> = {},
): string {
    let line = bundles[locale.value]?.[key] ?? key;

    for (const [token, value] of Object.entries(replacements)) {
        line = line.replaceAll(':' + token, String(value));
    }

    return line;
}

export async function setLocale(code: SupportedLocale): Promise<void> {
    await loadBundle(code);

    locale.value = code;
    applyDocumentDirection();
    await storage.set(storageKeys.locale, code);
}

/** Restore the remembered language, falling back to the device's own. Called once at boot. */
export async function restoreLocale(): Promise<void> {
    const stored = await storage.get(storageKeys.locale);

    if (stored === 'ar' || stored === 'en') {
        await setLocale(stored);

        return;
    }

    // No choice on record: follow the handset. A member whose phone is in Arabic should not have
    // to go and find a language switch on first launch.
    const device = (globalThis.navigator?.language ?? 'en').toLowerCase();

    await setLocale(device.startsWith('ar') ? 'ar' : 'en');
}

/**
 * `dir` and `lang` on the root element — which is what flips Ionic's whole layout, not just the
 * text: `ion-item` detail arrows, the back button chevron, slide transitions and every logical
 * padding in `app.css` read from it.
 */
function applyDocumentDirection(): void {
    const root = globalThis.document?.documentElement;

    if (root === undefined) {
        return;
    }

    root.setAttribute('dir', dir.value);
    root.setAttribute('lang', locale.value);
}

async function loadBundle(code: SupportedLocale): Promise<void> {
    if (code === 'en' || bundles[code] !== undefined) {
        return;
    }

    try {
        const loaded = (await import('@lang/ar.json')) as {
            default: Record<string, string>;
        };

        bundles[code] = loaded.default;
    } catch {
        // The bundle failed to load — a chunk that never arrived on a bad connection. Fall
        // through to English rather than leaving the app on a dead screen; `t()` already does
        // exactly that for a key it has no line for.
    }
}
