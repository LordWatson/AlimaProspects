<script setup lang="ts">
import { computed } from 'vue';
import type { HTMLAttributes } from 'vue';
import { cn } from '@/lib/utils';

/*
 * One value against its scale, as a bar.
 *
 * Wherever the product prints a weight or a score as a bare figure — the ideal-customer profile's
 * criteria, the score breakdown's "Weight 70" column — the figure alone makes the reader do the
 * comparison. A bar does it for them: which criteria carry this profile, and which are decoration,
 * is a glance rather than a column of numbers read in order.
 *
 * It is a plain `div`, not an SVG: a bar is a box of a given width, and a box mirrors under RTL by
 * itself. The figure beside it stays, because a bar is a comparison and a number is a fact; and
 * the whole thing carries one spoken phrase rather than being read as decoration.
 *
 * Tones are states, not decoration: `success` for a criterion that matched, `neutral` (Ink 60) for
 * one that did not — a criterion a lead fails is not an error (brand-tokens.md §7.7) — and `brand`
 * for a plain magnitude with no verdict attached.
 */
const props = withDefaults(
    defineProps<{
        value: number;
        max?: number;
        tone?: 'brand' | 'success' | 'neutral' | 'warning';
        /** The phrase a screen reader hears; the bar itself is hidden from it. */
        label?: string | null;
        width?: 'sm' | 'md' | 'full';
        /**
         * The value **changes while the bar is on screen** — a discovery run's progress, pushed
         * over Reverb.
         *
         * A static `sr-only` phrase is read once and never again, so a bar that climbs from 10 to
         * 90 says nothing after the first glance. `live` makes it a real `progressbar` instead,
         * which assistive technology tracks on its own. A bar over a value that cannot change (a
         * criterion's weight) stays a plain mark with one spoken phrase: a progress bar that never
         * progresses is noise.
         */
        live?: boolean;
        class?: HTMLAttributes['class'];
    }>(),
    {
        max: 100,
        tone: 'brand',
        label: null,
        width: 'md',
        live: false,
        class: '',
    },
);

const fraction = computed(() => {
    if (props.max <= 0) return 0;

    return Math.min(1, Math.max(0, props.value / props.max));
});

const fill = computed(
    () =>
        ({
            brand: 'bg-brand',
            success: 'bg-success',
            // Not Ink 60: §7.7's figure for it is measured against the **card**, and this
            // bar sits on the border token, where Ink 60 is 2.3:1 on the dark track —
            // under the 3:1 non-text minimum. Muted is 3.7:1 light and 5.4:1 dark.
            neutral: 'bg-muted-foreground',
            warning: 'bg-warning',
        })[props.tone],
);

const track = computed(
    () =>
        ({
            sm: 'w-14',
            md: 'w-24',
            full: 'w-full',
        })[props.width],
);
</script>

<template>
    <span
        :class="cn('inline-flex items-center', props.class)"
        :role="props.live ? 'progressbar' : undefined"
        :aria-valuenow="props.live ? props.value : undefined"
        :aria-valuemin="props.live ? 0 : undefined"
        :aria-valuemax="props.live ? props.max : undefined"
        :aria-label="props.live ? (props.label ?? undefined) : undefined"
    >
        <span v-if="props.label && !props.live" class="sr-only">{{
            props.label
        }}</span>
        <span
            :class="cn('h-1.5 overflow-hidden rounded-full bg-border', track)"
            aria-hidden="true"
        >
            <span
                class="duration-base block h-full rounded-full transition-[width] ease-brand"
                :class="fill"
                :style="{ width: `${fraction * 100}%` }"
            />
        </span>
    </span>
</template>
