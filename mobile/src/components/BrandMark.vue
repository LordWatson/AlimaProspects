<script setup lang="ts">
import { computed } from 'vue';
import { logoArtwork, markArtwork } from '@shared/brand/artwork';

/*
 * The Alima logo, drawn from the master artwork in `@shared/brand/artwork` — the same path data
 * the web app renders (brand-tokens.md §4: never redraw, retype or approximate the logo).
 *
 * Single-colour via `currentColor`, so one lock-up serves every approved colourway: the eyes and
 * the snout's cut-outs let the surface behind show through.
 *
 * `dir="ltr"` is forced on the element. The logo is never mirrored, in any language — and an RTL
 * screen would otherwise flip it along with everything else.
 */
const props = withDefaults(
    defineProps<{
        /** `signature` is the mark plus the wordmark (min width 120px); `mark` the panda alone (min 24px). */
        variant?: 'signature' | 'mark';
        /** Accessible name. Omit on a decorative instance sitting next to the app's own title. */
        title?: string;
    }>(),
    { variant: 'signature', title: undefined },
);

const artwork = computed(() =>
    props.variant === 'mark' ? markArtwork : logoArtwork,
);
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        dir="ltr"
        :viewBox="artwork.viewBox"
        fill="currentColor"
        :role="title ? 'img' : undefined"
        :aria-hidden="title ? undefined : true"
        class="brand-mark"
        :class="`brand-mark--${variant}`"
    >
        <title v-if="title">{{ title }}</title>
        <path :d="artwork.d" />
    </svg>
</template>

<style scoped>
.brand-mark {
    display: block;
    height: auto;
}

/* The two lock-ups have different minimum widths — 120px for the signature, 24px for the mark
   alone (brand-tokens.md §4) — so one `min-width` cannot enforce both, and the signature was
   the one going unguarded. Enforced here rather than left to each call site, because a logo
   below its minimum is a brand breach the next screen inherits. */
.brand-mark--signature {
    min-width: 120px;
}

.brand-mark--mark {
    min-width: 24px;
}
</style>
