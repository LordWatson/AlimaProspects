<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ChevronRight,
    Columns3,
    Download,
    Ellipsis,
    Plus,
    Radar,
    EyeOff,
    SearchX,
    Upload,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import BulkActionBar from '@/components/leads/BulkActionBar.vue';
import ConfidencePill from '@/components/leads/ConfidencePill.vue';
import LeadFilterBar from '@/components/leads/LeadFilterBar.vue';
import SavedViewBar from '@/components/leads/SavedViewBar.vue';
import {
    ListCell,
    ListHead,
    ListRow,
    ListRowLink,
    ListSearch,
    ListSort,
    ListTable,
    ListToolbar,
} from '@/components/list';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import MeterBar from '@/components/MeterBar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { EmptyState } from '@/components/ui/empty-state';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import { useNavigating } from '@/composables/useNavigating';
import type {
    ActiveFilterChip,
    EnrichmentStatus,
    Lead,
    LeadFilterOptions,
    LeadFilterState,
    LeadSort,
    LeadTag,
    Paginated,
    PipelineStage,
    SavedView,
    TeamMemberOption,
} from '@/types';

/*
 * The leads list — the screen a team spends most of its day on.
 *
 * What the rebuild changed, and why (the shared pieces live in `components/list`):
 *
 *  - **The filters fold away** and what they are doing is said in words above the table, as
 *    removable chips. Before, every filter the screen has was permanently open above the first
 *    row — six chip groups, about 300 pixels — so a laptop showed four leads, and a member who
 *    scrolled past the panel had no way of telling whether eleven rows was the answer or the
 *    leftovers of a filter set last Tuesday.
 *  - **The columns are sortable from the columns.** The ordering select stays, because "newest
 *    first" has no column, but Company, Fit and Updated now carry `aria-sort` and an arrow.
 *  - **The header stays** as the list scrolls, and **a row is clickable across its whole width**
 *    while still holding exactly one link.
 *  - **The list says when it is thinking.** Every filter is a server round trip; the table dims
 *    and the count is announced when the answer arrives.
 *  - **One primary action.** The hero carried five buttons of equal weight; §2.3 allows one
 *    primary CTA per view. Discovery is the primary, the two ways in are secondary, and import
 *    and export — the occasional, operational pair — moved into a menu.
 *  - **Each row carries its own summary on a phone**, where seven of the nine columns are hidden.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Leads', href: '/leads' }],
    },
});

const props = defineProps<{
    leads: Paginated<Lead>;
    filters: LeadFilterState;
    options: LeadFilterOptions;
    stages: PipelineStage[];
    tags: LeadTag[];
    members: TeamMemberOption[];
    savedViews: SavedView[];
    activeViewId: number | null;
    can: {
        saveView: boolean;
        work: boolean;
        create: boolean;
        import: boolean;
        export: boolean;
    };
    maxBulk: number;
    // --- Why now (PL1) ---
    /** Whether this team has the feature at all. Off = the list is exactly as it was before. */
    whyNow: boolean;
    /** How many leads these same filters are hiding for being below the team's threshold. */
    hiddenCount: number;
    /** The team's own threshold, so the toggle can say what "low" currently means. */
    minConfidence: number;
    /** Whether this team has contact enrichment (PL3). Off = no bulk Find button. */
    enrichment: boolean;
}>();

const { t } = useI18n();
const { number, date, percent } = useFormat();

// A filter change is a request, not a local toggle. Dim the rows while the server rebuilds them
// rather than leaving stale ones looking like an answer.
const busy = useNavigating();

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
        props.filters.unassigned ||
        props.filters.evidence_sources.length > 0 ||
        props.filters.evidence_kinds.length > 0 ||
        props.filters.include_low_confidence,
);

// --- What is being filtered out ------------------------------------------------------------
// Each chip names its group as well as its value, because "A", "Enriched" and "Dubai" are not
// self-explanatory side by side. The id is what comes back on removal.
function optionLabel(
    options: LeadFilterOptions['sources'],
    value: string,
): string {
    const option = options.find((candidate) => candidate.value === value);

    return option ? t(option.label) : value;
}

