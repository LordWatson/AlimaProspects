<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import type { Artwork } from '@/shared/brand/artwork';

/*
 * Renders one piece of Alima master artwork as an inline, single-colour SVG that inherits
 * `currentColor` (brand-tokens.md §4). Give it a `title` and it is an image with an accessible
 * name; leave it off and it is decoration, hidden from assistive tech.
 *
 * `dir="ltr"` is forced on the element itself: the logo is never mirrored, in any language (§4's
 * "Never" list), and an RTL page would otherwise flip it along with everything else.
 */
defineOptions({ inheritAttrs: false });

defineProps<{
    artwork: Artwork;
    /** Accessible name. Omit for decorative artwork. */
    title?: string;
    class?: HTMLAttributes['class'];
}>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        dir="ltr"
        :viewBox="artwork.viewBox"
        fill="currentColor"
        :role="title ? 'img' : undefined"
        :aria-hidden="title ? undefined : true"
        :class="$props.class"
        v-bind="$attrs"
    >
        <title v-if="title">{{ title }}</title>
        <path :d="artwork.d" />
    </svg>
</template>
