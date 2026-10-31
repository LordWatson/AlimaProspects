import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeLocaleDirection } from '@/composables/useLocaleDirection';
import { initializeEcho } from '@/echo';
import AdminLayout from '@/layouts/AdminLayout.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';
import { palette } from '@/shared/tokens';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

void createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
            case name === 'About':
            // `public/` pages are reached by people who are not users — the hosted privacy notice
            // and the unsubscribe endpoint (Compliance, Phase 15). They bring their own
            // MarketingLayout, like the other public pages; the app shell would ask them to log in
            // to read a privacy notice.
            case name.startsWith('public/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // The internal panel has its own chrome, deliberately distinct so an operator never
            // mistakes it for a tenant screen (Phase 19a, ADR 0014). Distinct *within* the
            // brand — a Deep Ink shell — never by inventing colours.
            case name.startsWith('admin/'):
                return AdminLayout;
            // `onboarding/` deliberately falls through to the app shell. The business profile is
            // a destination in the sidebar, not a one-off wizard: giving it its own chrome made
            // the screen read like a different product, and onboarding is not hard-gated anyway
            // (see docs/modules/onboarding-business-profile.md).
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp: (app) => {
        app.directive('focus', {
            mounted: (el: HTMLElement, shouldFocus) => {
                if (shouldFocus.value !== false) {
                    el.focus();
                }
            },
        });
    },
    progress: {
        color: palette.teal[100],
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// Keep <html lang/dir> in sync with the active locale across SPA navigations...
initializeLocaleDirection();

// This will listen for flash toast data from the server...
initializeFlashToast();

// Realtime client (Reverb) — available as window.Echo for module subscriptions...
initializeEcho();
