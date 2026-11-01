<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from '@/composables/useI18n';
import type { ScoreGrade } from '@/types';

/*
 * The fit score as a figure you can read at a glance.
 *
 * The score screen used to set the number in a grey square: "68" out of a scale the member had to
 * remember, with no sense of whether 68 is most of the way or barely half. An arc answers that
 * before the number is read, which is the whole job of the panel at the top of that screen.
 *
 * Colour follows the **grade**, using the same semantic tokens as `GradeBadge` so a lead's grade
 * is one colour everywhere in the product — and a weak fit is a neutral Ink 60, never `error`: a
 * lead that does not match your profile is nobody's fault (brand-tokens.md §7.7). Signal Orange
 * appears nowhere near it; it is decorative only and never a state.
 *
 * The arc is drawn with `stroke-dasharray` on a circle rather than an arc path, so there is no
 * trigonometry to get wrong and no flip to think about under RTL — a ring is the same ring in both
 * directions, and the figure inside it is forced `ltr` because a score is a number, not a
 * sentence.
 */
const props = withDefaults(
    defineProps<{
        /** 0–100. */
        score: number;
        grade: ScoreGrade;
        /** The grade's name, already translated — read out with the figure. */
        gradeLabel?: string | null;
        size?: 'default' | 'lg';
    }>(),
    { gradeLabel: null, size: 'default' },
);

const { t } = useI18n();

const RADIUS = 42;
const CIRCUMFERENCE = 2 * Math.PI * RADIUS;

const clamped = computed(() => Math.min(100, Math.max(0, props.score)));

const dash = computed(
    () => `${(clamped.value / 100) * CIRCUMFERENCE} ${CIRCUMFERENCE}`,
);

// The same mapping as `GradeBadge`, because a grade that is green on one screen and teal on the
// next is two grades as far as a reader is concerned.
const stroke = computed(
    () =>
        ({
            A: 'stroke-success',
            B: 'stroke-info',
            C: 'stroke-warning',
            D: 'stroke-ink-60',
        })[props.grade],
);

const box = computed(() => (props.size === 'lg' ? 'size-32' : 'size-24'));
const figure = computed(() => (props.size === 'lg' ? 'text-4xl' : 'text-3xl'));

const spoken = computed(() =>
    props.gradeLabel
        ? t(':label — fit score :score out of 100', {
              label: props.gradeLabel,
              score: clamped.value,
          })
        : t('Fit score :score out of 100', { score: clamped.value }),
);
</script>

<template>
    <div
        class="relative shrink-0"
        :class="box"
        role="img"
        :aria-label="spoken"
        data-test="score-dial"
    >
        <svg
            viewBox="0 0 100 100"
            class="size-full -rotate-90"
            aria-hidden="true"
            focusable="false"
        >
            <!-- The track: the rest of the scale, so the arc has something to be a fraction of. -->
            <circle
                cx="50"
                cy="50"
                :r="RADIUS"
                fill="none"
                stroke-width="8"
                class="stroke-border"
            />
            <circle
                cx="50"
                cy="50"
                :r="RADIUS"
                fill="none"
                stroke-width="8"
                stroke-linecap="round"
                :stroke-dasharray="dash"
                :class="stroke"
            />
        </svg>
        <p
            class="absolute inset-0 flex items-center justify-center font-mono font-semibold text-foreground tabular-nums rtl:font-bold"
            :class="figure"
            aria-hidden="true"
            dir="ltr"
        >
            {{ clamped }}
        </p>
    </div>
</template>
