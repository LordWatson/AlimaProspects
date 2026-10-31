import { api } from '@/lib/api';
import { endSession } from '@/lib/session';
import { forgetDevice } from '@/lib/push';
import { router } from '@/router';

/**
 * Signing out, in the order that survives a bad connection.
 *
 * Both server calls are best-effort and both come first, because once the local token is gone
 * there is no credential left to make them with. Then the session is cleared and the app
 * navigates — and that part cannot fail, which is the point: a member who hands their phone to
 * someone else must be signed out whether or not Alima was reachable at the time.
 *
 * `DELETE /auth/token` retires the credential **itself**, which is why that route carries no
 * scope: a token that could not stand itself down when a handset is wiped is one nobody can
 * clean up (`docs/api/README.md` §3).
 */
export async function signOut(): Promise<void> {
    await forgetDevice();

    try {
        await api.delete('auth/token');
    } catch {
        // Offline, or the token was already revoked from the web app's settings screen. Either
        // way the local half below is what makes this device signed out.
    }

    await endSession();

    // `replace`, so the back gesture cannot return to a screen whose data is gone.
    await router.replace({ name: 'sign-in' });
}
