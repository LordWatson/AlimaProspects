import { ApiClient, ApiError } from '@shared/api';
import { apiBaseUrl } from '@/lib/config';
import { currentLocale } from '@/lib/i18n';
import { currentToken } from '@/lib/session';

/**
 * The app's single `/api/v1` client.
 *
 * Built from `@shared/api` — the same typed client the web app's own types come from, reached
 * through a path alias rather than copied (Phase 17 put it there for exactly this). It already
 * knows the four things this app would otherwise get wrong one screen at a time: send the bearer
 * token, pin `X-Api-Version`, parse the shared error envelope instead of guessing, and surface a
 * `429` with something to wait on.
 *
 * Everything is resolved per call rather than captured at construction: the base URL (which a
 * development build can change from the sign-in screen), the token (which changes at sign-in and
 * sign-out), and the locale (which decides `Accept-Language`, and therefore the language of every
 * server-written sentence in an error).
 */
export const api = new ApiClient({
    get baseUrl(): string {
        return apiBaseUrl();
    },
    token: () => currentToken(),
    locale: () => currentLocale.value,
});

export { ApiError };

/**
 * `true` when the failure means "the credential is gone" rather than "that request was wrong".
 *
 * Worth a helper because the right response is drastic — drop the session and send the member
 * back to sign-in — and misreading any other failure as this one signs people out for a typo.
 * Note what is deliberately **not** here: `insufficient_token_ability` is a genuine 403 but
 * signing in again cannot fix it, since the token has to be reissued with a wider scope. The
 * shared `ApiError` carries `requiredAbilities` for that case.
 */
export function isSessionExpired(error: unknown): boolean {
    return error instanceof ApiError && error.isUnauthenticated;
}

/** A thrown value as a sentence to show someone, whatever it turned out to be. */
export function errorMessage(error: unknown, fallback: string): string {
    if (error instanceof ApiError) {
        return error.message;
    }

    // A `fetch` that never reached the server throws a TypeError with a message written for a
    // developer ("Failed to fetch"), which is not a sentence to put in front of a member.
    return fallback;
}
