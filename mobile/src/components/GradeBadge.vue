<script setup lang="ts">
import { computed } from 'vue';
import type { LeadScoreSummary } from '@shared/types';
import { t } from '@/lib/i18n';

/*
 * A lead's ICP-fit grade, A to D.
 *
 * Not a status, so not the status ramp: a D is not an error and an A is not a success — this is a
 * four-step scale of one thing, so it is drawn as one hue stepped by strength (brand-tokens.md
 * §7.7: Teal is the single-series mark). The letter is always present, so the grade never depends
 * on telling four tints apart.
 *
 * An **excluded** lead is the exception and is drawn as a state, because that is what it is: the
 * ICP rules ruled it out, whatever it scored.
 */
const props = defineProps<{ score: LeadScoreSummary | null | undefined }>();

const grade = computed(() => props.score?.grade ?? null);
const excluded = computed(() => props.score?.excluded === true);
</script>

<template>
    <span
        v-if="score"
        class="grade"
        :class="excluded ? 'grade--excluded' : `grade--${grade?.toLowerCase()}`"
        :title="t(score.grade_label)"
    >
        <span class="grade__letter alima-ltr">{{
            excluded ? '—' : grade
        }}</span>
        <span class="alima-sr-only">
            {{
                excluded
                    ? t('Excluded by your ideal customer rules')
                    : t(':label, score :score', {
                          label: t(score.grade_label),
                          score: score.score,
                      })
            }}
        </span>
    </span>
</template>

<style scoped>
.grade {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 26px;
    height: 26px;
    padding: 0 6px;
    border-radius: 9999px;
    font-family: var(--alima-font-mono);
    font-size: 0.8125rem;
    font-weight: 500;
    flex: none;
}

/* One hue, four steps. Every pairing keeps the letter at 4.5:1 or better on its own chip. */
.grade--a {
    background: var(--alima-teal);
    color: var(--alima-cream);
}

.grade--b {
    background: var(--alima-teal-60);
    color: var(--alima-ink);
}

.grade--c {
    background: var(--alima-teal-20);
    color: var(--alima-ink);
}

.grade--d {
    background: var(--alima-cream-sunken);
    color: var(--alima-text-secondary);
}

.grade--excluded {
    background: transparent;
    border: 1px dashed var(--alima-border-control);
    color: var(--alima-text-muted);
}

.alima-dark .grade--a {
    background: var(--alima-teal-60);
    color: var(--alima-ink);
}

.alima-dark .grade--b {
    background: var(--alima-teal-40);
    color: var(--alima-ink);
}

.alima-dark .grade--c {
    background: var(--alima-border);
    color: var(--alima-text-primary);
}

.alima-dark .grade--d {
    background: var(--alima-cream-sunken);
    color: var(--alima-text-muted);
}
</style>
