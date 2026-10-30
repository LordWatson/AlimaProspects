<script setup lang="ts">
import { ArrowDownRight, ArrowRight, ArrowUpRight } from '@lucide/vue';
import { computed } from 'vue';
import SparkLine from '@/components/charts/SparkLine.vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { Kpi } from '@/types';

/*
 * One headline KPI: the number, what it is, how it moved, and the shape behind it.
 *
 * The delta is never colour-alone — it carries an arrow and a worded label (WCAG AA, and the
 * brand's own rule that Signal Orange is decorative). "Up" is not assumed to be good, so the
 * arrow states direction and the status tokens are left for actual states; a rise is simply
 * stated, not celebrated in green.
 */
const props = defineProps<{ kpi: Kpi; tone?: string }>();

const { t } = useI18n();
const { number, currency } = useFormat();

const value = computed(() =>
    props.kpi.format === 'money'
        ? currency(props.kpi.value, props.kpi.currency ?? 'GBP')
        : number(props.kpi.value),
);

const delta = computed(() => props.kpi.delta);

const deltaIcon = computed(() => {
    const change = delta.value ?? 0;

    if (change === 0) {
        return ArrowRight;
    }

    return change > 0 ? ArrowUpRight : ArrowDownRight;
});

const deltaText = computed(() => {
    const change = delta.value ?? 0;

    if (change === 0) {
        return t('No change');
    }

    return `${change > 0 ? '+' : '−'}${number(Math.abs(change))}`;
});
</script>

<template>
    <div class="flex flex-col gap-4 bg-card p-5 sm:p-6">
        <p
            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
        >
            {{ t(kpi.label) }}
        </p>

        <div class="flex items-end justify-between gap-3">
            <p
                class="text-4xl font-bold tracking-tight tabular-nums sm:text-5xl"
            >
                {{ value }}
            </p>
            <SparkLine
                v-if="kpi.spark.length > 1"
                :values="kpi.spark"
                :tone="tone ?? 'text-chart-1'"
                class="w-20 shrink-0 sm:w-24"
            />
        </div>

        <div class="space-y-1.5">
            <p
                v-if="delta !== null"
                class="flex items-center gap-1.5 text-sm font-medium tabular-nums"
            >
                <component
                    :is="deltaIcon"
                    class="size-4 shrink-0 text-muted-foreground rtl:-scale-x-100"
                    aria-hidden="true"
                />
                <span>{{ deltaText }}</span>
                <span v-if="kpi.delta_label" class="text-muted-foreground">{{
                    t(kpi.delta_label)
                }}</span>
            </p>
            <p class="text-sm text-muted-foreground">{{ t(kpi.caption) }}</p>
        </div>
    </div>
</template>
