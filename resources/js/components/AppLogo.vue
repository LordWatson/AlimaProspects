<script setup lang="ts">
import AppLogoIcon from '@/components/AppLogoIcon.vue';

/*
 * In-app signature: the snout mark on a Deep Teal tile beside the wordmark
 * (colourway 03, Teal/Cream). The wordmark is a TEMPORARY typeset placeholder —
 * the delivered logo master SVGs are still pending (brand-tokens.md §7.3). The
 * lockup is forced `dir="ltr"` so it never mirrors in RTL (never mirror the
 * logo) and carries an accessible name.
 */
</script>

<template>
    <div
        dir="ltr"
        class="flex items-center gap-1"
        role="img"
        aria-label="Alima"
    >
        <div
            class="flex aspect-square size-9 items-center justify-center rounded-md bg-teal text-cream"
            aria-hidden="true"
        >
            <AppLogoIcon class="size-6" />
        </div>
        <div class="grid flex-1 text-start">
            <span
                class="truncate text-base leading-tight font-semibold tracking-tight text-teal"
                aria-hidden="true"
            >
                alima
            </span>
        </div>
    </div>
</template>