const activeChips = computed<ActiveFilterChip[]>(() => {
    const chips: ActiveFilterChip[] = [];

    for (const grade of props.filters.grades) {
        chips.push({ id: `grades:${grade}`, group: t('Fit'), label: grade });
    }

    for (const source of props.filters.sources) {
        chips.push({
            id: `sources:${source}`,
            group: t('Source'),
            label: optionLabel(props.options.sources, source),
        });
    }

    for (const status of props.filters.enrichment_statuses) {
        chips.push({
            id: `enrichment_statuses:${status}`,
            group: t('Data'),
            label: optionLabel(props.options.enrichment_statuses, status),
        });
    }

    for (const id of props.filters.stages) {
        const stage = props.stages.find((candidate) => candidate.id === id);

        chips.push({
            id: `stages:${id}`,
            group: t('Stage'),
            // A stage, a tag and a person are the team's own words — never translated.
            label: stage?.name ?? String(id),
        });
    }

    for (const id of props.filters.tags) {
        const tag = props.tags.find((candidate) => candidate.id === id);

        chips.push({
            id: `tags:${id}`,
            group: t('Tag'),
            label: tag?.name ?? String(id),
        });
    }

    for (const id of props.filters.assignees) {
        const member = props.members.find((candidate) => candidate.id === id);

        chips.push({
            id: `assignees:${id}`,
            group: t('Who’s on it'),
            label: member?.name ?? String(id),
        });
    }

    if (props.filters.unassigned) {
        chips.push({
            id: 'unassigned:1',
            group: t('Who’s on it'),
            label: t('Nobody'),
        });
    }

    if (props.filters.country !== '') {
        chips.push({
            id: 'country:1',
            group: t('Location'),
            label: props.filters.country,
        });
    }

    // --- Why now (PL1) ---
    for (const source of props.filters.evidence_sources) {
        chips.push({
            id: `evidence_sources:${source}`,
            group: t('Evidence from'),
            label: optionLabel(props.options.evidence_sources, source),
        });
    }

    for (const kind of props.filters.evidence_kinds) {
        chips.push({
            id: `evidence_kinds:${kind}`,
            group: t('Evidence type'),
            label: optionLabel(props.options.evidence_kinds, kind),
        });
    }

    // A chip, because revealing the weak leads changes what the list is answering - and because
    // the way back has to sit in the same place as every other filter's.
    if (props.filters.include_low_confidence) {
        chips.push({
            id: 'include_low_confidence:1',
            group: t('Confidence'),
            label: t('Low included'),
        });
    }

    return chips;
});

function removeChip(chip: ActiveFilterChip): void {
    const [key, raw] = chip.id.split(':');
    const id = Number(raw);

    switch (key) {
        case 'grades':
            apply({
                grades: props.filters.grades.filter((v) => v !== raw),
            });
            break;
        case 'sources':
            apply({
                sources: props.filters.sources.filter((v) => v !== raw),
            });
            break;
        case 'enrichment_statuses':
            apply({
                enrichment_statuses: props.filters.enrichment_statuses.filter(
                    (v) => v !== raw,
                ),
            });
            break;
        case 'stages':
            apply({ stages: props.filters.stages.filter((v) => v !== id) });
            break;
        case 'tags':
            apply({ tags: props.filters.tags.filter((v) => v !== id) });
            break;
        case 'assignees':
            apply({
                assignees: props.filters.assignees.filter((v) => v !== id),
            });
            break;
        case 'unassigned':
            apply({ unassigned: false });
            break;
        case 'country':
            apply({ country: '' });
            break;
        case 'evidence_sources':
            apply({
                evidence_sources: props.filters.evidence_sources.filter(
                    (v) => v !== raw,
                ),
            });
            break;
        case 'evidence_kinds':
            apply({
                evidence_kinds: props.filters.evidence_kinds.filter(
                    (v) => v !== raw,
                ),
            });
            break;
        case 'include_low_confidence':
            apply({ include_low_confidence: false });
            break;
    }
}

// --- Selection (Phase 10b) -----------------------------------------------------------------
// Selection is **per page**, deliberately: "select all" that silently meant 4 000 leads across 40
// pages would be a very easy way to do something nobody intended.
const selected = ref<number[]>([]);

watch(
    () => props.leads.data.map((lead) => lead.id).join(','),
    () => {
        selected.value = [];
    },
);

