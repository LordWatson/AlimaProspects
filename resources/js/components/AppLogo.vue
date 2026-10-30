<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import BrandArtwork from '@/components/BrandArtwork.vue';
import { logoArtwork } from '@/components/brand/artwork';

/*
 * The Alima primary signature — the master horizontal lock-up, panda mark + "alima" wordmark
 * (brand-tokens.md §4: never redraw, retype or rearrange it). Drawn inline from the master
 * artwork so it inherits `currentColor` and one component can serve every approved colourway.
 *
 * **Sizes are widths, not tastes.** §4 sets a hard minimum of 120px for the horizontal lock-up,
 * and the proportion is 3.36:1, so a height below 36px breaks the book. `md` (40px → 134px) is
 * the marketing header and the sidebar — it is what the reference screens in
 * `resources/screenshots/` show. `sm` (36px → 121px) is the floor, for tight chrome. `lg`
 * (48px → 161px) is §2.3's "logo 160px", for a landing footer or a wide signature.
 *
 * `tone` picks an approved colourway: light = 01 Teal on Cream, dark = 03 Cream on Teal (and 02
 * Cream on Ink). Pass a text colour class instead for any of the other six (§4).
 */
withDefaults(
    defineProps<{
        tone?: 'light' | 'dark';
        size?: 'sm' | 'md' | 'lg';
        class?: HTMLAttributes['class'];
    }>(),
    { tone: 'light', size: 'md' },
);

// Heights chosen so the lock-up never renders under its 120px minimum width.
const heights = {
    sm: 'h-9',
    md: 'h-10',
    lg: 'h-12',
} as const;
</script>

<template>
    <BrandArtwork
        :artwork="logoArtwork"
        title="Alima"
        class="w-auto shrink-0"
        :class="[
            heights[size],
            tone === 'dark' ? 'text-cream' : 'text-teal',
            $props.class,
        ]"
    />
</template>
