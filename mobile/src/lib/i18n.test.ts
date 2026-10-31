import { beforeEach, describe, expect, it } from 'vitest';
import { currentLocale, dir, isRtl, setLocale, t } from '@/lib/i18n';

/*
 * The language layer, and specifically the three things that would break the app quietly rather
 * than loudly.
 */

beforeEach(async () => {
    await setLocale('en');
});

describe('translation', () => {
    it('falls through to the key, because the key is the English sentence', () => {
        // `lang/en.json` is an identity map, and every `*_label` the server sends is an English
        // source string. So an unknown key must read as English prose, never as an identifier —
        // which is also what makes a string the mobile app added before the Arabic bundle caught
        // up render as English instead of as `leads.empty.title`.
        expect(t('Nothing here yet')).toBe('Nothing here yet');
    });

    it('interpolates the server’s `:placeholder` form, not a new one', () => {
        // The notification rows carry keys + params written by the server
        // (`NotificationDispatcher`). A screen that invented `{count}` would print the
        // placeholder for every line that came from one.
        expect(t(':count unread', { count: 7 })).toBe('7 unread');
    });

    it('replaces every occurrence of a placeholder', () => {
        expect(t(':a and :a', { a: 'x' })).toBe('x and x');
    });

    it('leaves a placeholder alone when nothing was passed for it', () => {
        expect(t('Hello, :name')).toBe('Hello, :name');
    });
});

describe('Arabic', () => {
    it('loads the shared bundle and translates', async () => {
        // The bundle is `lang/ar.json` through a Vite alias — the same file the Laravel app
        // serves the web client. If this fails, the alias is broken and the whole app is English.
        await setLocale('ar');

        expect(currentLocale.value).toBe('ar');
        expect(t('Dashboard')).toBe('لوحة التحكم');
    });

    it('turns the document right-to-left', async () => {
        await setLocale('ar');

        expect(dir.value).toBe('rtl');
        expect(isRtl.value).toBe(true);
        // `dir` on the root element is what flips Ionic's whole layout — the back chevron, the
        // item detail arrow, the slide transitions and every logical padding in `app.css`.
        // Setting it on `body`, or only in CSS, leaves half the app the wrong way round.
        expect(document.documentElement.getAttribute('dir')).toBe('rtl');
        expect(document.documentElement.getAttribute('lang')).toBe('ar');
    });

    it('goes back to left-to-right on the way out', async () => {
        await setLocale('ar');
        await setLocale('en');

        expect(document.documentElement.getAttribute('dir')).toBe('ltr');
        expect(t('Dashboard')).toBe('Dashboard');
    });
});
