<script setup lang="ts">
/*
 * The Alima primary signature — the master horizontal lock-up (panda mark +
 * "alima" wordmark), used verbatim as delivered artwork (brand guidelines §2:
 * never redraw the logo). Teal/Cream colourways only; `tone="dark"` picks the
 * Cream lock-up for dark surfaces. Forced dir="ltr" so it never mirrors in RTL.
 */
withDefaults(
    defineProps<{
        tone?: 'light' | 'dark';
    }>(),
    { tone: 'light' },
);
</script>

<template>
    <img
        dir="ltr"
        :src="
            tone === 'dark'
                ? '/brand/alima_logo_cream.svg'
                : '/brand/alima_logo_teal.svg'
        "
        alt="Alima"
        class="h-8 w-auto"
    />
</template>
