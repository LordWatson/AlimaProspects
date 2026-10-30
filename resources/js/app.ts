import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import { initializeLocaleDirection } from '@/composables/useLocaleDirection';
import { initializeEcho } from '@/echo';
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
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
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
