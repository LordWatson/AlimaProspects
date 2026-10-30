<script setup lang="ts">
import { ArrowRight, Lock } from '@lucide/vue';
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { FunnelStep } from '@/types';

/*
 * The prospecting funnel: sourced → strong fits → contacted → replies.
 *
 * Two things it refuses to do. It never draws an **unavailable** step as a zero — Outreach
 * (Phase 11) owns contacts and replies, and "we aren't tracking this yet" is a different claim
 * from "nobody replied"; those steps come through greyed, padlocked and captioned. And it only
 * prints a conversion rate between two steps that both have numbers, because a percentage of an
 * unknown is noise.
 *
 * Bars are proportional to the **first** step, which is what makes a funnel readable as a funnel.
 */
const props = defineProps<{ steps: FunnelStep[] }>();

const { t } = useI18n();
const { number, percent } = useFormat();

const top = computed(() => props.steps[0]?.value ?? 0);

const widthOf = (step: FunnelStep): string => {
    if (!step.available || step.value === null || top.value === 0) {
        return '0%';
    }

    return step.value === 0
        ? '0%'
        : `${Math.max(2, (step.value / top.value) * 100)}%`;
};

/** The drop from the step before, when both ends of the comparison are real numbers. */
const conversionInto = (index: number): string | null => {
    if (index === 0) {
        return null;
    }

    const previous = props.steps[index - 1];
    const step = props.steps[index];

    if (
        !previous?.available ||
        !step?.available ||
        previous.value === null ||
        step.value === null ||
        previous.value === 0
    ) {
        return null;
    }

    return percent(step.value / previous.value);
};
</script>

<template>
    <ol
        class="grid gap-px overflow-hidden rounded-3xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-4"
    >
        <li
            v-for="(step, index) in steps"
            :key="step.key"
            class="relative flex flex-col gap-4 bg-card p-5 sm:p-6"
            :class="{ 'text-muted-foreground': !step.available }"
        >
            <div class="flex items-start justify-between gap-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t(step.label) }}
                </p>
                <Lock
                    v-if="!step.available"
                    class="size-3.5 shrink-0 text-muted-foreground"
                    :aria-label="t('Not tracked yet')"
                />
            </div>

            <!-- An em dash, never a zero, when there is nothing to count. -->
            <p
                class="text-4xl font-bold tracking-tight tabular-nums sm:text-5xl"
                :class="{ 'text-muted-foreground/60': !step.available }"
            >
                <template v-if="step.available && step.value !== null">{{
                    number(step.value)
                }}</template>
                <template v-else>&mdash;</template>
            </p>

            <div
                class="h-2.5 w-full overflow-hidden rounded-sm bg-muted"
                role="img"
                :aria-label="
                    step.available && step.value !== null
                        ? t(':label: :value', {
                              label: t(step.label),
                              value: number(step.value),
                          })
                        : t(':label: not tracked yet', { label: t(step.label) })
                "
            >
                <div
                    class="h-full rounded-e-sm bg-chart-2 transition-[width] duration-250"
                    :style="{ width: widthOf(step) }"
                />
            </div>

            <p class="text-sm text-muted-foreground">{{ t(step.caption) }}</p>

            <p
                v-if="conversionInto(index)"
                class="flex items-center gap-1.5 font-mono text-xs text-brand tabular-nums"
            >
                <ArrowRight
                    class="size-3.5 rtl:rotate-180"
                    aria-hidden="true"
                />
                {{
                    t(':rate of the step before', {
                        rate: conversionInto(index) ?? '',
                    })
                }}
            </p>
        </li>
    </ol>
</template>
