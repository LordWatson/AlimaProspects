import { toastController } from '@ionic/vue';
import { Haptics, ImpactStyle, NotificationType } from '@capacitor/haptics';
import { Capacitor } from '@capacitor/core';
import { ApiError } from '@/lib/api';
import { t } from '@/lib/i18n';

/**
 * Telling the member what just happened.
 *
 * One function per outcome rather than a generic `toast(message, colour)`, because the colour is
 * not the caller's decision to make: success is the status ramp's green, failure its red, and
 * Signal Orange is decorative and never a status (CLAUDE.md §2a). Routing both through here is
 * what keeps that true as screens get added.
 *
 * A toast at the **top** on purpose. The important controls on these screens — the stage picker,
 * the compose button, the tab bar — are all at the bottom, under the thumb, and a toast that
 * covers the button someone just pressed is a toast that hides the next thing they need.
 */
export async function notifySuccess(message: string): Promise<void> {
    await haptic('success');
    await present(message, 'success', 2200);
}

export async function notifyError(message: string): Promise<void> {
    await haptic('error');
    await present(message, 'danger', 4000);
}

/**
 * Report a thrown value, choosing the sentence from the error envelope's **code** rather than
 * from its message (`docs/api/README.md` §4: branch on the code, never on the prose).
 *
 * Three codes mean something other than "show the message":
 *   - `too_many_requests` — the app asked too often. The member did nothing wrong and should be
 *     told how long, not told off.
 *   - `insufficient_token_ability` — the token is genuine and too narrow. Signing in again will
 *     not help; the credential has to be reissued, which is a sentence only this app can write
 *     because only this app knows it asked for a short list of scopes.
 *   - anything with no envelope at all — the request never reached Alima. "Check your
 *     connection" is the useful sentence; the server's would be a lie.
 */
export async function notifyFailure(
    error: unknown,
    fallback: string,
): Promise<void> {
    if (!(error instanceof ApiError)) {
        await notifyError(
            t(
                'Alima could not be reached. Check your connection and try again.',
            ),
        );

        return;
    }

    if (error.isThrottled) {
        const seconds = error.retryAfter;

        await notifyError(
            seconds === null
                ? t('Too many requests — try again shortly.')
                : t('Too many requests — try again in :seconds seconds.', {
                      seconds,
                  }),
        );

        return;
    }

    if (error.isMissingAbility) {
        await notifyError(
            t(
                'This app is not allowed to do that. Sign out and in again to refresh its permissions.',
            ),
        );

        return;
    }

    // A validation failure names the fields; the envelope's own message is the summary sentence,
    // and the screen shows the field errors inline where the person typed them.
    await notifyError(error.message === '' ? fallback : error.message);
}

async function present(
    message: string,
    color: 'success' | 'danger',
    duration: number,
): Promise<void> {
    const toast = await toastController.create({
        message,
        duration,
        color,
        position: 'top',
        buttons: [{ text: t('Close'), role: 'cancel' }],
    });

    await toast.present();
}

/**
 * A tap the hand can feel. Silently skipped off-device, where there is nothing to vibrate, and
 * never load-bearing — the toast carries the message, this only confirms it arrived.
 */
async function haptic(kind: 'success' | 'error'): Promise<void> {
    if (!Capacitor.isNativePlatform()) {
        return;
    }

    try {
        await (kind === 'success'
            ? Haptics.impact({ style: ImpactStyle.Light })
            : Haptics.notification({ type: NotificationType.Error }));
    } catch {
        // A handset with haptics switched off, or none at all.
    }
}
