<script setup lang="ts">
import { computed, ref } from 'vue';
import {
    IonButton,
    IonContent,
    IonHeader,
    IonItem,
    IonLabel,
    IonList,
    IonListHeader,
    IonNote,
    IonPage,
    IonSegment,
    IonSegmentButton,
    IonTitle,
    IonToggle,
    IonToolbar,
    alertController,
} from '@ionic/vue';
import type { SupportedLocale } from '@shared/types';
import { currentAppearance, setAppearance } from '@/lib/appearance';
import type { Appearance } from '@/lib/appearance';
import { currentLocale, locales, setLocale, t } from '@/lib/i18n';
import { currentTeam, currentUser } from '@/lib/session';
import {
    enablePush,
    forgetDevice,
    pushError,
    pushStatus,
    pushSupported,
} from '@/lib/push';
import { signOut } from '@/lib/signOut';
import { apiUrlRef, isDev } from '@/lib/config';
import { initials } from '@/lib/format';
import BrandMark from '@/components/BrandMark.vue';

/*
 * Settings — the small set that belongs on a handset, and no more.
 *
 * This app deliberately keeps none of the web app's settings: profile, security, team members,
 * API tokens, notification preferences, billing and compliance all stay there. What is here is
 * what is about **this device**: its language, its theme, whether it receives push, and signing
 * out of it.
 *
 * Notification *preferences* — which types reach which channels — are per team and per member,
 * are set on the web, and are the `NotificationDispatcher`'s to honour. Duplicating that picker
 * here would be a second place for the same decision to be made differently.
 */
const appearances: Appearance[] = ['light', 'dark', 'system'];

const appearanceLabels: Record<Appearance, string> = {
    light: 'Light',
    dark: 'Dark',
    system: 'System',
};

const busy = ref(false);

const pushOn = computed(() => pushStatus.value === 'registered');

const pushHint = computed(() => {
    if (!pushSupported) {
        return t(
            'Push notifications only work in the installed app, not in a browser.',
        );
    }

    switch (pushStatus.value) {
        case 'denied':
            return t(
                'Notifications are switched off for Alima in your phone’s settings. Turn them on there, then come back.',
            );
        case 'registered':
            return t(
                'This phone will be told when something needs you. Which things, and how often, is set on the Alima website.',
            );
        default:
            return t(
                'Get told about strong new matches, tasks that are due and leads assigned to you.',
            );
    }
});

async function togglePush(on: boolean): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        if (on) {
            await enablePush();
        } else {
            await forgetDevice();
        }
    } finally {
        busy.value = false;
    }
}

async function confirmSignOut(): Promise<void> {
    const alert = await alertController.create({
        header: t('Log out'),
        message: t(
            'You will need your password to sign in again on this phone.',
        ),
        buttons: [
            { text: t('Cancel'), role: 'cancel' },
            {
                text: t('Log out'),
                role: 'destructive',
                handler: () => {
                    void signOut();
                },
            },
        ],
    });

    await alert.present();
}
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonTitle>{{ t('Settings') }}</IonTitle>
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <div class="alima-page">
                <header class="who">
                    <div class="who__avatar" aria-hidden="true">
                        {{ initials(currentUser?.name ?? '?') }}
                    </div>
                    <div>
                        <p class="alima-h2">{{ currentUser?.name ?? '—' }}</p>
                        <p class="alima-muted alima-ltr">
                            {{ currentUser?.email ?? '' }}
                        </p>
                        <p v-if="currentTeam" class="alima-eyebrow who__team">
                            {{ currentTeam.name }}
                        </p>
                    </div>
                </header>
            </div>

            <IonList>
                <IonListHeader>
                    <IonLabel>{{ t('Language') }}</IonLabel>
                </IonListHeader>
                <IonItem lines="none">
                    <IonSegment
                        :value="currentLocale"
                        @ion-change="
                            setLocale($event.detail.value as SupportedLocale)
                        "
                    >
                        <IonSegmentButton
                            v-for="option in locales"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </IonSegmentButton>
                    </IonSegment>
                </IonItem>

                <IonListHeader>
                    <IonLabel>{{ t('Appearance') }}</IonLabel>
                </IonListHeader>
                <IonItem lines="none">
                    <IonSegment
                        :value="currentAppearance"
                        @ion-change="
                            setAppearance($event.detail.value as Appearance)
                        "
                    >
                        <IonSegmentButton
                            v-for="option in appearances"
                            :key="option"
                            :value="option"
                        >
                            {{ t(appearanceLabels[option]) }}
                        </IonSegmentButton>
                    </IonSegment>
                </IonItem>

                <IonListHeader>
                    <IonLabel>{{ t('Push') }}</IonLabel>
                </IonListHeader>
                <IonItem>
                    <IonToggle
                        :checked="pushOn"
                        :disabled="
                            !pushSupported || busy || pushStatus === 'denied'
                        "
                        @ion-change="togglePush($event.detail.checked)"
                    >
                        {{ t('Notifications') }}
                    </IonToggle>
                </IonItem>
                <IonItem lines="none">
                    <IonNote class="hint">{{ pushHint }}</IonNote>
                </IonItem>
                <IonItem v-if="pushError" lines="none">
                    <IonNote color="danger" class="hint alima-ltr">{{
                        pushError
                    }}</IonNote>
                </IonItem>
            </IonList>

            <div class="alima-page">
                <IonButton
                    expand="block"
                    fill="outline"
                    color="danger"
                    @click="confirmSignOut"
                >
                    {{ t('Log out') }}
                </IonButton>

                <footer class="foot">
                    <BrandMark variant="mark" class="foot__mark" />
                    <p class="alima-muted">
                        {{ t('Marketing brighter together.') }}
                    </p>
                    <p v-if="isDev" class="foot__dev alima-ltr">
                        {{ apiUrlRef }}
                    </p>
                </footer>
            </div>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.who {
    display: flex;
    align-items: center;
    gap: 14px;
}

/* Teal tile, cream initials — the brand's own structural colour, at 7.5:1. */
.who__avatar {
    width: 52px;
    height: 52px;
    border-radius: 9999px;
    background: var(--alima-teal);
    color: var(--alima-cream);
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: 600;
    font-size: 1.125rem;
    flex: none;
}

.alima-dark .who__avatar {
    background: var(--alima-teal-60);
    color: var(--alima-ink);
}

.who__team {
    margin-top: 4px;
}

.hint {
    font-size: 0.8125rem;
    line-height: 1.5;
    white-space: normal;
    padding-block: 4px;
}

.foot {
    margin-top: 40px;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 8px;
    text-align: center;
}

/* Well above the mark's 24px minimum (brand-tokens.md §4). Base Teal, not Teal 60: §1.3 says
   never tint the logo, and the 60 step is 2.9:1 on cream — under the floor for every approved
   colourway. Cream on Ink in dark mode. */
.foot__mark {
    width: 40px;
    color: var(--alima-teal);
}

.alima-dark .foot__mark {
    color: var(--alima-cream);
}

.foot__dev {
    font-family: var(--alima-font-mono);
    font-size: 0.6875rem;
    color: var(--alima-text-muted);
}
</style>
