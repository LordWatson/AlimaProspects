<script setup lang="ts">
import { computed } from 'vue';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { BreakdownSlice, ScoreGrade } from '@/types';

/*
 * The A–D fit mix as one proportional bar.
 *
 * It borrows the **status** tokens from `GradeBadge` rather than the chart palette, because colour
 * has to follow the entity: a grade A pill and the grade A segment of this bar are the same thing,
 * and giving them different colours would make a member learn the scale twice. (The chart palette
 * is for series identity; a grade is a state.) Signal Orange is not in play — it is decorative
 * only, never a status.
 *
 * The legend under the bar is the key, and it is always present: each row pairs the swatch with
 * its **letter**, its count and its share, so the A–D identity is never carried by colour alone.
 * The segments themselves stay unlabelled — at a realistic mix the thin ones are nowhere near wide
 * enough for a letter, and a label that appears only on the wide segments is worse than none.
 * The 2px gaps between segments are surface-coloured, per the dataviz mark spec.
 */
const props = defineProps<{ slices: BreakdownSlice[] }>();

const { t } = useI18n();
const { number, percent } = useFormat();

const total = computed(() =>
    props.slices.reduce((sum, slice) => sum + slice.value, 0),
);

const tones: Record<ScoreGrade, string> = {
    A: 'bg-success',
    B: 'bg-info',
    C: 'bg-warning',
    // A weak fit is not an error — a lead that does not match is nobody's fault — so D is a
    // neutral ink tint rather than `destructive`, matching the badge's outline treatment.
    // Ink 60, not Ink 40: as a mark, Ink 40 is 2.7:1 on the card and fails the 3:1 non-text
    // minimum. Ink 60 is 4.8:1 on the light card and 3.2:1 on the dark one.
    D: 'bg-ink-60',
};

const toneOf = (key: string): string => tones[key as ScoreGrade] ?? 'bg-ink-60';

/** Segments with nothing in them are left out of the bar, but kept in the legend below it. */
const segments = computed(() =>
    props.slices
        .filter((slice) => slice.value > 0)
        .map((slice) => ({
            ...slice,
            share: total.value === 0 ? 0 : slice.value / total.value,
        })),
);
</script>

<template>
    <figure class="flex flex-col gap-5">
        <figcaption>
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('Fit') }}
            </p>
            <h3 class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold">
                {{ t('How well your leads match') }}
            </h3>
            <p class="mt-1 text-sm text-muted-foreground">
                {{
                    total === 0
                        ? t(
                              'Nothing scored yet — grades appear once scoring runs.',
                          )
                        : t(':total scored against your active profile', {
                              total: number(total),
                          })
                }}
            </p>
        </figcaption>

        <div
            v-if="total > 0"
            class="flex h-4 w-full gap-0.5 overflow-hidden rounded-sm"
        >
            <div
                v-for="segment in segments"
                :key="segment.key"
                class="h-full first:rounded-s-sm last:rounded-e-sm"
                :class="toneOf(segment.key)"
                :style="{ width: `${segment.share * 100}%` }"
                role="img"
                :aria-label="
                    t(':label: :value (:share)', {
                        label: t(segment.label),
                        value: number(segment.value),
                        share: percent(segment.share),
                    })
                "
            />
        </div>
        <div v-else class="h-4 w-full rounded-sm bg-muted" aria-hidden="true" />

        <!-- Legend. All four letters, zeros included: a mix with no D is a fact worth seeing. -->
        <dl class="grid grid-cols-2 gap-x-6 gap-y-3 sm:grid-cols-4">
            <div v-for="slice in slices" :key="slice.key" class="flex gap-2.5">
                <span
                    class="mt-1 size-2.5 shrink-0 rounded-sm"
                    :class="toneOf(slice.key)"
                    aria-hidden="true"
                />
                <div class="min-w-0">
                    <dt class="font-mono text-xs text-muted-foreground">
                        {{ slice.key }}
                    </dt>
                    <dd class="text-lg font-semibold tabular-nums">
                        {{ number(slice.value) }}
                        <span
                            v-if="total > 0"
                            class="text-xs font-normal text-muted-foreground"
                            >{{ percent(slice.value / total) }}</span
                        >
                    </dd>
                </div>
            </div>
        </dl>
    </figure>
</template>