const pageIds = computed(() => props.leads.data.map((lead) => lead.id));

/**
 * `true`, `false` or `'indeterminate'` — a part-selected page reading as simply unchecked would be
 * a lie about what the next click does.
 */
const allSelected = computed<boolean | 'indeterminate'>(() => {
    if (selected.value.length === 0) return false;
    if (selected.value.length === pageIds.value.length) return true;

    return 'indeterminate';
});

function toggleLead(id: number, checked: boolean): void {
    selected.value = checked
        ? [...new Set([...selected.value, id])]
        : selected.value.filter((existing) => existing !== id);
}

function toggleAll(checked: boolean): void {
    selected.value = checked ? [...pageIds.value] : [];
}

/** What a screen reader is told about the selection, and where the controls for it are. */
const selectionAnnouncement = computed(() =>
    selected.value.length === 0
        ? ''
        : t(':count selected. Bulk actions are above the table.', {
              count: number(selected.value.length),
          }),
);

const selectAllBox = useTemplateRef<HTMLElement>('selectAllBox');

/**
 * After a bulk action the bar unmounts, so focus would land on the body. It goes back to the
 * select-all box, which is where the member was before they reached for the bar.
 */
async function clearSelection(): Promise<void> {
    selected.value = [];

    await nextTick();
    selectAllBox.value?.querySelector('button')?.focus();
}

function onBulkApplied(): void {
    void clearSelection();
}

/** The export carries the filters, so it is what is on screen rather than everything. */
const exportUrl = computed(() => {
    const params = new URLSearchParams();

    if (props.filters.search) params.set('search', props.filters.search);
    if (props.filters.country) params.set('country', props.filters.country);
    if (props.filters.sort !== 'newest') params.set('sort', props.filters.sort);

    for (const grade of props.filters.grades) params.append('grades[]', grade);
    for (const source of props.filters.sources)
        params.append('sources[]', source);
    for (const status of props.filters.enrichment_statuses)
        params.append('enrichment_statuses[]', status);
    for (const stage of props.filters.stages)
        params.append('stages[]', String(stage));
    for (const tag of props.filters.tags) params.append('tags[]', String(tag));
    for (const person of props.filters.assignees)
        params.append('assignees[]', String(person));
    if (props.filters.unassigned) params.set('unassigned', '1');

    // The export is "give me what I'm looking at", so it carries the evidence filters and the
    // reveal switch too - an export that silently included the leads the list was hiding would be
    // a different answer from the one on screen.
    for (const source of props.filters.evidence_sources)
        params.append('evidence_sources[]', source);
    for (const kind of props.filters.evidence_kinds)
        params.append('evidence_kinds[]', kind);
    if (props.filters.include_low_confidence)
        params.set('include_low_confidence', '1');

    const query = params.toString();

    return query === '' ? '/leads/export' : `/leads/export?${query}`;
});

/**
 * Push the new filter set into the URL and let the server rebuild the list. Everything about what
 * is on screen lives in the query string, so a filtered list can be shared, bookmarked and walked
 * back through with the browser's own buttons.
 */
