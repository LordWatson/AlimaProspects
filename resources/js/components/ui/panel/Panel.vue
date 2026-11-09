<script setup lang="ts">
import { type HTMLAttributes, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { useFormat } from '@/composables/useFormat';
import { cn } from '@/lib/utils';

/*
 * The one section shell for a record screen: a cream card, a DM Mono eyebrow heading, and the
 * panel's actions in the header at the **end** edge.
 *
 * Where the actions go is the reason this component exists rather than a style it happens to
 * have. The lead record had five different section shells and two different places to put an
 * "Add…" button — the contacts one below the list at the start edge, the tasks one above it at
 * the end edge — so a reader learned the layout again in every panel. A slot in one place makes
 * that impossible to get wrong in the next panel somebody writes.
 *
 * `emphasis` states the title as a heading rather than a label. At most **one** panel per screen
 * takes it: it is what gives a screen a lead instead of ten equal boxes (brand-tokens §7o).
 */
const props = withDefaults(
    defineProps<{
        class?: HTMLAttributes['class'];
        /** Already translated — a panel title is copy, and `t()` belongs to the caller. */
        title: string;
        /** Shown beside the title where the panel's content is a countable list. */
        count?: number | null;
        emphasis?: boolean;
    }>(),
    { count: null, emphasis: false },
);

const { number } = useFormat();

/*
 * A named region, so a screen reader can move between ten panels the way a sighted reader moves
 * between ten cards. Named *by its own heading* rather than by an `aria-label` repeating it — an
 * `aria-label` beside an identical heading says the same sentence twice.
 */
const headingId = useId();
</script>

<template>
    <section
        :class="
            cn(
                'rounded-2xl border border-border bg-card p-6',
                props.class,
            )
        "
        :aria-labelledby="headingId"
    >
        <header class="mb-4 space-y-2 border-b border-border pb-4">
            <div
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <h2
                        v-if="props.emphasis"
                        :id="headingId"
                        class="text-base font-semibold text-foreground rtl:font-bold"
                        dir="auto"
                    >
                        {{ props.title }}
                    </h2>
                    <!-- `title` because the heading truncates: a cut-off title had no way
                         to be read, and Arabic titles run longer than their Latin source. -->
                    <h2
                        v-else
                        :id="headingId"
                        class="truncate font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        dir="auto"
                        :title="props.title"
                    >
                        {{ props.title }}
                    </h2>

                    <!-- A figure, not a verdict: an outline badge in tabular numerals, never a
                         status colour (brand-tokens §7n.1). -->
                    <Badge
                        v-if="props.count !== null"
                        variant="outline"
                        class="shrink-0 font-mono tabular-nums"
                        dir="ltr"
                    >
                        {{ number(props.count) }}
                    </Badge>
                </div>

                <div
                    v-if="$slots.actions"
                    class="flex flex-wrap items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </div>

            <!-- A standing hint about the whole panel. Under the title rather than beside the
                 actions, where it was competing with the buttons for the same row. -->
            <p v-if="$slots.description" class="text-xs text-muted-foreground">
                <slot name="description" />
            </p>
        </header>

        <div class="space-y-4">
            <slot />
        </div>
    </section>
</template>
