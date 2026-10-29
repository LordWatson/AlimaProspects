<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

const props = defineProps<{ class?: HTMLAttributes['class'] }>();
</script>

<template>
    <th
        data-slot="table-head"
        :class="
            cn(
                'h-11 px-4 text-start align-middle font-mono text-xs font-medium uppercase tracking-wide text-muted-foreground rtl:tracking-normal [&:has([role=checkbox])]:pe-0',
                props.class,
            )
        "
    >
        <slot />
    </th>
</template>
