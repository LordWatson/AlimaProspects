<script setup lang="ts">
import { useElementSize } from '@vueuse/core';
import { computed, ref, useTemplateRef } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { TrendGranularity, TrendPoint } from '@/types';

/*
 * Leads sourced per period against how many of them graded A or B — the dashboard's one
 * change-over-time chart.
 *
 * The bucket width follows the dashboard's date filter rather than being fixed at a week: the
 * server picks it from the length of the window (daily under a short filter, monthly under a long
 * one) and this chart only has to say which it got. Everything a reader sees that names the unit —
 * the eyebrow, the heading, the tooltip, the screen-reader table — comes off one map below, so the
 * chart cannot call a month a week.
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
    granularity: TrendGranularity;
}>();

const { t, isRtl } = useI18n();
const { number, date } = useFormat();

const frame = useTemplateRef<HTMLElement>('frame');
const { width } = useElementSize(frame);

const HEIGHT = 240;
/*
 * Padding in **logical** terms: `start` is the side the axis labels sit on and the first bucket
 * opens from, which is the left in English and the right in Arabic. An SVG does not mirror itself
 * under `dir="rtl"` the way a `div` does, so the mirroring has to be arithmetic — see `xAt`.
 */
const PAD = { top: 16, bottom: 30, start: 40, end: 16 };

// Falls back to a sensible width for the first paint, before the observer reports.
const chartWidth = computed(() => Math.max(width.value || 640, 320));

const axisPad = computed(() => (isRtl.value ? PAD.end : PAD.start));

