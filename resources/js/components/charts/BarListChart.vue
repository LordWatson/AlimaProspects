<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { BreakdownSlice } from '@/types';

/*
 * A horizontal bar list — magnitude by category, which is what the pipeline, the lead sources and
 * the signal types all are. One series, so there is no legend and nothing to tell apart by colour:
 * the label sits beside its own bar and every value is direct-labelled.
 *
 * Deliberately plain HTML rather than SVG. A bar is a box of a given width; `div`s give us real
 * text wrapping, logical properties that mirror under RTL for free, and no measuring.
 *
 * Bars are scaled against the **largest** value, not the total, so a list of small numbers still
 * has a shape. `rounded-e-sm` rounds the data end only, keeping the baseline square.
 */
const props = withDefaults(
    defineProps<{
        slices: BreakdownSlice[];
        /** A chart token: `bg-chart-1`…`bg-chart-5`. */
        tone?: string;
        /** Show each bar's share of the total beside its value. */
        showShare?: boolean;
    }>(),
    { tone: 'bg-chart-2', showShare: false },
);

const { t } = useI18n();
const { number, percent } = useFormat();

const peak = computed(() =>
    Math.max(...props.slices.map((slice) => slice.value), 1),
);

const total = computed(() =>
    props.slices.reduce((sum, slice) => sum + slice.value, 0),
);

// A non-zero value always gets a sliver of bar: "1 of 400" must still be visible as more than
// nothing, or the chart quietly says zero.
const widthOf = (value: number): string =>
    value === 0 ? '0%' : `${Math.max(2, (value / peak.value) * 100)}%`;

const shareOf = (value: number): string =>
    total.value === 0 ? '' : percent(value / total.value);
</script>

<template>
    <ul class="flex flex-col gap-4">
        <li v-for="slice in slices" :key="slice.key" class="group">
            <div class="flex items-baseline justify-between gap-3">
                <p class="min-w-0 text-sm font-medium">
                    <span class="truncate">{{ t(slice.label) }}</span>
                </p>
                <p class="shrink-0 text-sm tabular-nums">
                    <span class="font-semibold">{{ number(slice.value) }}</span>
                    <span
                        v-if="showShare && total > 0"
                        class="ms-2 text-muted-foreground"
                        >{{ shareOf(slice.value) }}</span
                    >
                </p>
            </div>

            <div
                class="mt-2 h-2.5 w-full overflow-hidden rounded-sm bg-muted"
                role="img"
                :aria-label="
                    t(':label: :value', {
                        label: t(slice.label),
                        value: number(slice.value),
                    })
                "
            >
                <div
                    class="h-full rounded-e-sm transition-[width] duration-250"
                    :class="tone"
                    :style="{ width: widthOf(slice.value) }"
                />
            </div>

            <p v-if="slice.hint" class="mt-1.5 text-xs text-muted-foreground">
                {{ t(slice.hint) }}
            </p>
        </li>
    </ul>
</template>
