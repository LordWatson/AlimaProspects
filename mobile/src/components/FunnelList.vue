<script setup lang="ts">
import { computed } from 'vue';
import type { FunnelStep } from '@shared/types';
import { formatNumber } from '@/lib/format';
import { t } from '@/lib/i18n';

/*
 * The prospecting funnel: sourced → graded → approached → replied.
 *
 * A labelled bar list rather than a funnel shape. The data's job is magnitude, bars encode
 * magnitude accurately, and a tapering funnel graphic distorts the very comparison it is drawn
 * to make (dataviz anti-patterns). Every row is directly labelled with its name and its figure,
 * so identity never depends on a colour and there is nothing for a legend to explain.
 *
 * One hue, scaled by magnitude — sequential, not categorical. These are stages of one quantity,
 * not four different things.
 *
 * `available: false` renders as an em dash, never as a zero. "We are not tracking this" and
 * "nobody replied" are different claims, and drawing the first as the second is a lie the screen
 * tells on the server's behalf (`shared/types/reporting.ts`).
 */
const props = defineProps<{ steps: FunnelStep[] }>();

const widest = computed(() =>
    Math.max(
        1,
        ...props.steps.map((step) => (step.available ? (step.value ?? 0) : 0)),
    ),
);

function share(step: FunnelStep): number {
    if (!step.available || step.value === null) {
        return 0;
    }

    return Math.round((step.value / widest.value) * 100);
}
</script>

<template>
    <ul class="funnel">
        <li v-for="step in steps" :key="step.key" class="funnel__row">
            <div class="funnel__head">
                <span class="funnel__label">{{ t(step.label) }}</span>
                <span class="funnel__value">
                    {{
                        step.available && step.value !== null
                            ? formatNumber(step.value)
                            : '—'
                    }}
                </span>
            </div>

            <div
                class="funnel__track"
                role="img"
                :aria-label="
                    step.available && step.value !== null
                        ? t(':label: :value', {
                              label: t(step.label),
                              value: formatNumber(step.value),
                          })
                        : t(':label: not tracked yet', { label: t(step.label) })
                "
            >
                <div
                    class="funnel__bar"
                    :style="{ width: share(step) + '%' }"
                ></div>
            </div>

            <p class="funnel__caption">{{ t(step.caption) }}</p>
        </li>
    </ul>
</template>

<style scoped>
.funnel {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 14px;
}

.funnel__head {
    display: flex;
    align-items: baseline;
    justify-content: space-between;
    gap: 8px;
}

.funnel__label {
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--alima-text-primary);
}

.funnel__value {
    font-family: var(--alima-font-mono);
    font-variant-numeric: tabular-nums;
    font-size: 0.9375rem;
    font-weight: 500;
    color: var(--alima-text-primary);
}

/* The track is a sunken cream; the fill is brand Teal at 8.1:1 on it. One hue, because this is
   one quantity at successive stages. */
.funnel__track {
    margin-top: 6px;
    height: 8px;
    border-radius: 9999px;
    background: var(--alima-cream-sunken);
    overflow: hidden;
}

.funnel__bar {
    height: 100%;
    /* 4px rounded data-end, anchored to the baseline at the start edge. */
    border-radius: 0 4px 4px 0;
    background: var(--alima-chart-teal);
    transition: width var(--alima-ease) 250ms;
}

html[dir='rtl'] .funnel__bar {
    border-radius: 4px 0 0 4px;
}

.funnel__caption {
    margin: 6px 0 0;
    font-size: 0.75rem;
    line-height: 1.4;
    color: var(--alima-text-muted);
}

@media (prefers-reduced-motion: reduce) {
    .funnel__bar {
        transition: none;
    }
}
</style>
