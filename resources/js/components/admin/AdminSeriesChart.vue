<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';

/*
 * One daily series, drawn big (the 19d overhaul).
 *
 * **Why not `charts/TrendChart`.** That one carries the tenant dashboard's two series —
 * sourced against qualified — and its axis is cut in the *team's* timezone. The panel has neither:
 * one metric at a time, always in UTC, and a switcher above it. Bending the tenant chart into both
 * shapes would have made one component that serves two screens badly.
 *
 * It follows the same `dataviz` rules as the rest of the set: one y-axis, a thin mark, a recessive
 * grid, **direct labels** instead of a tooltip (the peak and the last point are written on the
 * chart), and a **text equivalent** — the sr-only table below is the chart for anybody who cannot
 * see it, which is the rule a sparkline satisfies with one word and a real chart has to satisfy
 * with the numbers.
 *
 * **`chart-2` by default, which is the Teal single-series token** (#205557, 8.1:1 on cream) —
 * not `chart-1`, which is Blue and reads at about 3:1 as a 2px mark. The first version said "Deep
 * Teal (`chart-1`)" in this very comment and drew a blue line, which is how a brand review earns
 * its keep: the panel's one accent is teal, and a second data hue beside it is exactly what §1.2
 * forbids.
 */
const props = withDefaults(
    defineProps<{
        /** Daily points, oldest first. `label` is a `Y-m-d`. */
        points: { label: string; value: number }[];
        /** What the series is, for the text equivalent. */
        title: string;
        /** `count` renders plain integers; `money` renders minor units as currency. */
        format?: 'count' | 'money';
        tone?: string;
    }>(),
    { format: 'count', tone: 'text-chart-2' },
);

const { number, currency, date } = useFormat();

const W = 720;
const H = 200;
const PAD = { top: 16, bottom: 26, start: 8, end: 8 };

const hasShape = computed(() => props.points.length >= 2);

const max = computed(() =>
    Math.max(...props.points.map((point) => point.value), 1),
);

const plot = computed(() => {
    const count = props.points.length;
    const width = W - PAD.start - PAD.end;
    const height = H - PAD.top - PAD.bottom;
    const step = count > 1 ? width / (count - 1) : 0;

    return props.points.map((point, index) => ({
        ...point,
        x: PAD.start + index * step,
        y: PAD.top + height - (point.value / max.value) * height,
    }));
});

const line = computed(() =>
    plot.value.map((p) => `${p.x.toFixed(1)},${p.y.toFixed(1)}`).join(' '),
);

const area = computed(
    () =>
        `${PAD.start},${H - PAD.bottom} ${line.value} ${(W - PAD.end).toFixed(1)},${H - PAD.bottom}`,
);

/** The highest day, which is the one worth labelling on the chart itself. */
const peak = computed(() =>
    plot.value.reduce(
        (highest, point) => (point.value > highest.value ? point : highest),
        plot.value[0] ?? { label: '', value: 0, x: 0, y: 0 },
    ),
);

const last = computed(() => plot.value[plot.value.length - 1] ?? null);

const total = computed(() =>
    props.points.reduce((sum, point) => sum + point.value, 0),
);

function show(value: number): string {
    return props.format === 'money' ? currency(value, 'GBP') : number(value);
}

function day(label: string): string {
    return date(label, { day: 'numeric', month: 'short' });
}
</script>

<template>
    <figure class="flex flex-col gap-3">
        <div v-if="hasShape" :class="tone">
            <svg
                :viewBox="`0 0 ${W} ${H}`"
                class="h-[200px] w-full"
                role="presentation"
                focusable="false"
            >
                <!-- A recessive grid: three rules, no box, no ticks. -->
                <line
                    v-for="fraction in [0, 0.5, 1]"
                    :key="fraction"
                    :x1="PAD.start"
                    :x2="W - PAD.end"
                    :y1="PAD.top + (H - PAD.top - PAD.bottom) * fraction"
                    :y2="PAD.top + (H - PAD.top - PAD.bottom) * fraction"
                    class="stroke-border"
                    stroke-width="1"
                />

                <polygon :points="area" fill="currentColor" opacity="0.1" />
                <polyline
                    :points="line"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                    vector-effect="non-scaling-stroke"
                />

                <!-- Direct labels rather than a tooltip, and that means the **numbers**:
                     two unlabelled markers and a grid with no values is not a direct label, it is
                     a decoration that a comment claimed credit for (brand review). -->
                <circle
                    v-if="peak.value > 0"
                    :cx="peak.x"
                    :cy="peak.y"
                    r="3"
                    fill="none"
                    stroke="currentColor"
                    stroke-width="1.5"
                />
                <text
                    v-if="peak.value > 0"
                    :x="peak.x"
                    :y="Math.max(peak.y - 10, 12)"
                    text-anchor="middle"
                    class="fill-muted-foreground font-mono text-[11px]"
                >
                    {{ show(peak.value) }}
                </text>

                <circle
                    v-if="last"
                    :cx="last.x"
                    :cy="last.y"
                    r="3.5"
                    fill="currentColor"
                />
                <text
                    v-if="last && last !== peak"
                    :x="last.x"
                    :y="Math.max(last.y - 10, 12)"
                    text-anchor="end"
                    class="fill-foreground font-mono text-[11px]"
                >
                    {{ show(last.value) }}
                </text>
            </svg>
        </div>

        <p v-else class="py-10 text-center text-sm text-muted-foreground">
            Nothing recorded in this window yet. The nightly rollup fills this
            in.
        </p>

        <!-- The axis, in words: where the window opens and closes, the peak, and the total. Three
             facts beat a row of eleven dates nobody reads. -->
        <figcaption
            v-if="hasShape"
            class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-1 font-mono text-xs text-muted-foreground tabular-nums"
        >
            <span
                >{{ day(plot[0]?.label ?? '') }} —
                {{ day(last?.label ?? '') }}</span
            >
            <span
                >peak {{ show(peak.value) }} on {{ day(peak.label) }} · total
                {{ show(total) }}</span
            >
        </figcaption>

        <!-- The text equivalent the guidance asks for. A screen reader gets the series, not a
             description of a picture of it. -->
        <table class="sr-only">
            <caption>
                {{
                    title
                }}
                by day, UTC
            </caption>
            <thead>
                <tr>
                    <th scope="col">Day</th>
                    <th scope="col">Value</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="point in points" :key="point.label">
                    <th scope="row">{{ point.label }}</th>
                    <td>{{ show(point.value) }}</td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
