import { computed, ref } from 'vue';
import type { TokenAbility } from '@shared/api';
import type { CurrentTeam, User } from '@shared/types';
import { storage, storageKeys } from '@/lib/storage';

/**
 * Who is signed in on this handset.
 *
 * The token and the user are held here and nowhere else, so there is one answer to "are we signed
 * in" and one place a sign-out has to reach. `lib/api.ts` resolves the bearer through
 * {@link currentToken} at call time rather than being handed it once, which is what lets a token
 * change — a sign-in, a sign-out, an expiry — take effect on the very next request without
 * rebuilding the client.
 */
const token = ref<string | null>(null);
const user = ref<User | null>(null);
const team = ref<CurrentTeam | null>(null);

/** True once the stored credential has been read, so the router can stop guessing. */
const restored = ref(false);

export const isSignedIn = computed(() => token.value !== null);
export const currentUser = computed(() => user.value);
export const currentTeam = computed(() => team.value);
export const sessionRestored = computed(() => restored.value);

export function currentToken(): string | null {
    return token.value;
}

/**
 * The scopes the app asks for at sign-in — and no more.
 *
 * This list is the app's own declaration of what it does, and it is deliberately not `*`. A
 * member who looks at Settings → API tokens on the web sees "Alima on iPhone" with these eight
 * beside it, which is an honest answer to "what can my phone do with my account". It is also the
 * list `MobileJourneyTest` walks the whole journey over, so an endpoint the app reaches without
 * a scope here fails in CI rather than on a handset.
 *
 * Widening it is a visible change. Narrowing it is free.
 */
export const requestedAbilities: TokenAbility[] = [
    'team:read',
    'reports:read',
    'leads:read',
    'leads:write',
    'outreach:read',
    'outreach:write',
    'notifications:read',
    'notifications:write',
];

let restoring: Promise<void> | null = null;

/**
 * Resolves once the stored credential has been read, however many callers ask.
 *
 * The router guard needs this: on a cold launch the token comes back from native storage
 * asynchronously, and a guard that answered before it arrived would bounce a signed-in member to
 * the sign-in screen for a frame — the flash of a login form on an app you are already inside.
 */
export function sessionReady(): Promise<void> {
    restoring ??= restoreSession();

    return restoring;
}

export async function restoreSession(): Promise<void> {
    const [storedToken, storedUser] = await Promise.all([
        storage.get(storageKeys.token),
        storage.get(storageKeys.user),
    ]);

    if (storedToken !== null) {
        token.value = storedToken;
    }

    if (storedUser !== null) {
        // A cached user so the shell can paint a name before `GET /user` answers. Treated as a
        // hint, not as truth: anything stale is replaced by the first live response.
        try {
            user.value = JSON.parse(storedUser) as User;
        } catch {
            await storage.remove(storageKeys.user);
        }
    }

    restored.value = true;
}

export async function startSession(
    newToken: string,
    newUser: User,
): Promise<void> {
    token.value = newToken;
    user.value = newUser;

    await Promise.all([
        storage.set(storageKeys.token, newToken),
        storage.set(storageKeys.user, JSON.stringify(newUser)),
    ]);
}

export function setUser(next: User): void {
    user.value = next;
    void storage.set(storageKeys.user, JSON.stringify(next));
}

export function setTeam(next: CurrentTeam | null): void {
    team.value = next;
}

/**
 * Forget the credential on this device.
 *
 * In-memory state is cleared first and the storage writes awaited second, so a failure to write
 * cannot leave the app showing a session it has already discarded. Revoking the token server-side
 * and deregistering the push device are the caller's job (`useSignOut`), because both can fail —
 * a handset out of signal still has to be able to sign out of itself.
 */
export async function endSession(): Promise<void> {
    token.value = null;
    user.value = null;
    team.value = null;

    await Promise.all([
        storage.remove(storageKeys.token),
        storage.remove(storageKeys.user),
    ]);
}

export type { User, CurrentTeam };
