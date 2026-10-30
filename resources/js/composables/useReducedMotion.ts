import { onBeforeUnmount, onMounted, ref } from 'vue';
import type { Ref } from 'vue';

const QUERY = '(prefers-reduced-motion: reduce)';

/**
 * Tracks the viewer's `prefers-reduced-motion` setting.
 *
 * Brand motion (brand-tokens.md §2.2) is a 150–250ms ease, not spectacle — but the marketing
 * pages do run things the viewer did not ask for: a showcase that advances itself, sections that
 * animate in. Those are exactly what the setting exists to stop, so anything that moves on its
 * own reads this and simply presents its finished state instead.
 *
 * Starts `false` so server-rendered markup and the first client frame agree; the real value
 * arrives on mount, which is also the only place `window` exists.
 */
export function useReducedMotion(): Ref<boolean> {
    const reduced = ref(false);

    let media: MediaQueryList | null = null;

    function sync(event: MediaQueryListEvent | MediaQueryList): void {
        reduced.value = event.matches;
    }

    onMounted(() => {
        if (typeof window === 'undefined' || !window.matchMedia) {
            return;
        }

        media = window.matchMedia(QUERY);
        sync(media);
        media.addEventListener('change', sync);
    });

    onBeforeUnmount(() => {
        media?.removeEventListener('change', sync);
        media = null;
    });

    return reduced;
}
