<script setup lang="ts">
import { computed } from 'vue';

/*
 * A KPI tile's trend line.
 *
 * One series, so there is no legend and no second hue to confuse: the tile's own label names it
 * (dataviz: a single series needs no legend box). A 2px line on a recessive track, no axis, no
 * grid, no point markers — at 36px tall anything else is noise rather than information.
 *
 * Not interactive, and that is the considered choice rather than the lazy one. A crosshair
 * tooltip needs a hit target bigger than the mark, and there is no room for one under a thumb on
 * a 36px strip. The non-visual read is the `aria-label` instead, which carries the actual figures
 * — so the series is never available only as a shape, and never only as a colour.
 */
const props = defineProps<{
    /** Weekly values, oldest first. Fewer than two points draws nothing. */
    values: number[];
    /** What the line is, for assistive technology. A finished sentence. */
    label: string;
}>();

const width = 120;
const height = 36;
const inset = 3;

const points = computed(() => {
    const values = props.values;

    if (values.length < 2) {
        return '';
    }

    const min = Math.min(...values);
    const max = Math.max(...values);
    // A flat series would divide by zero; draw it down the middle instead, which is the honest
    // picture of "this did not move".
    const span = max - min === 0 ? 1 : max - min;
    const step = (width - inset * 2) / (values.length - 1);

    return values
        .map((value, index) => {
            const x = inset + index * step;
            const y =
                height - inset - ((value - min) / span) * (height - inset * 2);

            return `${x.toFixed(1)},${y.toFixed(1)}`;
        })
        .join(' ');
});
</script>

<template>
    <svg
        v-if="points !== ''"
        class="spark"
        :viewBox="`0 0 ${width} ${height}`"
        preserveAspectRatio="none"
        role="img"
        :aria-label="label"
        dir="ltr"
    >
        <polyline
            :points="points"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            vector-effect="non-scaling-stroke"
        />
    </svg>
</template>

<style scoped>
.spark {
    /* The single-series data mark. The token carries the dark value too, which is a
       different hex rather than a tint — base Teal is 1.9:1 on Ink. */
    color: var(--alima-chart-teal);
    display: block;
    width: 100%;
    height: 36px;
}
</style>
