<script setup lang="ts">
import { useI18n } from '@/composables/useI18n';

/*
 * The form's actions, kept within reach.
 *
 * The two long forms put "Add this lead" and "Save profile" at the very bottom of a four-section
 * page, which means that on a laptop the save button is below the fold from the moment the screen
 * loads, and on a phone it is a thumb-scroll away from wherever the member is actually typing. The
 * bar is sticky instead: it sits at the foot of the scrolling `<main>`, bleeds to its edges, and
 * carries the only other thing somebody mid-form wants to know — whether there is anything
 * unsaved.
 *
 * The state line is deliberately not a status colour. "Unsaved changes" is not a warning and not
 * an error; it is the normal condition of a form being filled in, and spending the amber on it is
 * how a real warning stops being noticed (brand-tokens.md §7f.5 makes the same point about an
 * override badge). The dot is Deep Teal and the words are muted.
 */
const props = withDefaults(
    defineProps<{
        /** There is something to lose — usually Inertia's `form.isDirty`. */
        dirty?: boolean;
        /** A save is in flight. */
        processing?: boolean;
        /** Override the state line entirely (a wizard step, say). Already translated. */
        status?: string | null;
        /**
         * Bleed to the edges of the scrolling `<main>`, which is right for a full-width form.
         * A **centred** form (the ideal-customer editor is `max-w-3xl mx-auto`) passes `false`:
         * the negative margins would otherwise make the bar 32px wider than the cards above it,
         * which reads as a mistake rather than as a full-bleed bar.
         */
        bleed?: boolean;
    }>(),
    { dirty: false, processing: false, status: null, bleed: true },
);

const { t } = useI18n();
</script>

<template>
    <div
        class="sticky bottom-0 z-20 border-t border-border bg-background/95 py-4 backdrop-blur"
        :class="props.bleed ? '-mx-4 px-4 sm:-mx-6 sm:px-6' : ''"
    >
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p
                class="flex items-center gap-2 font-mono text-xs text-muted-foreground"
                data-test="form-state"
            >
                <span
                    v-if="props.dirty && !props.processing"
                    class="size-2 rounded-full bg-brand"
                    aria-hidden="true"
                />
                <span v-if="props.status">{{ props.status }}</span>
                <span v-else-if="props.processing">{{ t('Saving…') }}</span>
                <span v-else-if="props.dirty">{{ t('Unsaved changes') }}</span>
                <span v-else>{{ t('Nothing to save yet') }}</span>
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <slot />
            </div>
        </div>
    </div>
</template>