function apply(change: Partial<LeadFilterState>): void {
    const merged: Record<
        string,
        string | string[] | number[] | boolean | undefined
    > = {
        ...props.filters,
        ...change,
    };

    const next: Record<string, string | string[] | number[]> = {};

    // Drop empties so a cleared filter leaves the URL rather than sitting in it as `search=`.
    for (const [key, value] of Object.entries(merged)) {
        if (value === undefined || value === '' || value === false) continue;
        if (Array.isArray(value) && value.length === 0) continue;

        next[key] = value === true ? '1' : value;
    }

    router.get('/leads', next, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

function clear(): void {
    router.get('/leads', {}, { preserveScroll: true, replace: true });
}

/**
 * A click on a sortable column header. The same column again flips the direction; a different one
 * takes that ordering's own natural direction (`LeadSort::defaultDirection()` on the server)
 * rather than inheriting the last one, which is rarely what was meant.
 */
function sortBy(key: string): void {
    if (props.filters.sort === key) {
        apply({
            direction: props.filters.direction === 'asc' ? 'desc' : 'asc',
        });

        return;
    }

    apply({ sort: key as LeadSort, direction: undefined });
}

const summary = computed(() =>
    isFiltering.value
        ? t(':count leads match', { count: number(props.leads.meta.total) })
        : t(':count leads', { count: number(props.leads.meta.total) }),
);

// Data state reads as neutral information, never as an error: a lead still waiting to be enriched
// isn't a fault, so only an actual failure gets a warning tint.
//
// `enriching` is **not** `info`: `status.info` over its own 12% tint is about 4.4:1, under AA at
// badge size, and §7g.2 allows info as a non-text icon only until the token is approved (§7.6).
// An outline badge with a spinner says "in progress" without leaning on the colour at all.
const enrichmentVariant: Record<
    EnrichmentStatus,
    'success' | 'outline' | 'warning'
> = {
    enriched: 'success',
    enriching: 'outline',
    pending: 'outline',
    failed: 'warning',
};

/** The one line a phone gets instead of the six columns it cannot show. */
function rowSummary(lead: Lead): string {
    return [lead.stage?.name, lead.locality ?? lead.country]
        .filter((part): part is string => Boolean(part))
        .join(' · ');
}

/**
 * The lead's why-now line: Alima's sentence if it has written one, else the strongest reason's own
 * headline - because a card has to say something on the first page load, before the summary job
 * has run. Null when there is nothing at all.
 */
function whyNowLine(lead: Lead): string | null {
    return lead.why_now_summary ?? lead.top_evidence?.title ?? null;
}

function updatedAt(lead: Lead): string {
    if (lead.enriched_at) return date(lead.enriched_at);
    if (lead.collected_at) return date(lead.collected_at);

    return '—';
}
</script>

<template>
    <Head :title="t('Leads')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            index="06"
            :eyebrow="t('Leads')"
            :title="t('Your|leads.')"
            :description="
                t(
                    'Every company Alima has found for you, graded against your ideal customer. Filter it, save the filters you keep coming back to, and open a lead to see everything Alima knows.',
                )
            "
            :stat="{
                label: t('In your list'),
                value: leads.meta.total,
                caption: t('Companies Alima has found'),
            }"
        >
            <template #actions>
                <!-- One primary per view (brand-tokens.md §2.3): finding more leads is what this
                     screen is for. -->
                <Button variant="lime" as-child>
                    <Link href="/discovery">
                        <Radar class="size-4" />
                        {{ t('Find more leads') }}
                    </Link>
                </Button>
                <!-- One company a member met, rather than a search Alima runs. Any member
                     but Read-only, unlike Import below. -->
                <Button v-if="can.create" variant="hero" as-child>
                    <Link href="/leads/create">
                        <Plus class="size-4" />
                        {{ t('Add a lead') }}
                    </Link>
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/leads/pipeline">
                        <Columns3 class="size-4" />
                        {{ t('Board view') }}
                    </Link>
                </Button>

                <!-- Import and export are occasional and operational. In the row they read as two
                     more ways to look at leads, which is what the first three are; behind a menu
                     they read as what they are. -->
                <DropdownMenu v-if="can.import || can.export">
                    <DropdownMenuTrigger as-child>
                        <Button variant="hero" :aria-label="t('More actions')">
                            <Ellipsis class="size-4" />
                            {{ t('More') }}
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end" class="min-w-48">
                        <DropdownMenuItem v-if="can.import" as-child>
                            <Link
                                href="/leads/import"
                                class="w-full cursor-pointer"
                            >
                                <Upload class="size-4" />
                                {{ t('Import a CSV') }}
                            </Link>
                        </DropdownMenuItem>
                        <!-- A plain link, not an Inertia visit: the response is a streamed
                             file download. -->
                        <DropdownMenuItem v-if="can.export" as-child>
                            <a
                                :href="exportUrl"
                                class="w-full cursor-pointer"
                                data-test="export-leads"
                            >
                                <Download class="size-4" />
                                {{ t('Export what I can see') }}
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </template>
        </PageHero>

        <!-- Nothing at all yet: a different situation from "nothing matched" -->
        <EmptyState
            v-if="!leads.data.length && !isFiltering"
            :icon="Radar"
            :title="t('No leads yet')"
            :description="
                t(
                    'Run a discovery and the companies Alima finds will land here, enriched and graded. Or add one you already know about.',
                )
            "
            data-test="no-leads"
        >
            <Button variant="brand" as-child>
                <Link href="/discovery">
                    <Radar class="size-4" />
                    {{ t('Start discovery') }}
                </Link>
            </Button>
            <Button v-if="can.create" variant="outline" as-child>
                <Link href="/leads/create">
                    <Plus class="size-4" />
                    {{ t('Add a lead') }}
                </Link>
            </Button>
        </EmptyState>

        <template v-else>
            <SavedViewBar
                :views="savedViews"
                :active-view-id="activeViewId"
                :filters="filters"
                :can-save="can.saveView"
                :is-filtering="isFiltering"
            />

            <ListToolbar
                :summary="summary"
                :chips="activeChips"
                :busy="busy"
                @remove-filter="removeChip"
                @clear-filters="clear"
            >
                <template #search>
                    <ListSearch
                        id="lead-search"
                        :value="filters.search"
                        :label="t('Search')"
                        :placeholder="t('Company, number, website or contact')"
                        data-test="lead-search"
                        @search="apply({ search: $event })"
                    />
                </template>

                <template #sort>
                    <ListSort
                        id="lead-sort"
                        :options="options.sorts"
                        :value="filters.sort"
                        :direction="filters.direction"
                        @sort="
                            apply({
                                sort: $event as LeadSort,
                                // A new ordering gets that ordering's natural direction rather
                                // than inheriting the last one.
                                direction: undefined,
                            })
                        "
                        @direction="apply({ direction: $event })"
                    />
                </template>

                <template #filters>
                    <LeadFilterBar
                        :filters="filters"
                        :options="options"
                        :why-now="whyNow"
                        :stages="stages"
                        :tags="tags"
                        :members="members"
                        @change="apply"
                    />
                </template>
            </ListToolbar>

            <!-- Hidden by the team's confidence threshold (PL1). A count and a way in, in
                 words, above the table: a list that silently dropped a third of a team's leads
                 would be a list nobody could trust, and "12 hidden" is the difference between a
                 filter and a disappearance. -->
            <p
                v-if="whyNow && hiddenCount > 0"
                class="flex flex-wrap items-center gap-2 text-sm text-muted-foreground"
                data-test="hidden-low-confidence"
            >
                <EyeOff class="size-4 shrink-0" aria-hidden="true" />
                <span>{{
                    t(
                        ':count hidden — we don’t know enough about them yet (under :threshold).',
                        {
                            count: number(hiddenCount),
                            threshold: String(minConfidence),
                        },
                    )
                }}</span>
                <Button
                    variant="link"
                    size="sm"
                    class="h-auto p-0"
                    data-test="show-low-confidence"
                    @click="apply({ include_low_confidence: true })"
                >
                    {{ t('Show them') }}
                </Button>
            </p>

            <!-- Always mounted: a live region inserted with text already in it is usually not
                 announced, so the first selection would have gone by in silence. It also says
                 where the controls are, because they sit above the table the member is in. -->
            <p class="sr-only" role="status" aria-live="polite">
                {{ selectionAnnouncement }}
            </p>

            <BulkActionBar
                v-if="can.work && selected.length > 0"
                id="bulk-actions"
                :selected="selected"
                :stages="stages"
                :members="members"
                :max-bulk="maxBulk"
                :enrichment="enrichment"
                @clear="clearSelection"
                @applied="onBulkApplied"
            />

            <!-- Filtered down to nothing. The chips above say what did it, so this only has to
                 offer the way out. -->
            <EmptyState
                v-if="!leads.data.length"
                :icon="SearchX"
                :title="t('No leads match these filters')"
                :description="
                    t('Loosen a filter or clear them to see your whole list.')
                "
                data-test="no-matches"
            >
                <Button variant="brand" @click="clear">
                    {{ t('Clear filters') }}
                </Button>
            </EmptyState>

            <template v-else>
                <ListTable
                    :caption="t('Your leads, with their fit grade and source')"
                    :busy="busy"
                >
                    <thead>
                        <tr>
                            <ListHead
                                v-if="can.work"
                                :label="t('Select')"
                                class="w-10"
                            >
                                <!-- The ref sits on the wrapper rather than the component: a ref
                                     to a `<script setup>` component is a closed instance proxy
                                     with no element on it to focus. -->
                                <span ref="selectAllBox" class="block">
                                    <Checkbox
                                        id="select-all"
                                        :model-value="allSelected"
                                        :aria-label="
                                            t('Select every lead on this page')
                                        "
                                        data-test="select-all"
                                        @update:model-value="
                                            toggleAll($event === true)
                                        "
                                    />
                                </span>
                            </ListHead>
                            <ListHead
                                :label="t('Company')"
                                sort="company"
                                :active="filters.sort"
                                :direction="filters.direction"
                                @sort="sortBy"
                            />
                            <ListHead
                                :label="t('Fit')"
                                sort="fit"
                                :active="filters.sort"
                                :direction="filters.direction"
                                @sort="sortBy"
                            />
                            <ListHead :label="t('Stage')" hide="md" />
                            <ListHead :label="t('Location')" hide="md" />
                            <ListHead
                                :label="t('Contacts')"
                                hide="lg"
                                align="end"
                            />
                            <ListHead :label="t('Who’s on it')" hide="lg" />
                            <!-- "Data" is sorted by `readiness` (Q4): how much of what we need to
                                 contact this lead we actually hold. A different question from Fit,
                                 which is blind to missing data on purpose. -->
                            <ListHead
                                :label="t('Data')"
                                sort="readiness"
                                hide="sm"
                                :active="filters.sort"
                                :direction="filters.direction"
                                @sort="sortBy"
                            />
                            <ListHead
                                :label="t('Updated')"
                                sort="freshness"
                                align="end"
                                hide="lg"
                                :active="filters.sort"
                                :direction="filters.direction"
                                @sort="sortBy"
                            />
                            <!-- The "open" affordance: the arrow pointing at the row's own link.
                                 Its header is named but not shown, because an unnamed column
                                 reads as "blank" and a visible one would name a thing the row
                                 has already named. -->
                            <ListHead :label="t('Open')" class="w-8">
                                <span class="sr-only">{{ t('Open') }}</span>
                            </ListHead>
                        </tr>
                    </thead>
                    <tbody>
                        <ListRow
                            v-for="lead in leads.data"
                            :key="lead.id"
                            :selected="selected.includes(lead.id)"
                            :data-test="`lead-${lead.id}`"
                        >
                            <ListCell v-if="can.work" raised class="w-10">
                                <Checkbox
                                    :id="`select-${lead.id}`"
                                    :model-value="selected.includes(lead.id)"
                                    :aria-label="
                                        t('Select :company', {
                                            company: lead.company_name,
                                        })
                                    "
                                    :data-test="`select-${lead.id}`"
                                    @update:model-value="
                                        toggleLead(lead.id, $event === true)
                                    "
                                />
                            </ListCell>
                            <ListCell>
                                <!-- The row's main affordance is this one link, stretched over
                                     the whole row, so a screen reader reads one name per row. -->
                                <ListRowLink :href="`/leads/${lead.id}`">
                                    {{ lead.company_name }}
                                </ListRowLink>
                                <span
                                    v-if="lead.industry"
                                    class="block text-xs text-muted-foreground"
                                    dir="auto"
                                    >{{ lead.industry }}</span
                                >
                                <!-- Why now (PL1): Alima's sentence, or the strongest reason's
                                     own headline before the sentence exists. Clamped to two
                                     lines - a list row is a prompt to open the lead, not the
                                     panel. -->
                                <span
                                    v-if="whyNow && whyNowLine(lead)"
                                    class="mt-1 line-clamp-2 block max-w-prose text-xs leading-snug text-muted-foreground"
                                    dir="auto"
                                    :data-test="`why-now-${lead.id}`"
                                    >{{ whyNowLine(lead) }}</span
                                >
                                <!-- On a phone, six of the nine columns are hidden; this is what
                                     they said, in one line. -->
                                <span
                                    v-if="rowSummary(lead)"
                                    class="mt-0.5 block text-xs text-muted-foreground md:hidden"
                                    dir="auto"
                                    >{{ rowSummary(lead) }}</span
                                >
                                <ul
                                    v-if="lead.tags?.length"
                                    class="relative z-10 mt-1 flex flex-wrap gap-1"
                                >
                                    <li v-for="tag in lead.tags" :key="tag.id">
                                        <Badge variant="lilac" dir="auto">{{
                                            tag.name
                                        }}</Badge>
                                    </li>
                                </ul>
                            </ListCell>
                            <ListCell>
                                <GradeBadge
                                    v-if="lead.score"
                                    :grade="lead.score.grade"
                                    :score="lead.score.score"
                                    :label="t(lead.score.grade_label)"
                                />
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >{{ t('Not graded') }}</span
                                >
                                <!-- A different question from the grade above it, so it sits
                                     under rather than beside: fit is about the company,
                                     confidence is about our evidence. Colourless by design. -->
                                <div v-if="whyNow" class="mt-1">
                                    <ConfidencePill
                                        :confidence="lead.confidence"
                                        :band="lead.confidence_band"
                                    />
                                </div>
                            </ListCell>
                            <!-- No stage means the lead is still in the starting column, so the
                                 dash reads as "not moved yet" rather than "nowhere". -->
                            <ListCell hide="md" muted>
                                <span dir="auto">{{
                                    lead.stage?.name ?? '—'
                                }}</span>
                            </ListCell>
                            <ListCell hide="md" muted>
                                <span dir="auto">{{
                                    lead.locality ?? lead.country ?? '—'
                                }}</span>
                            </ListCell>
                            <!-- The isolation goes on a <bdi> inside the cell, not on the cell:
                                 `dir` on the `td` would make `text-align: start` resolve to the
                                 left and knock the column out of line under RTL. -->
                            <ListCell hide="lg" muted mono align="end">
                                <bdi dir="ltr">{{
                                    number(lead.contacts_count ?? 0)
                                }}</bdi>
                            </ListCell>
                            <ListCell hide="lg" muted>
                                <span
                                    v-if="lead.assignees?.length"
                                    class="truncate text-xs"
                                    dir="auto"
                                    >{{
                                        lead.assignees
                                            .map((p) => p.name)
                                            .join(', ')
                                    }}</span
                                >
                                <span v-else class="text-xs">{{
                                    t('Nobody')
                                }}</span>
                                <span
                                    v-if="lead.open_tasks_count"
                                    class="block font-mono text-xs"
                                >
                                    {{
                                        t(':count to do', {
                                            count: lead.open_tasks_count,
                                        })
                                    }}
                                </span>
                            </ListCell>
                            <ListCell hide="sm">
                                <Badge
                                    :variant="
                                        enrichmentVariant[
                                            lead.enrichment_status
                                        ]
                                    "
                                >
                                    <Spinner
                                        v-if="
                                            lead.enrichment_status ===
                                            'enriching'
                                        "
                                        class="size-3"
                                    />
                                    {{ t(lead.enrichment_status_label) }}
                                </Badge>
                                <!-- Deliberately NOT a grade: no letter and no status colour, since
                                     a low readiness is a gap in our data and not a verdict about
                                     the prospect. Nothing is shown until there is a figure. -->
                                <div
                                    v-if="lead.readiness !== null"
                                    class="mt-1.5 flex items-center gap-1.5"
                                >
                                    <MeterBar
                                        :value="lead.readiness"
                                        tone="neutral"
                                        class="w-10"
                                        :label="
                                            t(
                                                ':percent of what we need to reach them',
                                                {
                                                    percent: percent(
                                                        lead.readiness / 100,
                                                    ),
                                                },
                                            )
                                        "
                                    />
                                    <bdi
                                        class="text-xs text-ink-60 tabular-nums"
                                    >
                                        {{ percent(lead.readiness / 100) }}
                                    </bdi>
                                </div>
                            </ListCell>
                            <ListCell hide="lg" muted align="end">
                                <bdi class="text-xs">{{ updatedAt(lead) }}</bdi>
                            </ListCell>
                            <ListCell align="end" class="w-8">
                                <ChevronRight
                                    class="size-4 text-muted-foreground opacity-0 transition-opacity group-hover/row:opacity-100 rtl:rotate-180"
                                    aria-hidden="true"
                                />
                            </ListCell>
                        </ListRow>
                    </tbody>
                </ListTable>

                <!-- The per-page links live under `meta`; the top-level `links` is only
                     first/last/prev/next. -->
                <Pagination
                    :links="leads.meta.links"
                    :meta="leads.meta"
                    label="Pages of leads"
                />
            </template>
        </template>
    </div>
</template>
