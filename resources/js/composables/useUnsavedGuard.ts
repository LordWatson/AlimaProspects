import { router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';
import type { ComputedRef, Ref } from 'vue';

export type UnsavedGuard = {
    /** True while the member is being asked whether to leave. */
    isPrompting: Ref<boolean>;
    /** True when there is something to lose — bound by the caller's form. */
    isDirty: ComputedRef<boolean>;
    /** Leave anyway: replays the navigation that was held back. */
    discard: () => void;
    /** Stay on the form and close the prompt. */
    stay: () => void;
    /** Let one navigation through without asking (used after a successful save). */
    allowOnce: () => void;
};

/**
 * Hold back a navigation that would throw away a half-filled form.
 *
 * The two long forms in the product — add a lead, and the ideal-customer editor — are the kind
 * somebody fills in from a conversation they half remember. Losing one to a mis-clicked
 * breadcrumb is the worst thing either screen can do, and until now nothing stopped it.
 *
 * Only **leaving** is held back: a `get` to another path. A save is a `post`/`put` and goes
 * through untouched, as does a reload of the same screen — otherwise the guard would block the
 * very thing that makes the form clean. The browser's own `beforeunload` covers closing the tab,
 * where a prompt of ours cannot be shown.
 *
 * Deliberately **not** `window.confirm`: a native dialog blocks the page, cannot be translated and
 * cannot be set in the brand's type. The caller renders a `Dialog` and calls `discard`/`stay`.
 */
export function useUnsavedGuard(dirty: () => boolean): UnsavedGuard {
    const isPrompting = ref(false);
    const isDirty = computed(() => dirty());

    // The visit that was held back, replayed verbatim if the member chooses to leave.
    let held: { url: string; replace: boolean } | null = null;
    let bypass = false;

    const stopBefore = router.on('before', (event) => {
        const visit = event.detail.visit;

        if (bypass || !isDirty.value) return;
        if (visit.method !== 'get') return;

        // A prefetch is not a navigation. Inertia prefetches on hover — the user menu's settings
        // link is `prefetch` and sits in the shell on every screen — so without this, *hovering*
        // that link with a half-filled form would raise the dialog and cancel a request the
        // member never made.
        if (visit.prefetch) return;

        if (
            typeof window !== 'undefined' &&
            visit.url.pathname === window.location.pathname
        ) {
            return;
        }

        held = { url: visit.url.href, replace: visit.replace === true };
        isPrompting.value = true;

        return false;
    });

    function onBeforeUnload(event: BeforeUnloadEvent): void {
        if (!isDirty.value || bypass) return;

        // The wording is the browser's; only whether to ask is ours.
        event.preventDefault();
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    onBeforeUnmount(() => {
        stopBefore();

        if (typeof window !== 'undefined') {
            window.removeEventListener('beforeunload', onBeforeUnload);
        }
    });

    function discard(): void {
        const target = held;

        isPrompting.value = false;
        held = null;
        bypass = true;

        if (target) {
            router.visit(target.url, { replace: target.replace });
        }
    }

    function stay(): void {
        isPrompting.value = false;
        held = null;
    }

    function allowOnce(): void {
        bypass = true;
    }

    return { isPrompting, isDirty, discard, stay, allowOnce };
}
