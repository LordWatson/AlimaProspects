<script setup lang="ts">
import { ArrowDown, ArrowUp, ChevronsUpDown } from '@lucide/vue';
import { computed, inject } from 'vue';
import type { HTMLAttributes } from 'vue';
import { useI18n } from '@/composables/useI18n';
import { cn } from '@/lib/utils';
import { listDensityKey } from './context';

/*
 * One column header.
 *
 * A column a member can order by says so where the ordering is — on the column — rather than only
 * in a select above the table. The state goes on the `<th>` as `aria-sort`, which is what a screen
 * reader reads out; the arrow is for everyone else. An unsorted column shows its handle on hover
 * and focus, so the table is not a field of arrows.
 *
 * Ordering stays **server-side**: the header emits a key and the page re-requests the list, so the
 * URL remains the truth about what is on screen and a sorted list can be shared.
 */
const props = withDefaults(
    defineProps<{
        /** Column title, already translated. Also the accessible name of the sort control. */
        label: string;
        align?: 'start' | 'end' | 'center';
        /** Hide the column below this breakpoint. */
        hide?: 'sm' | 'md' | 'lg' | null;
        /** The ordering key this column maps to; omit for a column you cannot order by. */
        sort?: string | null;
        /** The ordering in force now. */
        active?: string | null;
        direction?: 'asc' | 'desc';
        class?: HTMLAttributes['class'];
    }>(),
    {
        align: 'start',
        hide: null,
        sort: null,
        active: null,
        direction: 'desc',
        class: '',
    },
);

const emit = defineEmits<{ sort: [key: string] }>();

const { t } = useI18n();

const density = inject(listDensityKey, undefined);

const isSorted = computed(
    () => props.sort !== null && props.sort === props.active,
);

const ariaSort = computed(() => {
    if (props.sort === null) return undefined;
    if (!isSorted.value) return 'none';

    return props.direction === 'asc' ? 'ascending' : 'descending';
});

const hideClass = computed(
    () =>
        ({
            sm: 'hidden sm:table-cell',
            md: 'hidden md:table-cell',
            lg: 'hidden lg:table-cell',
        })[props.hide ?? 'sm'],
);

const alignClass = computed(
    () =>
        ({
            start: 'text-start',
            end: 'text-end',
            center: 'text-center',
        })[props.align],
);

const padding = computed(() =>
    density?.value === 'compact' ? 'h-9 px-4' : 'h-11 px-4',
);

const icon = computed(() => {
    if (!isSorted.value) return ChevronsUpDown;

    return props.direction === 'asc' ? ArrowUp : ArrowDown;
});

/** What the next click does — a tooltip for the mouse, never the only clue. */
const hint = computed(() => {
    if (props.sort === null) return undefined;

    if (!isSorted.value) return t('Order by :column', { column: props.label });

    return props.direction === 'asc'
        ? t('Ordered by :column, lowest first — switch', {
              column: props.label,
          })
        : t('Ordered by :column, highest first — switch', {
              column: props.label,
          });
});
</script>

<template>
    <th
        scope="col"
        :aria-sort="ariaSort"
        :class="
            cn(
                // `text-muted-foreground` is 4.8:1 on the canvas but only 4.47:1 on the
                // opaque header band, which is under AA. The band had to become opaque
                // for the header to be sticky, so the text moved instead.
                'border-b border-border bg-accent align-middle font-mono text-xs font-medium tracking-eyebrow text-foreground/80 uppercase rtl:tracking-normal',
                padding,
                alignClass,
                props.hide ? hideClass : '',
                props.class,
            )
        "
    >
        <button
            v-if="props.sort"
            type="button"
            class="group inline-flex max-w-full items-center gap-1.5 rounded-sm uppercase transition-colors hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            :class="isSorted ? 'text-foreground' : ''"
            :title="hint"
            :data-test="`sort-${props.sort}`"
            @click="emit('sort', props.sort)"
        >
            <span class="truncate">
                <slot>{{ props.label }}</slot>
            </span>
            <!-- Vertical arrows are never mirrored under RTL: up is up in both scripts. -->
            <component
                :is="icon"
                class="size-3.5 shrink-0 transition-opacity"
                :class="
                    isSorted
                        ? ''
                        : 'opacity-60 group-hover:opacity-100 group-focus-visible:opacity-100'
                "
                aria-hidden="true"
            />
        </button>
        <template v-else>
            <slot>{{ props.label }}</slot>
        </template>
    </th>
</template>
