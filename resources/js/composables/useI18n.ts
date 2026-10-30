import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { LocaleOption } from '@/types';

export type UseI18nReturn = {
    /** Active locale code, e.g. `en` or `ar`. */
    locale: ComputedRef<string>;
    /** Writing direction of the active locale. */
    dir: ComputedRef<'ltr' | 'rtl'>;
    /** True when the active locale is right-to-left. */
    isRtl: ComputedRef<boolean>;
    /** Locales offered in the switcher. */
    available: ComputedRef<LocaleOption[]>;
    /** Translate a key, with optional `:placeholder` replacements. */
    t: (key: string, replacements?: Record<string, string | number>) => string;
    /** Switch the UI language (persists via cookie, reloads shared props). */
    setLocale: (code: string) => void;
};

/**
 * Lightweight i18n over Inertia-shared translations — no runtime dependency.
 * Translations are the active locale's JSON bundle shared from the server; the
 * `dir` attribute is kept in sync by `useLocaleDirection` (wired in app.ts).
 */
export function useI18n(): UseI18nReturn {
    const page = usePage();

    const shared = computed(() => page.props.locale);
    const locale = computed(() => shared.value?.current ?? 'en');
    const dir = computed<'ltr' | 'rtl'>(() => shared.value?.dir ?? 'ltr');
    const isRtl = computed(() => dir.value === 'rtl');
    const available = computed<LocaleOption[]>(
        () => shared.value?.available ?? [],
    );

    function t(
        key: string,
        replacements: Record<string, string | number> = {},
    ): string {
        const messages = shared.value?.translations ?? {};
        let line = messages[key] ?? key;

        for (const [token, value] of Object.entries(replacements)) {
            line = line.replace(`:${token}`, String(value));
        }

        return line;
    }

    function setLocale(code: string): void {
        if (code === locale.value) {
            return;
        }

        router.post('/locale', { locale: code }, { preserveScroll: true });
    }

    return { locale, dir, isRtl, available, t, setLocale };
}
