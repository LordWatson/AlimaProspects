<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * The row's one real link, with the whole row as its hit area.
 *
 * The stretch is a bare `<span>` inside the link, absolute against the row (`ListRow` is
 * `relative`): a click anywhere on the row opens the record — the target a pointer actually wants
 * — while the row still holds a single link, named by its own text, for anybody reading it with a
 * screen reader or tabbing through. The focus ring is drawn on the text rather than on the
 * stretched area, so a keyboard user sees *where* they are instead of a ring around the whole row.
 *
 * A cell with its own control in the same row passes `raised` to `ListCell`, which lifts it clear
 * of the stretch.
 */
const props = withDefaults(
    defineProps<{
        href: string;
        class?: HTMLAttributes['class'];
    }>(),
    { class: '' },
);
</script>

<template>
    <Link
        :href="props.href"
        :class="
            cn(
                'rounded-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none',
                props.class,
            )
        "
        dir="auto"
    >
        <slot />
        <span class="absolute inset-0" aria-hidden="true" />
    </Link>
</template>
