<script setup lang="ts">
import { Check } from '@lucide/vue';
import type { BadgeVariants } from '@/components/ui/badge';
import { Badge } from '@/components/ui/badge';

/*
 * One filter value, on or off.
 *
 * The same chip had been written out seven times in the leads filter bar, each copy carrying its
 * own `aria-pressed`, its own focus ring and its own variant switch. It is one component now, and
 * it fixes the thing every copy had in common: **a chip that was on differed from one that was off
 * only by colour.** A tick goes in when it is on (WCAG 1.4.1), so the state survives a monochrome
 * screen, a colour-blind reader and a photocopier.
 */
const props = withDefaults(
    defineProps<{
        pressed: boolean;
        /** The visible label, already translated. */
        label: string;
        /**
         * What a screen reader should hear instead, where the visible label is meaningless on its
         * own — a bare grade letter, say.
         */
        srLabel?: string | null;
        /** `brand` for a value the member chose, the rest for decorative taxonomy. */
        variant?: BadgeVariants['variant'];
        /** DM Mono, for a letter or a figure. */
        mono?: boolean;
        dataTest?: string | null;
    }>(),
    {
        srLabel: null,
        variant: 'brand',
        mono: false,
        dataTest: null,
    },
);

const emit = defineEmits<{ toggle: [] }>();
</script>

<template>
    <button
        type="button"
        :aria-pressed="props.pressed"
        class="rounded-full transition-transform focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none active:scale-95"
        :data-test="props.dataTest ?? undefined"
        @click="emit('toggle')"
    >
        <Badge
            :variant="props.pressed ? props.variant : 'outline'"
            :class="[
                props.mono ? 'font-mono' : '',
                props.pressed ? '' : 'border-input hover:bg-accent',
            ]"
            dir="auto"
        >
            <Check v-if="props.pressed" class="size-3" aria-hidden="true" />
            <span v-if="props.srLabel" class="sr-only">{{
                props.srLabel
            }}</span>
            <span :aria-hidden="props.srLabel ? 'true' : undefined">{{
                props.label
            }}</span>
        </Badge>
    </button>
</template>
