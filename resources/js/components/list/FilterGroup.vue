<script setup lang="ts">
/*
 * A named set of filter chips — a real `<fieldset>` with a real `<legend>`, so the group's name is
 * part of the structure rather than a paragraph that happens to sit above it.
 *
 * `hint` is for a rule the chips cannot show on their own: two tags mean *both* tags, which nobody
 * guesses from two chips side by side.
 */
const props = withDefaults(
    defineProps<{
        /** The group's name, already translated. */
        legend: string;
        hint?: string | null;
    }>(),
    { hint: null },
);
</script>

<template>
    <fieldset class="space-y-2">
        <legend
            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
        >
            {{ props.legend }}
        </legend>
        <div class="flex flex-wrap gap-1.5">
            <slot />
        </div>
        <p v-if="props.hint" class="text-xs text-muted-foreground">
            {{ props.hint }}
        </p>
    </fieldset>
</template>