const plot = computed(() => ({
    left: axisPad.value,
    right: chartWidth.value - (isRtl.value ? PAD.start : PAD.end),
    top: PAD.top,
    bottom: HEIGHT - PAD.bottom,
    width: chartWidth.value - PAD.start - PAD.end,
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

/*
 * The physical x of a bucket. **The one place that knows which way time runs**, so the line, the
 * crosshair, the hover maths, the arrow keys and the tooltip cannot disagree about it: under RTL
 * the oldest bucket is on the right, the same way the text reads. Everything else works in
 * bucket indexes and asks here.
 */
const xAt = (index: number): number => {
    const count = props.points.length;
    const fraction = count <= 1 ? 0.5 : index / (count - 1);

    return (
        plot.value.left +
        (isRtl.value ? 1 - fraction : fraction) * plot.value.width
    );
};

/** The inverse of {@see xAt} — a physical x back to the nearest bucket index. */
const indexAt = (x: number): number => {
    const count = props.points.length;

    if (count <= 1) {
        return 0;
    }

    const fraction = (x - plot.value.left) / Math.max(1, plot.value.width);
    const along = isRtl.value ? 1 - fraction : fraction;

    return Math.min(count - 1, Math.max(0, Math.round(along * (count - 1))));
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

    // Always the physical offset from the left edge — `indexAt` is what knows about direction.
    active.value = indexAt(event.clientX - box.left);
}

function step(delta: number): void {
    const count = props.points.length;

    if (count === 0) {
        return;
    }

    const next = (active.value ?? (delta > 0 ? -1 : count)) + delta;
    active.value = Math.min(count - 1, Math.max(0, next));
}

/**
 * Tooltip offset in px, clamped so the card never hangs off either edge.
 *
 * It is set as `inset-inline-start`, which the browser measures from the **right** edge under RTL —
 * so the physical x from `xAt` has to be flipped to match, or the card lands on the mirror image of
 * the point it is describing.
 */
const TOOLTIP_WIDTH = 160; // `w-40`
const tooltipOffset = computed(() => {
    if (active.value === null) {
        return 0;
    }

    const x = xAt(active.value);
    const fromInlineStart = isRtl.value ? chartWidth.value - x : x;

    return Math.min(
        Math.max(0, chartWidth.value - TOOLTIP_WIDTH),
        Math.max(0, fromInlineStart - TOOLTIP_WIDTH / 2),
    );
});

/*
 * Every piece of wording that names the bucket, in one place. Each entry is a translation key, so
 * the chart reads correctly in Arabic too (ADR 0007).
 */
const WORDING: Record<
    TrendGranularity,
    {
        eyebrow: string;
        heading: string;
        tooltip: string;
        column: string;
        summary: string;
    }
> = {
    day: {
        eyebrow: 'Day by day',
        heading: 'What Alima found, day by day',
        tooltip: ':date',
        column: 'Day',
        summary: 'Leads sourced and qualified by day',
    },
    week: {
        eyebrow: 'Week by week',
        heading: 'What Alima found, week by week',
        tooltip: 'Week of :date',
        column: 'Week beginning',
        summary: 'Leads sourced and qualified by week',
    },
    month: {
        eyebrow: 'Month by month',
        heading: 'What Alima found, month by month',
        tooltip: 'Month of :date',
        column: 'Month',
        summary: 'Leads sourced and qualified by month',
    },
};

const wording = computed(() => WORDING[props.granularity]);

/**
 * A bucket's start, formatted at the bucket's own precision.
 *
 * A monthly bar covers October, not 1 October — printing the day would invite a reader to think the
 * bar was about that date. Days and weeks do open on a date, so they keep one.
 */
const bucketDate = (startsOn: string): string =>
    props.granularity === 'month'
        ? date(startsOn, { year: 'numeric', month: 'long' })
        : date(startsOn);

const series = computed(() => [
    { key: 'sourced', label: t('Sourced'), tone: 'bg-chart-1' },
    { key: 'qualified', label: t('Strong fits'), tone: 'bg-chart-4' },
]);

/**
 * What the keyboard reader hears when they arrow onto a bucket.
 *
 * The chart promises "use the arrow keys to read each period" in its label, and a `role=application`
 * region with an `aria-hidden` SVG has no other way to keep that promise: moving the crosshair
 * changes pixels, which announces nothing. This sentence goes in a polite live region instead.
 */
const activeSummary = computed(() => {
    const point = activePoint.value;

    if (point === null) {
        return '';
    }

    return t(':date — sourced :sourced, strong fits :qualified', {
        date: bucketDate(point.starts_on),
        sourced: number(point.sourced),
        qualified: number(point.qualified),
    });
});

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
                    {{ t(wording.eyebrow) }}
                </p>
                <h3
                    class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                >
                    {{ t(wording.heading) }}
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
                t(':summary — use the arrow keys to read each period', {
                    summary: t(wording.summary),
                })
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
                        <!--
                          The axis labels sit outside the plot on whichever side it starts, so
                          under RTL they move to the right of it and anchor the other way.
                        -->
                        <text
                            :x="isRtl ? plot.right + 8 : plot.left - 8"
                            :y="yAt(tick) + 4"
                            :text-anchor="isRtl ? 'start' : 'end'"
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

            <!--
              The spoken half of the crosshair. Polite, so it waits for a gap rather than cutting
              across whatever the reader is already hearing.
            -->
            <p class="sr-only" aria-live="polite">{{ activeSummary }}</p>

            <div
                v-if="activePoint"
                class="pointer-events-none absolute top-0 w-40 rounded-xl border border-border bg-popover p-3 text-xs shadow-md"
                :style="{ insetInlineStart: `${tooltipOffset}px` }"
            >
                <p class="font-mono text-xs text-muted-foreground">
                    {{
                        t(wording.tooltip, {
                            date: bucketDate(activePoint.starts_on),
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

        <!--
          The chart's text equivalent. `sr-only` goes on a wrapping div, never on the `<table>`
          itself: a table element hands `position` and `margin` to its wrapper box but `width`,
          `height`, `overflow` and `clip-path` to the grid box, so the `<caption>` escapes the clip
          and prints at full size, out of flow, over whatever is laid out around the card.
        -->
        <div class="sr-only">
            <table>
                <caption>
                    {{
                        t(wording.summary)
                    }}
                </caption>
                <thead>
                    <tr>
                        <th scope="col">{{ t(wording.column) }}</th>
                        <th scope="col">{{ t('Sourced') }}</th>
                        <th scope="col">{{ t('Strong fits') }}</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="point in points" :key="point.starts_on">
                        <th scope="row">
                            {{ bucketDate(point.starts_on) }}
                        </th>
                        <td>{{ number(point.sourced) }}</td>
                        <td>{{ number(point.qualified) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </figure>
</template>
