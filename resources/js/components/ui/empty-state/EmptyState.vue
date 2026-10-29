<script setup lang="ts">
import type { Component, HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * Alima empty state — bright, warm, and encouraging (brand voice: lead with the
 * opportunity). Use for empty lists, zero-result searches and first-run screens.
 * Pass an `icon` (Lucide) and use the default slot for the primary CTA.
 */
const props = defineProps<{
    class?: HTMLAttributes['class'];
    icon?: Component;
    title: string;
    description?: string;
}>();
</script>

<template>
    <div
        data-slot="empty-state"
        :class="
            cn(
                'flex flex-col items-center justify-center gap-4 rounded-lg border border-dashed border-border bg-card/50 px-6 py-14 text-center',
                props.class,
            )
        "
    >
        <div
            v-if="props.icon"
            class="flex size-14 items-center justify-center rounded-full bg-brand/10 text-brand"
        >
            <component :is="props.icon" class="size-7" />
        </div>
        <div class="flex max-w-md flex-col gap-1.5">
            <h3 class="text-lg font-semibold text-foreground">{{ props.title }}</h3>
            <p v-if="props.description" class="text-sm text-muted-foreground">
                {{ props.description }}
            </p>
        </div>
        <div v-if="$slots.default" class="mt-1 flex flex-wrap items-center justify-center gap-2">
            <slot />
        </div>
    </div>
</template>
