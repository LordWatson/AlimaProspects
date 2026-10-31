<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import BrandArtwork from '@/components/BrandArtwork.vue';
import { markArtwork } from '@/shared/brand/artwork';

/*
 * The Alima panda mark — master artwork from `shared/brand/artwork.ts` (brand-tokens.md §4:
 * never redraw or approximate the logo). Single colour via `currentColor`; the eyes and the
 * snout's nose/smile are true cut-outs, so the surface behind shows through (a cream mark on a
 * teal tile, a lilac "peek" on the cream canvas). Never mirrored — `BrandArtwork` forces ltr.
 */
defineOptions({ inheritAttrs: false });

defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <BrandArtwork
        :artwork="markArtwork"
        :class="$props.class"
        v-bind="$attrs"
    />
</template>
