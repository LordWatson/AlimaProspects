<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';

/*
 * The small line inside a stat tile. It is a **secondary** encoding — the tile's number is the
 * headline and the trend chart below carries the exact values — so this has no axes, no labels and
 * no tooltip, per the dataviz guidance for stat tiles.
 *
 * Drawn in a fixed viewBox and stretched to the tile, with `vector-effect="non-scaling-stroke"` so
 * the 2px stroke stays 2px however wide the tile gets. `aria-hidden`, with the direction stated in
 * one sr-only line instead — a screen reader gets "rising", not 12 numbers.
 */
const props = withDefaults(
    defineProps<{
        /** Weekly values, oldest first. Fewer than two points draws nothing. */
        values: number[];
        /** A chart token: `chart-1`…`chart-5`. */
        tone?: string;
    }>(),
    { tone: 'text-chart-1' },
);

const { t } = useI18n();

const W = 100;
const H = 28;

const hasShape = computed(() => props.values.length >= 2);

const points = computed(() => {
    const values = props.values;
    const max = Math.max(...values, 1);
    const step = W / (values.length - 1);

    return values.map((value, index) => ({
        x: index * step,
        // 1px inset top and bottom so a flat series and a peak both stay inside the stroke.
        y: H - 1 - (value / max) * (H - 2),
    }));
});

const line = computed(() =>
    points.value.map((p) => `${p.x.toFixed(2)},${p.y.toFixed(2)}`).join(' '),
);

const area = computed(() => `0,${H} ${line.value} ${W},${H}`);

/** Last value against the first non-zero one — enough for a one-word direction. */
const direction = computed(() => {
    const values = props.values;
    const first = values.find((value) => value > 0) ?? 0;
    const last = values[values.length - 1] ?? 0;

    if (first === last) {
        return t('holding steady');
    }

    return last > first ? t('rising') : t('falling');
});
</script>

<template>
    <div v-if="hasShape" :class="tone">
        <svg
            :viewBox="`0 0 ${W} ${H}`"
            preserveAspectRatio="none"
            class="h-7 w-full"
            aria-hidden="true"
            focusable="false"
        >
            <polygon :points="area" fill="currentColor" opacity="0.12" />
            <polyline
                :points="line"
                fill="none"
                stroke="currentColor"
                stroke-width="2"
                stroke-linecap="round"
                stroke-linejoin="round"
                vector-effect="non-scaling-stroke"
            />
        </svg>
        <span class="sr-only">{{
            t('Recent trend: :direction', { direction })
        }}</span>
    </div>
</template>
