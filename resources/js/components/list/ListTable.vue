<script setup lang="ts">
import { computed, provide, toRef } from 'vue';
import type { HTMLAttributes } from 'vue';
import { useListDensity } from '@/composables/useListDensity';
import type { ListDensity } from '@/composables/useListDensity';
import { cn } from '@/lib/utils';
import { listDensityKey } from './context';

/*
 * The table every tenant list is built from.
 *
 * Three things it does that a hand-rolled `<table>` on each screen did not, and that are the
 * reason it exists:
 *
 *  - **The column headers stay.** `thead` cells are sticky against the app shell's scrolling
 *    `<main>`, so the fortieth row still knows which column is which. That is also why the card
 *    is `overflow-clip` rather than `overflow-hidden`: `hidden` makes the card a scroll container
 *    of its own and a sticky header inside it then has nothing to stick to. `clip` crops the
 *    rounded corners without that side effect. A table wide enough to need sideways scrolling
 *    passes `scroll`, which trades the sticky header for an `overflow-x` container — the two
 *    cannot both be had, and on a wide table the scrollbar is the more useful of the two.
 *  - **Borders survive.** `border-separate` with zero spacing, borders on the cells. With
 *    `border-collapse` a sticky header loses its own bottom rule as soon as it lifts off.
 *  - **One density for the whole product**, provided here and read by every head and cell, so a
 *    member who wants dense tables gets dense tables everywhere (`useListDensity`).
 *
 * `busy` is for the half-second after a filter changes: the server is rebuilding the list, so the
 * rows on screen are about to be replaced and are dimmed to say so rather than sitting there
 * looking like an answer.
 */
const props = withDefaults(
    defineProps<{
        /** What this table is, for screen readers. Every table says what it is. */
        caption: string;
        /** A request for this list is in flight. */
        busy?: boolean;
        /** Wide table: scroll sideways instead of holding the header. */
        scroll?: boolean;
        /** Override the member's own density — only where a table has no choice. */
        density?: ListDensity | null;
        class?: HTMLAttributes['class'];
    }>(),
    { busy: false, scroll: false, density: null },
);

const { density: preferred } = useListDensity();

const density = computed<ListDensity>(() => props.density ?? preferred.value);

provide(listDensityKey, toRef(density));
</script>

<template>
    <div class="relative">
        <div
            :class="
                cn(
                    'rounded-2xl border border-border bg-card',
                    props.scroll ? 'overflow-x-auto' : 'overflow-clip',
                )
            "
        >
            <table
                :class="
                    cn(
                        'w-full border-separate border-spacing-0 text-sm',
                        // The last row sits on the card's own edge, so it needs no rule of its own.
                        '[&_tbody_tr:last-child_td]:border-b-0',
                        // The header band is opaque (rows pass underneath it) and takes the card's
                        // top corners with it.
                        '[&_thead_th:first-child]:rounded-ss-2xl [&_thead_th:last-child]:rounded-se-2xl',
                        // The header sits above a `raised` cell (z-10): body cells come
                        // later in the DOM, so an equal z-index let the checkbox column
                        // show through the band as the rows passed under it.
                        props.scroll
                            ? ''
                            : '[&_thead_th]:sticky [&_thead_th]:top-0 [&_thead_th]:z-20',
                        props.class,
                    )
                "
                :aria-busy="props.busy ? 'true' : undefined"
            >
                <caption class="sr-only">
                    {{
                        props.caption
                    }}
                </caption>
                <slot />
            </table>
        </div>

        <!-- The veil. Pointer-events pass through: a member who has already reached for the next
             chip should not have the click swallowed by a loading state. -->
        <div
            v-if="props.busy"
            class="pointer-events-none absolute inset-0 z-30 rounded-2xl bg-background/55 transition-opacity"
            aria-hidden="true"
        />
    </div>
</template>
