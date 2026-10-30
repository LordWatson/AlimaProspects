<script setup lang="ts">
import type { HTMLAttributes } from 'vue';
import { computed } from 'vue';
import { cn } from '@/lib/utils';

/*
 * A determinate progress bar for a job the team is waiting on — a Lead discovery run, a background
 * check, a signal watch. This is the markup that `lead-sourcing/Show.vue` and
 * `business-intel/Review.vue` had already hand-rolled identically; the Signals feed made it a third
 * copy, so it is a component now.
 *
 * `label` is required and must arrive translated: a bar with no accessible name is a decorative
 * rectangle to a screen reader, and `aria-valuenow` alone doesn't say what is progressing. The
 * *step* text ("Searching the news") stays at the call site in its own live region — the label
 * names the task once, while the step changes as the job moves, and announcing them together would
 * repeat the task name on every stage.
 *
 * The fill uses `brand`, not `orange`: Signal Orange is decorative only and never carries state
 * (`brand-tokens.md` §2). Width grows from the inline start, so it fills right-to-left under RTL
 * with no extra work.
 */
const props = defineProps<{
    /** 0–100. Clamped, because it usually arrives from a broadcast rather than from our own code. */
    value: number;
    /** Already-translated accessible name for what is progressing. */
    label: string;
    class?: HTMLAttributes['class'];
}>();

const percent = computed(() => {
    // A broadcast can carry anything; `Math.round(undefined)` is NaN, and `aria-valuenow="NaN"`
    // is worse than an empty bar.
    if (!Number.isFinite(props.value)) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round(props.value)));
});
</script>

<template>
    <div
        data-slot="progress"
        :class="
            cn(
                'h-1.5 w-full overflow-hidden rounded-full bg-accent',
                props.class,
            )
        "
        role="progressbar"
        aria-valuemin="0"
        aria-valuemax="100"
        :aria-valuenow="percent"
        :aria-label="label"
    >
        <div
            class="h-full rounded-full bg-brand transition-all duration-300 motion-reduce:transition-none"
            :style="{ width: `${percent}%` }"
        />
    </div>
</template>
