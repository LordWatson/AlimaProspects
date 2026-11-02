import { computed, ref } from 'vue';
import { Capacitor } from '@capacitor/core';
import { PushNotifications } from '@capacitor/push-notifications';
import type { PushNotificationSchema } from '@capacitor/push-notifications';
import { api } from '@/lib/api';
import { storage, storageKeys } from '@/lib/storage';

/**
 * Push, wired to the Notifications module (Phase 12).
 *
 * The server end was already built: `POST /push-devices` takes a provider registration token and
 * a platform, is idempotent by design (`201` first time, `200` on the re-registration a phone
 * does at every launch), and `NotificationDispatcher` decides whether a given member even wants
 * push for a given type. Nothing here second-guesses any of that — this file's whole job is to
 * get the handset's token to that endpoint and to take it away again at sign-out.
 *
 * **A phone belongs to a person, not a team.** The endpoint stamps no `team_id`, so a member who
 * switches teams does not re-register and does not need to.
 *
 * Three things are deliberately *not* done here:
 *   - No permission prompt at launch. Asking before the member has seen anything worth being
 *     told about is how an app gets a permanent "no" on the first screen; `enable()` is called
 *     from Settings, where there is a sentence next to it explaining what it is for.
 *   - No badge count. Nothing in the app clears one, and a number on the icon that never goes
 *     away is worse than no number at all (see `capacitor.config.ts`).
 *   - No local notification mirror while the app is open. A foreground push surfaces in-app
 *     through the bell, which is already live.
 */
export type PushStatus =
    | 'unsupported'
    | 'unknown'
    | 'denied'
    | 'granted'
    | 'registered';

const status = ref<PushStatus>(
    Capacitor.isNativePlatform() ? 'unknown' : 'unsupported',
);

const lastError = ref<string | null>(null);

export const pushStatus = computed(() => status.value);
export const pushError = computed(() => lastError.value);

/** True on a handset; false in a desktop browser, where there is no FCM/APNs token to get. */
export const pushSupported = Capacitor.isNativePlatform();

/**
 * The handler that turns a provider registration into a row on the account. Registered once at
 * boot so a token the OS rotates — which it does, without being asked — is sent up whenever it
 * arrives, not only when a member happens to be on the Settings screen.
 */
export function listenForPushRegistration(): void {
    if (!pushSupported) {
        return;
    }

    void PushNotifications.addListener('registration', (token) => {
        void registerDevice(token.value);
    });

    void PushNotifications.addListener('registrationError', (error) => {
        status.value = 'denied';
        lastError.value = String(error.error ?? '');
    });

    // A tap on a notification should land on the thing it is about. The payload's `url` is the
    // web app's path for the record (`AppNotification.url`), and the app's routes mirror it for
    // the handful of screens it has — anything else falls back to the bell, which is a worse
    // landing but never a dead one.
    void PushNotifications.addListener(
        'pushNotificationActionPerformed',
        (action) => {
            const url = (
                action.notification.data as { url?: string } | undefined
            )?.url;

            onNotificationTap.value =
                typeof url === 'string' ? url : '/tabs/notifications';
        },
    );

    void PushNotifications.addListener(
        'pushNotificationReceived',
        (notification: PushNotificationSchema) => {
            lastForegroundNotification.value = notification;
        },
    );
}

/** Set when a notification is tapped; the shell reads and clears it. */
export const onNotificationTap = ref<string | null>(null);

/** Set when one arrives with the app open, so the bell can refresh itself. */
export const lastForegroundNotification = ref<PushNotificationSchema | null>(
    null,
);

/**
 * Ask for permission and register. Called from Settings, on a deliberate tap.
 *
 * Returns the resulting status rather than throwing: "the member said no" is an answer, not a
 * failure, and a screen that has to catch an exception to render a toggle gets it wrong.
 */
export async function enablePush(): Promise<PushStatus> {
    if (!pushSupported) {
        return 'unsupported';
    }

    lastError.value = null;

    try {
        let permission = await PushNotifications.checkPermissions();

        if (
            permission.receive === 'prompt' ||
            permission.receive === 'prompt-with-rationale'
        ) {
            permission = await PushNotifications.requestPermissions();
        }

        if (permission.receive !== 'granted') {
            status.value = 'denied';

            return status.value;
        }

        status.value = 'granted';

        // Hands the token to the OS, which answers asynchronously on the `registration` listener
        // above — which is why `registerDevice` is not called from here.
        await PushNotifications.register();

        return status.value;
    } catch (error) {
        status.value = 'denied';
        lastError.value =
            error instanceof Error ? error.message : String(error);

        return status.value;
    }
}

/**
 * Take this handset off the account.
 *
 * Best-effort on purpose, and the reason it returns nothing: a member signing out on a phone with
 * no signal must still get signed out. A device row left behind is swept by
 * `notifications:prune` after `retention.device_days`, and sends to a token whose app has been
 * removed come back `UNREGISTERED` and are deleted by `PushResult::$invalidTokens` — so the
 * failure mode is a stale row, not a notification going to the wrong person.
 */
export async function forgetDevice(): Promise<void> {
    const token = await storage.get(storageKeys.pushToken);

    if (token === null) {
        return;
    }

    try {
        await api.send({
            method: 'DELETE',
            path: 'push-devices',
            body: { token },
        });
    } catch {
        // See above.
    }

    await storage.remove(storageKeys.pushToken);
    status.value = 'granted';
}

async function registerDevice(token: string): Promise<void> {
    try {
        await api.post('push-devices', {
            token,
            platform: Capacitor.getPlatform() === 'ios' ? 'ios' : 'android',
        });

        await storage.set(storageKeys.pushToken, token);
        status.value = 'registered';
    } catch (error) {
        lastError.value =
            error instanceof Error ? error.message : String(error);
    }
}
