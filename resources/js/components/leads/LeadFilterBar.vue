<script setup lang="ts">
import { ArrowDown, ArrowUp, Search, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type {
    LeadFilterOptions,
    LeadFilterState,
    LeadSortDirection,
    LeadTag,
    PipelineStage,
    TeamMemberOption,
} from '@/types';

/*
 * The leads list's filter bar. It owns no list state: every change emits upward and the parent
 * re-requests the page from the server, so the URL is always the truth about what is on screen.
 *
 * Search is debounced — one request when the member stops typing, not one per keystroke.
 */
const props = defineProps<{
    filters: LeadFilterState;
    options: LeadFilterOptions;
    /** The team's own board, labels and people — all three are filters (Phase 10b). */
    stages: PipelineStage[];
    tags: LeadTag[];
    members: TeamMemberOption[];
    /** How many leads the current filters match, for the "clear" affordance's context. */
    total: number;
}>();

const emit = defineEmits<{
    change: [filters: Partial<LeadFilterState>];
    clear: [];
}>();

const { t } = useI18n();

const SEARCH_DEBOUNCE_MS = 350;

const search = ref(props.filters.search);
let searchTimer: ReturnType<typeof setTimeout> | undefined;

// Keep the box in step when the server sends different filters (a saved view was opened, or the
// member used the back button).
watch(
    () => props.filters.search,
    (value) => {
        if (value !== search.value) search.value = value;
    },
);

watch(search, (value) => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(
        () => emit('change', { search: value }),
        SEARCH_DEBOUNCE_MS,
    );
});

/** Toggle one value in a multi-select filter. */
function toggle<K extends 'grades' | 'sources' | 'enrichment_statuses'>(
    key: K,
    value: string,
): void {
    const current = props.filters[key] as string[];
    const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];

    emit('change', { [key]: next } as Partial<LeadFilterState>);
}

function isOn(
    key: 'grades' | 'sources' | 'enrichment_statuses',
    value: string,
): boolean {
    return (props.filters[key] as string[]).includes(value);
}

/** The same, for the filters whose values are ids (Phase 10b). */
function toggleId(key: 'stages' | 'tags' | 'assignees', value: number): void {
    const current = props.filters[key];
    const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];

    emit(
        'change',
        key === 'assignees'
            ? // Picking a person and "nobody" at once is a contradiction, so one clears the other.
              ({
                  assignees: next,
                  unassigned: false,
              } as Partial<LeadFilterState>)
            : ({ [key]: next } as Partial<LeadFilterState>),
    );
}

function isIdOn(key: 'stages' | 'tags' | 'assignees', value: number): boolean {
    return props.filters[key].includes(value);
}

function toggleUnassigned(): void {
    emit('change', {
        unassigned: !props.filters.unassigned,
        assignees: [],
    });
}

const directionLabel = computed(() =>
    props.filters.direction === 'asc'
        ? t('Sorted ascending — switch to descending')
        : t('Sorted descending — switch to ascending'),
);

const isFiltering = computed(
    () =>
        props.filters.search !== '' ||
        props.filters.grades.length > 0 ||
        props.filters.sources.length > 0 ||
        props.filters.enrichment_statuses.length > 0 ||
        props.filters.country !== '' ||
        props.filters.stages.length > 0 ||
        props.filters.tags.length > 0 ||
        props.filters.assignees.length > 0 ||
        props.filters.unassigned,
);
</script>

