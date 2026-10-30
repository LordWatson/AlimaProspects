<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { TrendPoint } from '@/types';

/*
 * Leads sourced per week against how many of them graded A or B — the dashboard's one
 * change-over-time chart.
 *
 * Built to the dataviz guidance: one y-axis (never a second scale), 2px lines, a recessive grid, a
 * legend plus a tooltip so the two series are never told apart by colour alone, and a crosshair on
 * hover *and* on keyboard arrows. The series colours are the validated `chart-1`/`chart-4` pair
 * (deutan ΔE 23.6, identical hexes in light and dark, so a series keeps its identity when the
 * theme flips). The sr-only table is the chart's text equivalent.
 *
 * Geometry is measured rather than stretched: a `preserveAspectRatio="none"` viewBox would scale
 * the labels along with the box.
 */
const props = defineProps<{
    points: TrendPoint[];
    weeks: number;
}>();

const { t, isRtl } = useI18n();
const { number, date } = useFormat();

const frame = useTemplateRef<HTMLElement>('frame');
const { width } = useElementSize(frame);

const HEIGHT = 240;
const PAD = { top: 16, right: 16, bottom: 30, left: 40 };

// Falls back to a sensible width for the first paint, before the observer reports.
const chartWidth = computed(() => Math.max(width.value || 640, 320));

const plot = computed(() => ({
    left: PAD.left,
    right: chartWidth.value - PAD.right,
    top: PAD.top,
    bottom: HEIGHT - PAD.bottom,
    width: chartWidth.value - PAD.left - PAD.right,
    height: HEIGHT - PAD.top - PAD.bottom,
}));

/**
 * A round-numbered ceiling, so the axis reads 0/25/50 rather than 0/22.5/45.
 *
 * Rounded up to a multiple of **twice** the step, not the step, because the axis only draws three
 * ticks: whatever the ceiling is, half of it has to be a whole number too.
 */
const max = computed(() => {
    const peak = Math.max(
        ...props.points.map((point) => point.sourced),
        ...props.points.map((point) => point.qualified),
        0,
    );

    if (peak <= 4) {
        return 4;
    }

    const magnitude = 10 ** Math.floor(Math.log10(peak));
    const ratio = peak / magnitude;
    const step =
        ratio > 5 ? magnitude : ratio > 2 ? magnitude / 2 : magnitude / 5;
    const unit = step * 2;

    return Math.ceil(peak / unit) * unit;
});

const ticks = computed(() => [0, max.value / 2, max.value]);

const xAt = (index: number): number => {
    const count = props.points.length;

    if (count <= 1) {
        return plot.value.left + plot.value.width / 2;
    }

    return plot.value.left + (index / (count - 1)) * plot.value.width;
};

const yAt = (value: number): number =>
    plot.value.bottom - (value / max.value) * plot.value.height;

const path = (key: 'sourced' | 'qualified'): string =>
    props.points
        .map(
            (point, index) =>
                `${index === 0 ? 'M' : 'L'}${xAt(index).toFixed(2)},${yAt(point[key]).toFixed(2)}`,
        )
        .join(' ');

const sourcedLine = computed(() => path('sourced'));
const qualifiedLine = computed(() => path('qualified'));

const sourcedArea = computed(() => {
    if (props.points.length === 0) {
        return '';
    }

    return [
        `M${xAt(0).toFixed(2)},${plot.value.bottom}`,
        sourcedLine.value.slice(1),
        `L${xAt(props.points.length - 1).toFixed(2)},${plot.value.bottom}`,
        'Z',
    ].join(' ');
});

/*
 * Only every third label on a narrow box: twelve dates at phone width overlap into a smudge, and
 * the first and last week are the two a reader actually looks for.
 */
const labelEvery = computed(() => (chartWidth.value < 520 ? 3 : 1));

const showLabel = (index: number): boolean =>
    index === 0 ||
    index === props.points.length - 1 ||
    index % labelEvery.value === 0;

const active = ref<number | null>(null);
const activePoint = computed(() =>
    active.value === null ? null : (props.points[active.value] ?? null),
);

function track(event: MouseEvent): void {
    const box = frame.value?.getBoundingClientRect();

    if (!box || props.points.length === 0) {
        return;
    }

    // Logical, not physical: under RTL the first week sits on the right.
    const offset = isRtl.value
        ? box.right - event.clientX
        : event.clientX - box.left;
    const ratio = (offset - PAD.left) / Math.max(1, plot.value.width);

    active.value = Math.min(
        props.points.length - 1,
        Math.max(0, Math.round(ratio * (props.points.length - 1))),
    );
}

function step(delta: number): void {
    const count = props.points.length;

    if (count === 0) {
        return;
    }

    const next = (active.value ?? (delta > 0 ? -1 : count)) + delta;
    active.value = Math.min(count - 1, Math.max(0, next));
}

/** Tooltip offset in px, clamped so the card never hangs off either edge. */
const TOOLTIP_WIDTH = 160; // `w-40`
const tooltipOffset = computed(() => {
    if (active.value === null) {
        return 0;
    }

    return Math.min(
        Math.max(0, chartWidth.value - TOOLTIP_WIDTH),
        Math.max(0, xAt(active.value) - TOOLTIP_WIDTH / 2),
    );
});

const series = computed(() => [
    { key: 'sourced', label: t('Sourced'), tone: 'bg-chart-1' },
    { key: 'qualified', label: t('Strong fits'), tone: 'bg-chart-4' },
]);

const totals = computed(() => ({
    sourced: props.points.reduce((sum, point) => sum + point.sourced, 0),
    qualified: props.points.reduce((sum, point) => sum + point.qualified, 0),
}));
</script>

