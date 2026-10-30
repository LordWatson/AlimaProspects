<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';

/*
 * Reveals its content once, the first time it scrolls into view — a short rise and fade on the
 * brand easing (brand-tokens.md §2.2), nothing more. It exists so a long marketing page arrives
 * section by section instead of all at once; it is never load-bearing, and the content is in the
 * DOM and readable whether or not the animation ever runs.
 *
 * Two cases simply show the finished state, with no animation at all: `prefers-reduced-motion`,
 * and a browser without `IntersectionObserver`. Both are decided here in `setup`, before the
 * first render, so the hidden state is never painted and then undone — a reveal that flashes its
 * content and hides it again is worse than no reveal.
 */
const { delay = 0 } = defineProps<{
    /** Stagger, in milliseconds, for an item in a row of cards. */
    delay?: number;
}>();

const animates =
    typeof window !== 'undefined' &&
    typeof IntersectionObserver !== 'undefined' &&
    !window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;

const root = ref<HTMLElement | null>(null);
const shown = ref(!animates);

let observer: IntersectionObserver | null = null;

onMounted(() => {
    if (!animates || !root.value) {
        return;
    }

    observer = new IntersectionObserver(
        (entries) => {
            for (const entry of entries) {
                if (!entry.isIntersecting) {
                    continue;
                }

                shown.value = true;
                observer?.disconnect();
                observer = null;
            }
        },
        { rootMargin: '0px 0px -12% 0px', threshold: 0.05 },
    );

    observer.observe(root.value);
});

onBeforeUnmount(() => {
    observer?.disconnect();
    observer = null;
});
</script>

<template>
    <div
        ref="root"
        :class="[
            animates
                ? 'transition-[opacity,transform] duration-500 ease-brand'
                : '',
            shown ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0',
        ]"
        :style="animates ? { transitionDelay: `${delay}ms` } : undefined"
    >
        <slot />
    </div>
</template>
