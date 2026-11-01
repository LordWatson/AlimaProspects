<script setup lang="ts">
import { computed, inject } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';
import { listDensityKey } from './context';

/*
 * One cell. Padding comes from the table's density, alignment and breakpoint from the column.
 *
 * `raised` is for a cell holding its own control — a checkbox, a menu — in a row whose primary
 * link stretches over the whole row: the stretched hit area is a positioned element and would
 * otherwise swallow the click. **Never put the row's own link in a raised cell**: `raised` makes
 * that cell the containing block, so the stretch would shrink to it and the row would quietly stop
 * being clickable. Figures take `mono`, which brings `tabular-nums` with it so a column of numbers
 * lines up on the digit instead of drifting.
 */
const props = withDefaults(
    defineProps<{
        align?: 'start' | 'end' | 'center';
        hide?: 'sm' | 'md' | 'lg' | null;
        /** Secondary information — the row's own name stays `text-foreground`. */
        muted?: boolean;
        /** Figures: DM Mono, tabular. */
        mono?: boolean;
        /** Lift this cell above the row's stretched link so its own controls stay clickable. */
        raised?: boolean;
        nowrap?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        align: 'start',
        hide: null,
        muted: false,
        mono: false,
        raised: false,
        nowrap: false,
        class: '',
    },
);

const density = inject(listDensityKey, undefined);

const padding = computed(() =>
    density?.value === 'compact' ? 'px-4 py-1.5' : 'px-4 py-3',
);

const hideClass = computed(
    () =>
        ({
            sm: 'hidden sm:table-cell',
            md: 'hidden md:table-cell',
            lg: 'hidden lg:table-cell',
        })[props.hide ?? 'sm'],
);

const alignClass = computed(
    () =>
        ({
            start: 'text-start',
            end: 'text-end',
            center: 'text-center',
        })[props.align],
);
</script>

<template>
    <td
        :class="
            cn(
                'border-b border-border align-middle',
                padding,
                alignClass,
                props.hide ? hideClass : '',
                props.muted ? 'text-muted-foreground' : '',
                props.mono ? 'font-mono text-xs tabular-nums' : '',
                props.raised ? 'relative z-10' : '',
                props.nowrap ? 'whitespace-nowrap' : '',
                props.class,
            )
        "
    >
        <slot />
    </td>
</template>
