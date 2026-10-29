<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{
    class?: HTMLAttributes['class'];
    colspan?: number;
}>();
</script>

<template>
    <tr>
        <td
            :colspan="props.colspan ?? 1"
            :class="cn('p-10 text-center text-sm text-muted-foreground', props.class)"
        >
            <slot />
        </td>
    </tr>
</template>
