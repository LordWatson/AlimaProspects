<script setup lang="ts">
import type { Component } from 'vue';

/*
 * One part of a form, on its own card.
 *
 * A long form is easier to fill in when it is visibly a few short ones, so each section carries a
 * number as well as a name: the number is what tells somebody halfway down that there are two
 * sections left, and it is what the error summary can point at. The number sits in a Deep Teal
 * tinted square set in DM Mono — the same device the page hero uses for the screen's own index,
 * one level quieter.
 *
 * `aside` is for the one thing a section needs to say that is not a field: the compliance note on
 * the contact section, the "already using these tags" row. It sits at the end of the section
 * rather than between the fields, so the fields stay a single rhythm.
 */
const props = withDefaults(
    defineProps<{
        /** "01", "02" — the section's place in the form. */
        step?: string | null;
        /** Section name, already translated. */
        title: string;
        description?: string | null;
        icon?: Component | null;
        /** Anchor, so the error summary and a deep link can reach this section. */
        id?: string | null;
    }>(),
    { step: null, description: null, icon: null, id: null },
);
</script>

<template>
    <section
        :id="props.id ?? undefined"
        class="scroll-mt-6 space-y-5 rounded-2xl border border-border bg-card p-6"
    >
        <header class="flex items-start gap-3">
            <!-- Solid Deep Teal rather than `text-brand` on a 10% tint of itself: that
                 combination is 4.3:1 on the dark card, under AA at this size, because the dark
                 brand token is still PENDING (brand-tokens.md §7.10). Cream on Teal is 7.5:1
                 light and 5.5:1 dark, and it reads more like a step marker besides. -->
            <span
                v-if="props.step"
                class="flex size-7 shrink-0 items-center justify-center rounded-sm bg-brand font-mono text-xs text-brand-foreground tabular-nums"
                aria-hidden="true"
                dir="ltr"
            >
                {{ props.step }}
            </span>
            <component
                v-else-if="props.icon"
                :is="props.icon"
                class="mt-0.5 size-5 shrink-0 text-brand"
                aria-hidden="true"
            />
            <div class="min-w-0 space-y-1">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ props.title }}
                </h2>
                <p
                    v-if="props.description"
                    class="text-sm text-muted-foreground"
                >
                    {{ props.description }}
                </p>
            </div>
        </header>

        <div class="space-y-5">
            <slot />
        </div>

        <slot name="aside" />
    </section>
</template>
