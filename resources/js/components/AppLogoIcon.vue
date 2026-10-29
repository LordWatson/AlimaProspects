<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

/*
 * The Alima "snout" — the brand's smallest signature, strictly NOSE + SMILE
 * (brand-tokens.md §5), used as a placeholder app mark until the delivered logo
 * master SVGs arrive (§7.3 PENDING). Renders in currentColor so it inherits the
 * approved tile colourway. This is the snout element, NOT the full logo.
 */
defineOptions({ inheritAttrs: false });

defineProps<{ className?: HTMLAttributes['class'] }>();
</script>

<template>
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 48 48"
        fill="none"
        :class="className"
        v-bind="$attrs"
        aria-hidden="true"
    >
        <!-- nose -->
        <ellipse cx="24" cy="21" rx="3.4" ry="2.6" fill="currentColor" />
        <!-- smile -->
        <path
            d="M13 24c2.3 5.4 6.4 8 11 8s8.7-2.6 11-8"
            stroke="currentColor"
            stroke-width="3.4"
            stroke-linecap="round"
            fill="none"
        />
    </svg>
</template>