<template>
    <div class="space-y-5 rounded-2xl border border-border bg-card p-5">
        <div class="grid gap-4 sm:grid-cols-[1fr_auto]">
            <!-- Search -->
            <div class="grid gap-2">
                <Label for="lead-search">{{ t('Search') }}</Label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        id="lead-search"
                        v-model="search"
                        type="search"
                        class="ps-9"
                        :placeholder="t('Company, number, website or contact')"
                        autocomplete="off"
                        data-test="lead-search"
                    />
                </div>
            </div>

            <!-- Sort -->
            <div class="grid gap-2">
                <Label for="lead-sort">{{ t('Sort by') }}</Label>
                <div class="flex gap-2">
                    <select
                        id="lead-sort"
                        class="h-9 flex-1 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :value="props.filters.sort"
                        data-test="lead-sort"
                        @change="
                            emit('change', {
                                sort: ($event.target as HTMLSelectElement)
                                    .value as LeadFilterState['sort'],
                                // A new ordering gets that ordering's natural direction rather
                                // than inheriting the last one, which is rarely what was meant.
                                direction: undefined,
                            })
                        "
                    >
                        <option
                            v-for="option in props.options.sorts"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ t(option.label) }}
                        </option>
                    </select>
                    <Button
                        variant="outline"
                        type="button"
                        :aria-label="directionLabel"
                        :title="directionLabel"
                        data-test="lead-direction"
                        @click="
                            emit('change', {
                                direction: (props.filters.direction === 'asc'
                                    ? 'desc'
                                    : 'asc') as LeadSortDirection,
                            })
                        "
                    >
                        <!-- Lucide, not a text glyph, so the icon sits on the same grid and stroke
                             as the rest. Vertical arrows are not mirrored under RTL. -->
                        <component
                            :is="
                                props.filters.direction === 'asc'
                                    ? ArrowUp
                                    : ArrowDown
                            "
                            class="size-4"
                            aria-hidden="true"
                        />
                    </Button>
                </div>
            </div>
        </div>

        <!-- Chip filters -->
        <div class="grid gap-4 sm:grid-cols-3">
            <fieldset class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Fit') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="option in props.options.grades"
                        :key="option.value"
                        type="button"
                        :aria-pressed="isOn('grades', option.value)"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-grade-${option.value}`"
                        @click="toggle('grades', option.value)"
                    >
                        <Badge
                            :variant="
                                isOn('grades', option.value)
                                    ? 'brand'
                                    : 'outline'
                            "
                            class="font-mono"
                            :class="
                                isOn('grades', option.value)
                                    ? ''
                                    : 'border-input'
                            "
                        >
                            <!-- A bare letter is meaningless out of context, and a <legend> isn't
                                 reliably announced for a button. -->
                            <span class="sr-only">{{
                                t('Grade :grade', { grade: option.value })
                            }}</span>
                            <span aria-hidden="true">{{ option.value }}</span>
                        </Badge>
                    </button>
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Source') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="option in props.options.sources"
                        :key="option.value"
                        type="button"
                        :aria-pressed="isOn('sources', option.value)"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-source-${option.value}`"
                        @click="toggle('sources', option.value)"
                    >
                        <Badge
                            :variant="
                                isOn('sources', option.value)
                                    ? 'brand'
                                    : 'outline'
                            "
                            :class="
                                isOn('sources', option.value)
                                    ? ''
                                    : 'border-input'
                            "
                        >
                            {{ t(option.label) }}
                        </Badge>
                    </button>
                </div>
            </fieldset>

            <fieldset class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Data') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="option in props.options.enrichment_statuses"
                        :key="option.value"
                        type="button"
                        :aria-pressed="
                            isOn('enrichment_statuses', option.value)
                        "
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-enrichment-${option.value}`"
                        @click="toggle('enrichment_statuses', option.value)"
                    >
                        <Badge
                            :variant="
                                isOn('enrichment_statuses', option.value)
                                    ? 'brand'
                                    : 'outline'
                            "
                            :class="
                                isOn('enrichment_statuses', option.value)
                                    ? ''
                                    : 'border-input'
                            "
                        >
                            {{ t(option.label) }}
                        </Badge>
                    </button>
                </div>
            </fieldset>
        </div>

        <!-- The team's own board, labels and people (Phase 10b). Each group is hidden when the
             team has nothing of that kind, so a fresh account isn't shown three empty rows. -->
        <div
            v-if="
                props.stages.length || props.tags.length || props.members.length
            "
            class="grid gap-4 border-t border-border pt-4 sm:grid-cols-3"
        >
            <fieldset v-if="props.stages.length" class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Stage') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="stage in props.stages"
                        :key="stage.id"
                        type="button"
                        :aria-pressed="isIdOn('stages', stage.id)"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-stage-${stage.id}`"
                        @click="toggleId('stages', stage.id)"
                    >
                        <Badge
                            :variant="
                                isIdOn('stages', stage.id) ? 'brand' : 'outline'
                            "
                            :class="
                                isIdOn('stages', stage.id) ? '' : 'border-input'
                            "
                            dir="auto"
                        >
                            {{ stage.name }}
                        </Badge>
                    </button>
                </div>
            </fieldset>

            <fieldset v-if="props.tags.length" class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Tags') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="tag in props.tags"
                        :key="tag.id"
                        type="button"
                        :aria-pressed="isIdOn('tags', tag.id)"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-tag-${tag.id}`"
                        @click="toggleId('tags', tag.id)"
                    >
                        <Badge
                            :variant="
                                isIdOn('tags', tag.id) ? 'brand' : 'outline'
                            "
                            :class="
                                isIdOn('tags', tag.id) ? '' : 'border-input'
                            "
                            dir="auto"
                        >
                            {{ tag.name }}
                        </Badge>
                    </button>
                </div>
                <!-- Narrowing by two tags means both, which isn't obvious from chips alone. -->
                <p
                    v-if="props.filters.tags.length > 1"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('Leads must carry every tag you pick.') }}
                </p>
            </fieldset>

            <fieldset v-if="props.members.length" class="space-y-2">
                <legend
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Who’s on it') }}
                </legend>
                <div class="flex flex-wrap gap-1.5">
                    <button
                        v-for="member in props.members"
                        :key="member.id"
                        type="button"
                        :aria-pressed="isIdOn('assignees', member.id)"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`filter-assignee-${member.id}`"
                        @click="toggleId('assignees', member.id)"
                    >
                        <Badge
                            :variant="
                                isIdOn('assignees', member.id)
                                    ? 'brand'
                                    : 'outline'
                            "
                            :class="
                                isIdOn('assignees', member.id)
                                    ? ''
                                    : 'border-input'
                            "
                            dir="auto"
                        >
                            {{ member.name }}
                        </Badge>
                    </button>
                    <button
                        type="button"
                        :aria-pressed="props.filters.unassigned"
                        class="rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        data-test="filter-unassigned"
                        @click="toggleUnassigned"
                    >
                        <Badge
                            :variant="
                                props.filters.unassigned ? 'brand' : 'outline'
                            "
                            :class="
                                props.filters.unassigned ? '' : 'border-input'
                            "
                        >
                            {{ t('Nobody') }}
                        </Badge>
                    </button>
                </div>
            </fieldset>
        </div>

        <div
            v-if="isFiltering"
            class="flex flex-wrap items-center justify-between gap-3 border-t border-border pt-4"
        >
            <p class="font-mono text-xs text-muted-foreground tabular-nums">
                {{ t(':count leads match', { count: props.total }) }}
            </p>
            <Button
                variant="ghost"
                size="sm"
                type="button"
                data-test="clear-filters"
                @click="emit('clear')"
            >
                <X class="size-4" />
                {{ t('Clear filters') }}
            </Button>
        </div>
    </div>
</template>
