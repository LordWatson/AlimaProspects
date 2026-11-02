<script setup lang="ts">
import {
    IonBadge,
    IonIcon,
    IonLabel,
    IonPage,
    IonRouterOutlet,
    IonTabBar,
    IonTabButton,
    IonTabs,
} from '@ionic/vue';
import {
    briefcaseOutline,
    notificationsOutline,
    personCircleOutline,
    statsChartOutline,
} from 'ionicons/icons';
import { t } from '@/lib/i18n';
import { unreadCount } from '@/lib/bell';

/*
 * The app's four tabs, and the one piece of chrome that persists across them.
 *
 * Four, not seven: this is a field companion, and the web app keeps settings, billing, discovery,
 * ICPs and compliance. What is here is what a member needs with a phone in one hand.
 *
 * The unread count lives in `lib/bell.ts` rather than in the Notifications screen, because the
 * badge has to be right on every tab — a count that only loads when you open the bell is a badge
 * that is only correct once you no longer need it.
 */
</script>

<template>
    <IonPage>
        <IonTabs>
            <IonRouterOutlet />

            <IonTabBar slot="bottom">
                <IonTabButton tab="dashboard" href="/tabs/dashboard">
                    <IonIcon :icon="statsChartOutline" aria-hidden="true" />
                    <IonLabel>{{ t('Dashboard') }}</IonLabel>
                </IonTabButton>

                <IonTabButton tab="leads" href="/tabs/leads">
                    <IonIcon :icon="briefcaseOutline" aria-hidden="true" />
                    <IonLabel>{{ t('Leads') }}</IonLabel>
                </IonTabButton>

                <IonTabButton
                    tab="notifications"
                    href="/tabs/notifications"
                    :aria-label="
                        unreadCount > 0
                            ? t('Notifications') +
                              ', ' +
                              t(':count unread', { count: unreadCount })
                            : t('Notifications')
                    "
                >
                    <IonIcon :icon="notificationsOutline" aria-hidden="true" />
                    <IonLabel>{{ t('Notifications') }}</IonLabel>
                    <!--
                        `primary`, not `danger`: an unread notification is a count, not a
                        failure, and the status ramp's red is reserved for things that went
                        wrong (CLAUDE.md §2a).

                        The count goes into the **tab's** accessible name rather than onto the
                        badge, because an `aria-label` on a bare span is routinely ignored.
                        The badge itself is then decoration.
                    -->
                    <IonBadge
                        v-if="unreadCount > 0"
                        color="primary"
                        aria-hidden="true"
                    >
                        {{ unreadCount > 99 ? '99+' : unreadCount }}
                    </IonBadge>
                </IonTabButton>

                <IonTabButton tab="settings" href="/tabs/settings">
                    <IonIcon :icon="personCircleOutline" aria-hidden="true" />
                    <IonLabel>{{ t('Settings') }}</IonLabel>
                </IonTabButton>
            </IonTabBar>
        </IonTabs>
    </IonPage>
</template>
