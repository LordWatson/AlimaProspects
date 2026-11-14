<script setup lang="ts">
import type { HTMLAttributes } from "vue";
import { cn } from "@/lib/utils";

const props = defineProps<{ class?: HTMLAttributes["class"] }>();
</script>

<template>
    <tr
        data-slot="table-row"
        :class="
            cn(
                'border-b border-line-soft transition-colors duration-150 last:border-b-0 hover:bg-background data-[state=selected]:bg-accent',
                props.class,
            )
        "
    >
        <slot />
    </tr>
</template>
