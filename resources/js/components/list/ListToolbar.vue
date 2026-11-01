<script setup lang="ts">
import { ChevronDown, SlidersHorizontal } from '@lucide/vue';
import { ref, useId } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import type { ActiveFilterChip } from '@/types';
import ActiveFilters from './ActiveFilters.vue';
import DensityToggle from './DensityToggle.vue';

/*
 * The bar above a list: search, ordering, the filters, and how many records are left.
 *
 * The change that matters here is that **the filters fold away.** The leads list used to open
 * with a 300-pixel panel of six chip groups above the first row — every filter the screen has, in
 * full, whether or not any of them were in use, on a laptop that then had room for four leads. A
 * list is for reading the list.
 *
 * So: one row of controls, always; the filters behind a disclosure that says how many are on; and
 * the ones actually in force summarised as removable chips, so folding the panel away never hides
 * them. The panel opens by itself when the screen arrives already filtered, because somebody who
 * followed a saved-view link should be able to see what it did.
 *
 * The record count is also a live region: filtering is a server round trip, and "2 leads match"
 * said out loud is the only way a screen-reader user learns that the chip they just pressed did
 * anything at all.
 */
const props = withDefaults(
    defineProps<{
        /** How many records match, as a finished phrase — the page owns the wording. */
        summary?: string | null;
        /** The filters in force, named for the summary row. Their count drives the disclosure. */
        chips?: ActiveFilterChip[];
        /** A request for this list is in flight. */
        busy?: boolean;
        showDensity?: boolean;
    }>(),
    {
        summary: null,
        chips: () => [],
        busy: false,
        showDensity: true,
    },
);

const emit = defineEmits<{
    removeFilter: [chip: ActiveFilterChip];
    clearFilters: [];
}>();

const { t } = useI18n();

const panelId = `list-filters-${useId()}`;

// Open on arrival when the screen is already filtered: a member who followed a shared link lands
// looking at a narrowed list, and the first question is always "narrowed by what?".
const open = ref(props.chips.length > 0);

// The label stays "Filters". `aria-expanded` is what says whether the panel is open, and a
// button whose name flips contradicts its own state for anybody reading both; the chevron
// is the visual half of the same thing.
</script>

<template>
    <section
        class="rounded-2xl border border-border bg-card"
        :aria-label="t('Filter and order this list')"
    >
        <div class="flex flex-wrap items-end gap-3 p-4">
            <div v-if="$slots.search" class="min-w-56 flex-1">
                <slot name="search" />
            </div>

            <slot name="sort" />

            <Button
                v-if="$slots.filters"
                variant="outline"
                type="button"
                :aria-expanded="open"
                :aria-controls="panelId"
                data-test="toggle-filters"
                @click="open = !open"
            >
                <SlidersHorizontal class="size-4" />
                {{ t('Filters') }}
                <Badge
                    v-if="props.chips.length > 0"
                    variant="brand"
                    class="font-mono tabular-nums"
                >
                    {{ props.chips.length }}
                </Badge>
                <ChevronDown
                    class="size-4 transition-transform"
                    :class="open ? 'rotate-180' : ''"
                    aria-hidden="true"
                />
            </Button>

            <DensityToggle v-if="props.showDensity" />

            <div v-if="$slots.actions" class="flex items-center gap-2">
                <slot name="actions" />
            </div>

            <p
                v-if="props.summary"
                class="ms-auto font-mono text-xs text-muted-foreground tabular-nums"
            >
                {{ props.summary }}
            </p>
        </div>

        <!-- Always mounted: a live region inserted with its text already in it is usually not
             announced, so the first filter change would go by in silence. Empty while a request
             is in flight, so the figure is read once — when it is true — rather than twice. -->
        <p class="sr-only" role="status" aria-live="polite">
            {{ props.busy ? '' : (props.summary ?? '') }}
        </p>

        <div v-if="props.chips.length" class="border-t border-border px-4 py-3">
            <ActiveFilters
                :chips="props.chips"
                @remove="emit('removeFilter', $event)"
                @clear="emit('clearFilters')"
            />
        </div>

        <div
            v-show="open"
            :id="panelId"
            class="border-t border-border p-4"
            data-test="filters-panel"
        >
            <slot name="filters" />
        </div>
    </section>
</template>