<template>
    <figure class="flex flex-col gap-5">
        <figcaption class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Last :weeks weeks', { weeks }) }}
                </p>
                <h3
                    class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                >
                    {{ t('What Alima found, week by week') }}
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">
                    {{
                        t('Sourced :sourced · Strong fits :qualified', {
                            sourced: number(totals.sourced),
                            qualified: number(totals.qualified),
                        })
                    }}
                </p>
            </div>

            <!-- Legend: always present for two series, so identity is never colour-alone. -->
            <ul class="flex flex-wrap items-center gap-4">
                <li
                    v-for="item in series"
                    :key="item.key"
                    class="flex items-center gap-2 text-sm text-muted-foreground"
                >
                    <span
                        class="size-2.5 rounded-sm"
                        :class="item.tone"
                        aria-hidden="true"
                    />
                    {{ item.label }}
                </li>
            </ul>
        </figcaption>

        <div
            ref="frame"
            class="relative focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            tabindex="0"
            role="application"
            :aria-label="
                t(
                    'Leads sourced and qualified by week — use the arrow keys to read each week',
                )
            "
            @mousemove="track"
            @mouseleave="active = null"
            @blur="active = null"
            @keydown.left.prevent="step(isRtl ? 1 : -1)"
            @keydown.right.prevent="step(isRtl ? -1 : 1)"
            @keydown.esc="active = null"
        >
            <svg
                :viewBox="`0 0 ${chartWidth} ${HEIGHT}`"
                :height="HEIGHT"
                class="w-full"
                aria-hidden="true"
                focusable="false"
            >
                <!-- Grid + y labels (recessive) -->
                <g>
                    <template v-for="tick in ticks" :key="tick">
                        <line
                            :x1="plot.left"
                            :x2="plot.right"
                            :y1="yAt(tick)"
                            :y2="yAt(tick)"
                            class="stroke-border"
                            stroke-width="1"
                        />
                        <text
                            :x="plot.left - 8"
                            :y="yAt(tick) + 4"
                            text-anchor="end"
                            class="fill-muted-foreground font-mono text-xs"
                        >
                            {{ number(tick) }}
                        </text>
                    </template>
                </g>

                <!-- Sourced: area + line -->
                <path :d="sourcedArea" class="fill-chart-1" opacity="0.14" />
                <path
                    :d="sourcedLine"
                    fill="none"
                    class="stroke-chart-1"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <!-- Strong fits: line only, so the two never muddy each other where they overlap -->
                <path
                    :d="qualifiedLine"
                    fill="none"
                    class="stroke-chart-4"
                    stroke-width="2"
                    stroke-linecap="round"
                    stroke-linejoin="round"
                />

                <!-- x labels -->
                <g>
                    <template
                        v-for="(point, index) in points"
                        :key="point.starts_on"
                    >
                        <text
                            v-if="showLabel(index)"
                            :x="xAt(index)"
                            :y="HEIGHT - 10"
                            text-anchor="middle"
                            class="fill-muted-foreground font-mono text-xs"
                        >
                            {{ point.label }}
                        </text>
                    </template>
                </g>

                <!-- Crosshair + the hovered week's two points, each ringed in the surface colour -->
                <g v-if="activePoint && active !== null">
                    <line
                        :x1="xAt(active)"
                        :x2="xAt(active)"
                        :y1="plot.top"
                        :y2="plot.bottom"
                        class="stroke-foreground"
                        stroke-width="1"
                        opacity="0.35"
                    />
                    <circle
                        :cx="xAt(active)"
                        :cy="yAt(activePoint.sourced)"
                        r="5"
                        class="fill-chart-1 stroke-card"
                        stroke-width="2"
                    />
                    <circle
                        :cx="xAt(active)"
                        :cy="yAt(activePoint.qualified)"
                        r="5"
                        class="fill-chart-4 stroke-card"
                        stroke-width="2"
                    />
                </g>
            </svg>

            <div
                v-if="activePoint"
                class="pointer-events-none absolute top-0 w-40 rounded-xl border border-border bg-popover p-3 text-xs shadow-md"
                :style="{ insetInlineStart: `${tooltipOffset}px` }"
            >
                <p class="font-mono text-xs text-muted-foreground">
                    {{
                        t('Week of :date', {
                            date: date(activePoint.starts_on),
                        })
                    }}
                </p>
                <dl class="mt-2 space-y-1">
                    <div class="flex items-center justify-between gap-2">
                        <dt class="flex items-center gap-1.5">
                            <span
                                class="size-2 rounded-sm bg-chart-1"
                                aria-hidden="true"
                            />
                            {{ t('Sourced') }}
                        </dt>
                        <dd class="font-semibold tabular-nums">
                            {{ number(activePoint.sourced) }}
                        </dd>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <dt class="flex items-center gap-1.5">
                            <span
                                class="size-2 rounded-sm bg-chart-4"
                                aria-hidden="true"
                            />
                            {{ t('Strong fits') }}
                        </dt>
                        <dd class="font-semibold tabular-nums">
                            {{ number(activePoint.qualified) }}
                        </dd>
                    </div>
                </dl>
            </div>
        </div>

        <!-- The chart's text equivalent. -->
        <table class="sr-only">
            <caption>
                {{
                    t('Leads sourced and qualified by week')
                }}
            </caption>
            <thead>
                <tr>
                    <th scope="col">{{ t('Week beginning') }}</th>
                    <th scope="col">{{ t('Sourced') }}</th>
                    <th scope="col">{{ t('Strong fits') }}</th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="point in points" :key="point.starts_on">
                    <th scope="row">{{ date(point.starts_on) }}</th>
                    <td>{{ number(point.sourced) }}</td>
                    <td>{{ number(point.qualified) }}</td>
                </tr>
            </tbody>
        </table>
    </figure>
</template>
