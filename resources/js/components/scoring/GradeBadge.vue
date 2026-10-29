<script setup lang="ts">
import { computed } from 'vue';
import type { BadgeVariants } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import type { ScoreGrade } from '@/types';

/**
 * The A–D ICP-fit grade (Phase 9). The letter carries the meaning, so the badge never relies on
 * colour alone (WCAG AA); the tint uses the semantic status tokens — Signal Orange is decorative
 * only and is never a status colour (brand-tokens.md §2.1). A weak fit is a neutral outline rather
 * than an error: a lead that doesn't match isn't a fault.
 *
 * The badge is `role="img"` with a composed label, so a screen reader always gets the grade (and
 * its score) as one phrase rather than a bare letter.
 */
const props = defineProps<{
    grade: ScoreGrade;
    /** The blended 0–100 score, shown beside the letter when given. */
    score?: number | null;
    /** Spoken label for the badge; defaults to "Grade X". */
    label?: string | null;
}>();

const { t } = useI18n();

const variant = computed<BadgeVariants['variant']>(
    () =>
        (
            {
                A: 'success',
                B: 'info',
                C: 'warning',
                D: 'outline',
            } as const
        )[props.grade],
);

const spokenLabel = computed(() => {
    const base = props.label ?? t('Grade :grade', { grade: props.grade });

    return props.score == null
        ? base
        : t(':label, score :score', { label: base, score: props.score });
});
</script>

<template>
    <Badge
        :variant="variant"
        class="gap-1.5 font-mono tabular-nums"
        role="img"
        :aria-label="spokenLabel"
    >
        <span aria-hidden="true">{{ grade }}</span>
        <span
            v-if="score != null"
            class="text-xs opacity-80"
            aria-hidden="true"
        >
            {{ score }}
        </span>
    </Badge>
</template>
