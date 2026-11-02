import { computed, ref, watch } from 'vue';
import { api } from '@/lib/api';
import { isSignedIn } from '@/lib/session';
import { lastForegroundNotification } from '@/lib/push';

/**
 * The unread count behind the tab bar's badge.
 *
 * Held here rather than in the Notifications screen because the badge has to be right on every
 * tab: a count that only loads when the bell is opened is a count that is only correct once the
 * member no longer needs it.
 *
 * `GET /notifications/unread` is the cheap endpoint for exactly this — a number, not a page of
 * rows. It is refreshed on three events and on no timer: when a session starts, when the member
 * reads something, and when a push arrives with the app open. No polling, because a phone that
 * wakes a radio every thirty seconds to ask whether anything happened is a phone with a flat
 * battery, and push already answers that question.
 */
const unread = ref(0);

export const unreadCount = computed(() => unread.value);

export async function refreshUnread(): Promise<void> {
    if (!isSignedIn.value) {
        unread.value = 0;

        return;
    }

    try {
        const response = await api.get<{ data: { unread: number } }>(
            'notifications/unread',
        );

        unread.value = response.data.unread;
    } catch {
        // A badge is not worth a message. Leave the last known count rather than showing zero,
        // which would say "nothing waiting" on the strength of a failed request.
    }
}

/** Called by the bell screen after it marks rows read, so the badge does not lag the list. */
export function setUnread(count: number): void {
    unread.value = Math.max(0, count);
}

watch(
    isSignedIn,
    (signedIn) => {
        if (signedIn) {
            void refreshUnread();
        } else {
            unread.value = 0;
        }
    },
    { immediate: true },
);

watch(lastForegroundNotification, (notification) => {
    if (notification !== null) {
        void refreshUnread();
    }
});
