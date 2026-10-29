<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import PandaFace from '@/components/PandaFace.vue';

/*
 * "The peek" (brand-tokens.md §5) — the panda scaled up and cropped by the
 * frame, both eyes in view, bleeding past an edge. Drop it into a container
 * that is `relative overflow-hidden`; pass sizing/position via `class`.
 * One peek, one colour per layout. Decorative only (aria-hidden).
 */
defineProps<{
    class?: HTMLAttributes['class'];
    tone?: string;
}>();
</script>

<template>
    <div
        :class="$props.class"
        class="pointer-events-none select-none"
        aria-hidden="true"
    >
        <PandaFace class="h-full w-full" :class="tone ?? 'text-lilac'" />
    </div>
</template>
