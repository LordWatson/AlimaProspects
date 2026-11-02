<script setup lang="ts">
import { onMounted, watch } from 'vue';
import { IonApp, IonRouterOutlet } from '@ionic/vue';
import { App as CapacitorApp } from '@capacitor/app';
import { Capacitor } from '@capacitor/core';
import { api, isSessionExpired } from '@/lib/api';
import { setTeam, isSignedIn } from '@/lib/session';
import { signOut } from '@/lib/signOut';
import { listenForPushRegistration, onNotificationTap } from '@/lib/push';
import { router } from '@/router';
import type { ApiResource } from '@shared/api';
import type { CurrentTeam } from '@shared/types';

/*
 * The shell. It holds the router outlet and the three things that are the whole app's business
 * rather than any one screen's:
 *
 *   1. **Whose team is this.** Every response is scoped to the caller's current team, and the
 *      team carries the timezone every date on every screen is rendered in. Fetched once here
 *      rather than per screen, which is also what stops four screens asking at once on launch.
 *   2. **A tapped notification.** The payload's `url` is set by the push listener; the shell is
 *      what can act on it, because it is the only component that outlives a route change.
 *   3. **The hardware back button**, on Android, where leaving the root screen means leaving the
 *      app — and the OS default is to close it from anywhere, including mid-form.
 */
async function loadTeam(): Promise<void> {
    try {
        const response =
            await api.get<ApiResource<CurrentTeam>>('teams/current');

        setTeam(response.data);
    } catch (error) {
        // A dead token on a cold launch is the common case here — the member revoked it from the
        // web app, or it was pruned. Signing out is the honest response: the alternative is an
        // app that shows empty screens and never says why.
        if (isSessionExpired(error)) {
            await signOut();
        }
    }
}

onMounted(() => {
    listenForPushRegistration();

    if (isSignedIn.value) {
        void loadTeam();
    }

    if (Capacitor.getPlatform() === 'android') {
        void CapacitorApp.addListener('backButton', ({ canGoBack }) => {
            if (canGoBack) {
                router.back();

                return;
            }

            void CapacitorApp.exitApp();
        });
    }
});

// A sign-in mid-session: load the team the moment there is a credential to load it with.
watch(isSignedIn, (signedIn) => {
    if (signedIn) {
        void loadTeam();
    }
});

watch(onNotificationTap, (url) => {
    if (url === null) {
        return;
    }

    onNotificationTap.value = null;
    void router.push(url);
});
</script>

<template>
    <IonApp>
        <IonRouterOutlet />
    </IonApp>
</template>
