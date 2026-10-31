import { computed, ref } from 'vue';
import { StatusBar, Style } from '@capacitor/status-bar';
import { Capacitor } from '@capacitor/core';
import { storage, storageKeys } from '@/lib/storage';

/**
 * Light, dark, or whatever the handset is set to — the same three choices as the web app's
 * appearance setting, remembered per device.
 *
 * Dark mode is marked PENDING BRAND-OWNER APPROVAL in `brand-tokens.md` §7.1 and carries the
 * same caveat here as it does on the web; this is the switch, not a decision about the palette.
 *
 * The status bar is set alongside the theme because on a phone it is part of the page: Ink text
 * on a cream canvas needs dark status-bar glyphs, and leaving it on the default gives a white-on-
 * cream clock that is invisible.
 */
export type Appearance = 'light' | 'dark' | 'system';

const appearance = ref<Appearance>('system');
const prefersDark = ref(false);

export const currentAppearance = computed(() => appearance.value);

export const isDark = computed(() =>
    appearance.value === 'system'
        ? prefersDark.value
        : appearance.value === 'dark',
);

export const appearances: Appearance[] = ['light', 'dark', 'system'];

export async function restoreAppearance(): Promise<void> {
    const query = globalThis.matchMedia?.('(prefers-color-scheme: dark)');

    if (query !== undefined) {
        prefersDark.value = query.matches;
        // `system` has to keep following the system, including while the app is open — a handset
        // on an automatic schedule flips at sunset with the app in the foreground.
        query.addEventListener('change', (event) => {
            prefersDark.value = event.matches;
            applyAppearance();
        });
    }

    const stored = await storage.get(storageKeys.appearance);

    if (stored === 'light' || stored === 'dark' || stored === 'system') {
        appearance.value = stored;
    }

    applyAppearance();
}

export async function setAppearance(value: Appearance): Promise<void> {
    appearance.value = value;
    applyAppearance();
    await storage.set(storageKeys.appearance, value);
}

function applyAppearance(): void {
    const root = globalThis.document?.documentElement;

    if (root === undefined) {
        return;
    }

    // Ionic reads `.ion-palette-dark` for its own components; `.alima-dark` carries the brand
    // tokens in `theme/brand.css`. Both, so neither half of the app is left on the wrong theme.
    root.classList.toggle('alima-dark', isDark.value);
    root.classList.toggle('ion-palette-dark', isDark.value);

    if (!Capacitor.isNativePlatform()) {
        return;
    }

    // The plugin names these after the *background* it expects, not after the glyphs it draws:
    // `Style.Dark` is light text for a dark background, `Style.Light` dark text for a light one.
    // So the cream canvas wants `Style.Light`, which reads backwards and is why this is spelt out.
    void StatusBar.setStyle({
        style: isDark.value ? Style.Dark : Style.Light,
    }).catch(() => {
        // No status bar to set — a browser, or a platform without the plugin.
    });
}
