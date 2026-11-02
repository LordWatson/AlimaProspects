import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, readonly, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * `true` while an Inertia visit to the **same** screen is in flight.
 *
 * Every list in the product filters, sorts and pages on the server: the URL is the truth about
 * what is on screen (`leads/Index.vue`), so a filter change is a round trip. Without this, the
 * table sits there looking finished while the server is still thinking — and on a slow connection
 * a member taps the same chip three times because nothing said the first tap landed.
 *
 * Scoped to the current pathname by default, so the page's own refresh is what dims the table and
 * a real navigation away from it is not: leaving the screen should feel like leaving, not like
 * waiting.
 */
export function useNavigating(samePathOnly = true): Readonly<Ref<boolean>> {
    const navigating = ref(false);

    const stopStart = router.on('start', (event) => {
        const visit = event.detail.visit;

        // A POST is a save, and a save has its own affordance (the button says "Adding…"). Only a
        // GET re-reads the list, which is the thing this is about.
        if (visit.method !== 'get') return;

        // A prefetch is speculative — Inertia fires one on hover — and dimming the rows because
        // somebody's pointer passed over a link would be a lie about what is happening.
        if (visit.prefetch) return;

        if (
            samePathOnly &&
            typeof window !== 'undefined' &&
            visit.url.pathname !== window.location.pathname
        ) {
            return;
        }

        navigating.value = true;
    });

    const stopFinish = router.on('finish', () => {
        navigating.value = false;
    });

    onBeforeUnmount(() => {
        stopStart();
        stopFinish();
    });

    return readonly(navigating);
}
