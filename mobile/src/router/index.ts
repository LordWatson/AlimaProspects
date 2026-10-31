import { createRouter, createWebHistory } from '@ionic/vue-router';
import type { RouteRecordRaw } from 'vue-router';
import { isSignedIn, sessionReady } from '@/lib/session';

/**
 * The app's routes.
 *
 * `createWebHistory` rather than hash history: a Capacitor WebView serves the bundle from the
 * root of its own origin, so paths work, and they let a tapped push notification's `url` be used
 * as-is — the payload carries the web app's path for the record, and the four screens below are
 * named to match it.
 *
 * The four tabs are the app's scope, deliberately smaller than the web app's navigation. This is
 * a field companion, not a second copy of settings and billing: what a member needs between
 * appointments is today's numbers, their leads, what they are allowed to say, and the bell.
 */
const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/tabs/dashboard' },

    {
        path: '/sign-in',
        name: 'sign-in',
        component: () => import('@/views/SignInView.vue'),
        meta: { guest: true },
    },

    {
        path: '/tabs',
        component: () => import('@/views/TabsShell.vue'),
        children: [
            { path: '', redirect: '/tabs/dashboard' },
            {
                path: 'dashboard',
                name: 'dashboard',
                component: () => import('@/views/DashboardView.vue'),
            },
            {
                path: 'leads',
                name: 'leads',
                component: () => import('@/views/LeadsView.vue'),
            },
            {
                path: 'notifications',
                name: 'notifications',
                component: () => import('@/views/NotificationsView.vue'),
            },
            {
                path: 'settings',
                name: 'settings',
                component: () => import('@/views/SettingsView.vue'),
            },
        ],
    },

    // Outside the tabs, so they push over the whole shell with a back button — the lead is a
    // place you go into and come back from, not a fifth tab.
    {
        path: '/leads/:id',
        name: 'lead',
        component: () => import('@/views/LeadDetailView.vue'),
    },
    {
        path: '/leads/:id/draft',
        name: 'lead-draft',
        component: () => import('@/views/LeadDraftView.vue'),
    },
    {
        path: '/leads/:id/log',
        name: 'lead-log',
        component: () => import('@/views/LeadLogView.vue'),
    },

    // Anything else — including a push payload pointing at a web-only screen — lands on the bell
    // rather than on a blank page.
    { path: '/:pathMatch(.*)*', redirect: '/tabs/notifications' },
];

export const router = createRouter({
    history: createWebHistory(),
    routes,
});

/**
 * One gate, both ways: a signed-out member cannot reach a screen, and a signed-in one cannot
 * reach the sign-in form. It waits on {@link sessionReady} first — see there for why.
 */
router.beforeEach(async (to) => {
    await sessionReady();

    if (to.meta.guest === true) {
        return isSignedIn.value ? { name: 'dashboard' } : true;
    }

    if (!isSignedIn.value) {
        return { name: 'sign-in' };
    }

    return true;
});
