<script setup lang="ts">
import type { Component, HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * Alima empty state — bright, warm, and encouraging (brand voice: lead with the
 * opportunity). Use for empty lists, zero-result searches and first-run screens.
 * Pass an `icon` (Lucide) and use the default slot for the primary CTA.
 *
 * `size="sm"` is for an empty state **inside** a panel rather than in place of a page: three
 * full-height ones stacked down a record screen (no contacts, no tasks, no notes) made a new
 * lead look like a fault. Same composition, two thirds of the height.
 */
const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        icon?: Component;
        title: string;
        description?: string;
        size?: 'sm' | 'default';
    }>(),
    { size: 'default' },
);
</script>

<template>
    <div
        data-slot="empty-state"
        :class="
            cn(
                'flex flex-col items-center justify-center rounded-lg border border-dashed border-border bg-card/50 text-center',
                props.size === 'sm' ? 'gap-3 px-5 py-8' : 'gap-4 px-6 py-14',
                props.class,
            )
        "
    >
        <div
            v-if="props.icon"
            :class="
                cn(
                    'flex items-center justify-center rounded-full bg-brand/10 text-brand',
                    props.size === 'sm' ? 'size-10' : 'size-14',
                )
            "
        >
            <component
                :is="props.icon"
                :class="props.size === 'sm' ? 'size-5' : 'size-7'"
            />
        </div>
        <div class="flex max-w-md flex-col gap-1.5">
            <h3
                :class="
                    cn(
                        'font-semibold text-foreground',
                        props.size === 'sm' ? 'text-base' : 'text-lg',
                    )
                "
            >
                {{ props.title }}
            </h3>
            <p v-if="props.description" class="text-sm text-muted-foreground">
                {{ props.description }}
            </p>
        </div>
        <div
            v-if="$slots.default"
            class="mt-1 flex flex-wrap items-center justify-center gap-2"
        >
            <slot />
        </div>
    </div>
</template>
