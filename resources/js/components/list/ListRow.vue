<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * One row.
 *
 * `relative` is load-bearing — **do not remove it.** It is what lets the row's primary link
 * (`ListRowLink`) stretch its hit area over the whole row, so opening a record is a row-sized
 * target rather than a word-sized one, while the row still holds exactly **one** link, which is
 * what keeps a screen reader reading one name per row instead of five.
 *
 * It is load-bearing in the literal sense: the stretch is an absolutely positioned `<span>`, and
 * if this row stops being its containing block the span resolves against `ListTable`'s wrapper
 * instead and covers the **whole table** — every click anywhere in the list would then open one
 * row. Relative positioning on a `<tr>` was undefined in CSS 2.1 and is defined now; every engine
 * this product supports implements it (the last holdout, Firefox, since 30).
 *
 * `group/row` so a cell can react to the row being hovered; the row's quick actions do.
 */
const props = withDefaults(
    defineProps<{
        /** Picked out by a checkbox. */
        selected?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    { selected: false, class: '' },
);
</script>

<template>
    <tr
        :class="
            cn(
                'group/row relative transition-colors focus-within:bg-accent/40 hover:bg-accent/40',
                props.selected ? 'bg-accent/60' : '',
                props.class,
            )
        "
        :data-state="props.selected ? 'selected' : undefined"
    >
        <slot />
    </tr>
</template>
