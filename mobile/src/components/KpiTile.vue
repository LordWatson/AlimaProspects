<script setup lang="ts">
import { computed } from 'vue';
import type { Kpi } from '@shared/types';
import Sparkline from '@/components/Sparkline.vue';
import { formatDelta, formatMoney, formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/*
 * One headline figure from `GET /reports/dashboard`.
 *
 * A stat tile rather than a chart, because the data's job here is a single headline — the number
 * is the message and the sparkline is context, not the other way round.
 *
 * Two things the server's shape obliges:
 *   - `label`, `caption` and `delta_label` are **English translation keys**, never finished
 *     strings (`shared/types/reporting.ts`). They go through `t()`.
 *   - `value` is a plain integer and money arrives in **minor units**, with `currency` beside it.
 *     `format` says which.
 *
 * The delta is never colour alone: an arrow glyph and the server's own wording carry it, so it
 * reads for someone who cannot distinguish the green from the red, and in forced-colors mode.
 */
const props = defineProps<{ kpi: Kpi }>();

const value = computed(() =>
    props.kpi.format === 'money'
        ? formatMoney(props.kpi.value, props.kpi.currency)
        : formatNumber(props.kpi.value),
);

/*
 * The tiles where a rise is good news.
 *
 * The server sends a delta and no polarity, so colouring every rise green is an assumption —
 * and a wrong one the first time a "lower is better" figure grows a delta. `ai_spend` is exactly
 * that tile, and it has no delta today; the day it gets one, green would mean "you spent more".
 *
 * So the knowledge lives here, explicitly, and anything not named stays in neutral ink with the
 * arrow and the server's own wording carrying the direction. Add a key only when a rise on that
 * tile is unambiguously good.
 */
const higherIsBetter = new Set(['leads', 'strong_fits']);

const delta = computed(() => {
    const change = props.kpi.delta;

    if (change === null) {
        return null;
    }

    const rose = change > 0;
    const fell = change < 0;
    const coloured = higherIsBetter.has(props.kpi.key);

    return {
        text: formatDelta(change),
        direction:
            !coloured || (!rose && !fell) ? 'flat' : rose ? 'up' : 'down',
        glyph: rose ? '▲' : fell ? '▼' : '–',
        label: props.kpi.delta_label === null ? null : t(props.kpi.delta_label),
    };
});

const sparkLabel = computed(() =>
    t(':label over recent weeks: :values', {
        label: t(props.kpi.label),
        values: props.kpi.spark.map((n) => formatNumber(n)).join(', '),
    }),
);
</script>

<template>
    <div class="tile">
        <p class="alima-eyebrow tile__label">{{ t(kpi.label) }}</p>

        <p class="tile__value">{{ value }}</p>

        <p class="tile__caption">{{ t(kpi.caption) }}</p>

        <p
            v-if="delta"
            class="tile__delta"
            :class="`tile__delta--${delta.direction}`"
        >
            <span aria-hidden="true">{{ delta.glyph }}</span>
            <span>{{ delta.text }}</span>
            <span v-if="delta.label" class="tile__delta-label">{{
                delta.label
            }}</span>
        </p>

        <Sparkline
            v-if="kpi.spark.length > 1"
            :values="kpi.spark"
            :label="sparkLabel"
            class="tile__spark"
        />
    </div>
</template>

<style scoped>
.tile {
    background: var(--alima-bg-surface);
    border: 1px solid var(--alima-border);
    border-radius: var(--alima-radius-md);
    padding: 14px;
    display: flex;
    flex-direction: column;
    gap: 2px;
    box-shadow: var(--alima-shadow-sm);
    /* A grid item defaults to `min-width: auto`, so a long value pushes the tile wider than its
       column instead of wrapping — and these are half-width tiles (~160px) against figures like
       "د.إ 12,345" at 110% under RTL. */
    min-width: 0;
}

.tile__label {
    margin: 0 0 4px;
}

/* The hero number. Tabular figures so a grid of tiles does not jitter as values change, and
   a clamp so a six-figure currency amount shrinks rather than overflowing. */
.tile__value {
    margin: 0;
    font-size: clamp(1.25rem, 7vw, 1.75rem);
    overflow-wrap: anywhere;
    font-weight: 600;
    line-height: 1.1;
    letter-spacing: -0.01em;
    font-variant-numeric: tabular-nums;
    color: var(--alima-text-primary);
}

html[dir='rtl'] .tile__value {
    letter-spacing: 0;
}

.tile__caption {
    margin: 0;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--alima-text-muted);
}

.tile__delta {
    display: flex;
    align-items: baseline;
    gap: 4px;
    margin: 6px 0 0;
    font-size: 0.75rem;
    font-weight: 500;
    font-variant-numeric: tabular-nums;
}

/* The status ramp, never Signal Orange (CLAUDE.md §2a). A flat delta is neither good nor bad, so
   it stays in muted ink rather than borrowing a status colour to say "nothing happened". */
.tile__delta--up {
    color: var(--alima-status-success);
}

.tile__delta--down {
    color: var(--alima-status-error);
}

.tile__delta--flat {
    color: var(--alima-text-muted);
}

.tile__delta-label {
    color: var(--alima-text-muted);
    font-weight: 400;
}

.tile__spark {
    margin-top: 10px;
}
</style>
