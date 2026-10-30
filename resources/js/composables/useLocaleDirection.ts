import { router } from '@inertiajs/vue3';
import type { Page } from '@inertiajs/core';
import type { LocaleShared } from '@/types';

/**
 * Keeps `<html lang>` and `<html dir>` in step with the server-resolved locale
 * across Inertia (SPA) navigations — the Blade root sets them on first paint,
 * this syncs them whenever the shared locale prop changes (e.g. after the user
 * switches language). Wired once from app.ts.
 */
function applyFromPage(page: Page): void {
    if (typeof document === 'undefined') {
        return;
    }

    const locale = page.props.locale as LocaleShared | undefined;

    if (!locale) {
        return;
    }

    document.documentElement.lang = locale.current;
    document.documentElement.dir = locale.dir;
}

export function initializeLocaleDirection(): void {
    if (typeof window === 'undefined') {
        return;
    }

    // The Blade root sets lang/dir on first paint; this keeps them in sync after
    // every subsequent Inertia visit (including a language switch).
    router.on('success', (event) => applyFromPage(event.detail.page));
}
