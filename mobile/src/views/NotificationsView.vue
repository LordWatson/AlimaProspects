<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { useRouter } from 'vue-router';
import {
    IonButton,
    IonButtons,
    IonContent,
    IonHeader,
    IonItem,
    IonLabel,
    IonList,
    IonNote,
    IonPage,
    IonRefresher,
    IonRefresherContent,
    IonTitle,
    IonToolbar,
    onIonViewWillEnter,
} from '@ionic/vue';
import type { ApiPage } from '@shared/api';
import type { AppNotification } from '@shared/types';
import { api } from '@/lib/api';
import { t } from '@/lib/i18n';
import { formatRelative } from '@/lib/format';
import { refreshUnread, setUnread } from '@/lib/bell';
import { notifyFailure } from '@/lib/feedback';
import StateMessage from '@/components/StateMessage.vue';

/*
 * The bell.
 *
 * Every row's `title` and `body` are **translation keys** with `params` beside them, never formed
 * prose — the row may have been written days ago and the member may since have switched the app to
 * Arabic, so the sentence is composed here (`shared/types/notifications.ts`). That is also why
 * the payload keeps keys rather than a sentence: `NotificationDispatcher` is required to store
 * keys + params, not words.
 *
 * Tapping a row marks it read and follows its `url`. The `url` is the **web app's** path for the
 * record, which the app's own routes mirror for the screens it has; the router's catch-all sends
 * anything else back here rather than to a blank page, so a notification about a screen the phone
 * does not have is a dead end rather than a crash.
 */
const router = useRouter();

const items = ref<AppNotification[]>([]);
const state = ref<'loading' | 'ready' | 'error'>('loading');
const busy = ref(false);

async function load(): Promise<void> {
    if (items.value.length === 0) {
        state.value = 'loading';
    }

    try {
        const response = await api.get<ApiPage<AppNotification>>(
            'notifications',
            {
                per_page: 30,
            },
        );

        items.value = response.data;
        state.value = 'ready';
        await refreshUnread();
    } catch {
        state.value = 'error';
    }
}

async function open(item: AppNotification): Promise<void> {
    // Marked read first, and optimistically: the member has seen it, and a failed write should
    // not stop them opening the thing it was about.
    if (item.read_at === null) {
        item.read_at = new Date().toISOString();

        try {
            await api.post(`notifications/${item.id}/read`);
            await refreshUnread();
        } catch {
            // Left as read on screen. The next load will correct it if the write really failed.
        }
    }

    if (item.url !== null) {
        await router.push(item.url);
    }
}

async function markAllRead(): Promise<void> {
    if (busy.value) {
        return;
    }

    busy.value = true;

    try {
        await api.post('notifications/read-all');

        const now = new Date().toISOString();

        items.value = items.value.map((item) => ({
            ...item,
            read_at: item.read_at ?? now,
        }));

        setUnread(0);
    } catch (error) {
        await notifyFailure(error, t('We could not mark those read.'));
    } finally {
        busy.value = false;
    }
}

async function refresh(event: CustomEvent): Promise<void> {
    await load();
    (event.target as HTMLIonRefresherElement | null)?.complete();
}

onMounted(load);

onIonViewWillEnter(() => {
    if (items.value.length > 0) {
        void load();
    }
});
</script>

<template>
    <IonPage>
        <IonHeader>
            <IonToolbar>
                <IonTitle>{{ t('Notifications') }}</IonTitle>
                <IonButtons slot="end">
                    <IonButton
                        fill="clear"
                        size="small"
                        color="secondary"
                        :disabled="
                            busy || items.every((item) => item.read_at !== null)
                        "
                        @click="markAllRead"
                    >
                        {{ t('Mark all read') }}
                    </IonButton>
                </IonButtons>
            </IonToolbar>
        </IonHeader>

        <IonContent :fullscreen="true">
            <IonRefresher slot="fixed" @ion-refresh="refresh">
                <IonRefresherContent />
            </IonRefresher>

            <StateMessage v-if="state === 'loading'" state="loading" />

            <StateMessage
                v-else-if="state === 'error'"
                state="error"
                :message="t('We could not load your notifications.')"
                @retry="load"
            />

            <StateMessage
                v-else-if="items.length === 0"
                state="empty"
                :message="t('Nothing waiting on you')"
                :hint="
                    t(
                        'Nothing new. Alima will tell you when something happens.',
                    )
                "
            />

            <IonList v-else lines="full">
                <IonItem
                    v-for="item in items"
                    :key="item.id"
                    button
                    :detail="item.url !== null"
                    @click="open(item)"
                >
                    <!-- Unread is a dot *and* a bolder title: a state carried only by a coloured
                         dot is a state some people cannot see. -->
                    <div
                        v-if="item.read_at === null"
                        slot="start"
                        class="bell__dot"
                        :aria-label="t('Unread')"
                        role="img"
                    ></div>

                    <IonLabel>
                        <h2
                            :class="{
                                'bell__title--unread': item.read_at === null,
                            }"
                        >
                            {{ t(item.title, item.params) }}
                        </h2>
                        <p class="bell__body">
                            {{ t(item.body, item.params) }}
                        </p>
                    </IonLabel>

                    <IonNote slot="end" class="bell__when">
                        {{ formatRelative(item.created_at) }}
                    </IonNote>
                </IonItem>
            </IonList>
        </IonContent>
    </IonPage>
</template>

<style scoped>
.bell__dot {
    width: 8px;
    height: 8px;
    border-radius: 9999px;
    background: var(--alima-teal);
    margin-inline-end: 12px;
    flex: none;
}

.alima-dark .bell__dot {
    background: var(--alima-teal-60);
}

.bell__title--unread {
    font-weight: 600;
}

.bell__body {
    font-size: 0.8125rem;
    line-height: 1.5;
    color: var(--alima-text-muted);
    white-space: normal;
}

.bell__when {
    font-size: 0.6875rem;
    align-self: flex-start;
    padding-top: 4px;
}
</style>
