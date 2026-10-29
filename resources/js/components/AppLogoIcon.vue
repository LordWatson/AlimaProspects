<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import PandaFace from '@/components/PandaFace.vue';

/*
 * The Alima panda mark. Thin wrapper over PandaFace so every existing call
 * site (`<AppLogoIcon class="size-6" />`) now renders the real brand panda.
 */
defineOptions({ inheritAttrs: false });

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <PandaFace :class="props.class" v-bind="$attrs" />
</template>
