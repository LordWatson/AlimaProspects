<script setup lang="ts">
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ActiveFilterChip } from '@/types';

/*
 * What is being filtered out, said once, in words, with a way to undo each part.
 *
 * This is the piece the leads list was missing. The filters lived in a panel of chips: once the
 * panel was scrolled past — or collapsed — a member looking at eleven rows had no way of knowing
 * whether eleven was the answer or the leftovers of a filter they set last Tuesday and a saved
 * view they forgot. "No leads match" with no visible cause is the single most common way a list
 * screen lies to somebody.
 *
 * Each chip names its **group** as well as its value, because "A" and "Enriched" and "Dubai" are
 * not self-explanatory side by side, and removes just itself. Clearing everything is a separate,
 * deliberate button.
 */
const props = defineProps<{
    chips: ActiveFilterChip[];
}>();

const emit = defineEmits<{
    remove: [chip: ActiveFilterChip];
    clear: [];
}>();

const { t } = useI18n();
</script>

<template>
    <div
        v-if="props.chips.length"
        class="flex flex-wrap items-center gap-2"
        data-test="active-filters"
    >
        <p
            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
        >
            {{ t('Filtering by') }}
        </p>

        <ul class="flex flex-wrap items-center gap-1.5">
            <li v-for="chip in props.chips" :key="chip.id">
                <span
                    class="inline-flex items-center gap-1.5 rounded-full bg-brand/10 py-1 ps-3 pe-1 text-xs text-foreground"
                >
                    <!-- The tint carries the brand; the words stay `text-foreground`.
                         `text-brand` on a 10% tint of itself is 4.3:1 on the dark card
                         (the dark brand token is still PENDING, brand-tokens.md §7.10),
                         and a dimmed `text-brand/70` was 3.4:1 even in light — while a
                         filter's group name is information, not decoration. It is the
                         same rule §7f.3 already applies to the status tints. -->
                    <span dir="auto">
                        {{ chip.group }}
                        <span class="font-semibold">{{ chip.label }}</span>
                    </span>
                    <button
                        type="button"
                        class="flex size-5 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-brand/15 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="
                            t('Stop filtering by :group :value', {
                                group: chip.group,
                                value: chip.label,
                            })
                        "
                        :data-test="`remove-filter-${chip.id}`"
                        @click="emit('remove', chip)"
                    >
                        <X class="size-3" aria-hidden="true" />
                    </button>
                </span>
            </li>
        </ul>

        <Button
            variant="ghost"
            size="sm"
            type="button"
            class="ms-auto"
            data-test="clear-filters"
            @click="emit('clear')"
        >
            <X class="size-4" />
            {{ t('Clear all') }}
        </Button>
    </div>
</template>
