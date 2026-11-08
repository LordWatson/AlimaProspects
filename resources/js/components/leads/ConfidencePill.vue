<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useI18n } from '@/composables/useI18n';
import type { ConfidenceBand } from '@/types';

/*
 * How much the evidence behind a lead is worth, as a pill (PL1).
 *
 * **Deliberately colourless.** Confidence is a statement about *our data*, not a verdict on the
 * company, so it carries no hue at all: one outline badge, the band in words, the figure in
 * tabular numerals. The temptation is a traffic light, and a traffic light here would read as
 * "this company is poor" when what it says is "we have not found much out yet" — the same mistake
 * `MeterBar`'s `neutral` tone exists to avoid for readiness, and the reason brand-tokens.md §2a
 * keeps Signal Orange decorative and `status.*` for actual states.
 *
 * **The band is in words, never only in a shade** (WCAG AA): "Low" is legible to a reader who
 * cannot tell two greys apart, and the number is there for anyone who wants the precision.
 *
 * **Null is not zero.** A lead the queue has not reached yet has no confidence, and this renders
 * "No reasons yet" rather than a zero — because a zero reads as a judgement that was made.
 */
const props = withDefaults(
    defineProps<{
        confidence: number | null;
        band: ConfidenceBand | null;
        /** `sm` for a list row, `md` for the detail panel's heading. */
        size?: 'sm' | 'md';
    }>(),
    { size: 'sm' },
);

const { t } = useI18n();

const bandLabel = computed(() => {
    switch (props.band) {
        case 'high':
            return t('High confidence');
        case 'medium':
            return t('Medium confidence');
        case 'low':
            return t('Low confidence');
        default:
            return t('No reasons yet');
    }
});

/** The one phrase a screen reader hears, so the band and the figure are not read as two facts. */
const spoken = computed(() =>
    props.confidence === null
        ? t('No reasons recorded for this lead yet')
        : t(':band — evidence score :score out of 100', {
              band: bandLabel.value,
              score: String(props.confidence),
          }),
);
</script>

<template>
    <!-- `role="img"` so the one spoken phrase is actually honoured: a name on a generic
         element may be ignored, and both children below are hidden, so without it the pill can
         announce nothing at all. 12px floor (brand-tokens.md §3) at both sizes. -->
    <Badge
        variant="outline"
        role="img"
        :class="size === 'md' ? 'gap-1.5 text-xs' : 'gap-1 text-xs'"
        :aria-label="spoken"
    >
        <span aria-hidden="true">{{ bandLabel }}</span>
        <bdi
            v-if="confidence !== null"
            class="text-muted-foreground tabular-nums"
            aria-hidden="true"
            >{{ confidence }}</bdi
        >
    </Badge>
</template>
