<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Columns3, Download, Plus, Radar, SearchX, Upload } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import BulkActionBar from '@/components/leads/BulkActionBar.vue';
import LeadFilterBar from '@/components/leads/LeadFilterBar.vue';
import SavedViewBar from '@/components/leads/SavedViewBar.vue';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    EnrichmentStatus,
    Lead,
    LeadFilterOptions,
    LeadFilterState,
    LeadTag,
    Paginated,
    PipelineStage,
    SavedView,
    TeamMemberOption,
} from '@/types';

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
}>();

const { t } = useI18n();
const { number, date } = useFormat();

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

// Data state reads as neutral information, never as an error: a lead still waiting to be enriched
// isn't a fault, so only an actual failure gets a warning tint.
const enrichmentVariant: Record<
    EnrichmentStatus,
    'success' | 'info' | 'outline' | 'warning'
> = {
    enriched: 'success',
    enriching: 'info',
    pending: 'outline',
    failed: 'warning',
};
</script>

<template>
    <Head :title="t('Leads')" />

    <div class="flex flex-col gap-8 py-2">
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
                <Button v-if="can.import" variant="hero" as-child>
                    <Link href="/leads/import">
                        <Upload class="size-4" />
                        {{ t('Import') }}
                    </Link>
                </Button>
                <!-- A plain link, not an Inertia visit: the response is a streamed file download. -->
                <Button v-if="can.export" variant="hero" as-child>
                    <a :href="exportUrl" data-test="export-leads">
                        <Download class="size-4" />
                        {{ t('Export') }}
                    </a>
                </Button>
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

            <LeadFilterBar
                :filters="filters"
                :options="options"
                :stages="stages"
                :tags="tags"
                :members="members"
                :total="leads.meta.total"
                @change="apply"
                @clear="clear"
            />

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
                @clear="clearSelection"
                @applied="onBulkApplied"
            />

            <!-- Filtered down to nothing -->
            <EmptyState
                v-if="!leads.data.length"
                :icon="SearchX"
                :title="t('No leads match these filters')"
                :description="
                    t('Loosen a filter or clear them to see your whole list.')
                "
                data-test="no-matches"
            >
                <Button variant="outline" @click="clear">
                    {{ t('Clear filters') }}
                </Button>
            </EmptyState>

            <template v-else>
                <div
                    class="overflow-hidden rounded-2xl border border-border bg-card"
                >
                    <table class="w-full text-sm">
                        <caption class="sr-only">
                            {{
                                t('Your leads, with their fit grade and source')
                            }}
                        </caption>
                        <thead class="bg-accent/50 text-muted-foreground">
                            <tr>
                                <th
                                    v-if="can.work"
                                    ref="selectAllBox"
                                    scope="col"
                                    class="w-10 px-4 py-3 text-start font-medium"
                                >
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
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-start font-medium"
                                >
                                    {{ t('Company') }}
                                </th>
                                <th
                                    scope="col"
                                    class="px-4 py-3 text-start font-medium"
                                >
                                    {{ t('Fit') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium md:table-cell"
                                >
                                    {{ t('Stage') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium md:table-cell"
                                >
                                    {{ t('Location') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium lg:table-cell"
                                >
                                    {{ t('Contacts') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium lg:table-cell"
                                >
                                    {{ t('Who’s on it') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-start font-medium sm:table-cell"
                                >
                                    {{ t('Data') }}
                                </th>
                                <th
                                    scope="col"
                                    class="hidden px-4 py-3 text-end font-medium lg:table-cell"
                                >
                                    {{ t('Updated') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="lead in leads.data"
                                :key="lead.id"
                                class="border-t border-border transition-colors hover:bg-accent/30"
                                :data-test="`lead-${lead.id}`"
                            >
                                <td v-if="can.work" class="px-4 py-3">
                                    <Checkbox
                                        :id="`select-${lead.id}`"
                                        :model-value="
                                            selected.includes(lead.id)
                                        "
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
                                </td>
                                <td class="px-4 py-3">
                                    <!-- The row's main affordance is this one link, so a screen
                                         reader reads one name per row. -->
                                    <Link
                                        :href="`/leads/${lead.id}`"
                                        class="rounded-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                        dir="auto"
                                    >
                                        {{ lead.company_name }}
                                    </Link>
                                    <span
                                        v-if="lead.industry"
                                        class="block text-xs text-muted-foreground"
                                        dir="auto"
                                        >{{ lead.industry }}</span
                                    >
                                    <ul
                                        v-if="lead.tags?.length"
                                        class="mt-1 flex flex-wrap gap-1"
                                    >
                                        <li
                                            v-for="tag in lead.tags"
                                            :key="tag.id"
                                        >
                                            <Badge variant="lilac" dir="auto">{{
                                                tag.name
                                            }}</Badge>
                                        </li>
                                    </ul>
                                </td>
                                <td class="px-4 py-3">
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
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                                    dir="auto"
                                >
                                    <!-- No stage means the lead is still in the starting column, so
                                         the dash reads as "not moved yet" rather than "nowhere". -->
                                    {{ lead.stage?.name ?? '—' }}
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                                    dir="auto"
                                >
                                    {{ lead.locality ?? lead.country ?? '—' }}
                                </td>
                                <!-- The isolation goes on a <bdi> inside the cell, not on the cell:
                                     `dir` on the <td> would make `text-align: start` resolve to the
                                     left and knock the column out of line under RTL. -->
                                <td
                                    class="hidden px-4 py-3 font-mono text-xs text-muted-foreground tabular-nums lg:table-cell"
                                >
                                    <bdi dir="ltr">{{
                                        number(lead.contacts_count ?? 0)
                                    }}</bdi>
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-muted-foreground lg:table-cell"
                                >
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
                                </td>
                                <td class="hidden px-4 py-3 sm:table-cell">
                                    <Badge
                                        :variant="
                                            enrichmentVariant[
                                                lead.enrichment_status
                                            ]
                                        "
                                    >
                                        {{ t(lead.enrichment_status_label) }}
                                    </Badge>
                                </td>
                                <td
                                    class="hidden px-4 py-3 text-end text-xs text-muted-foreground lg:table-cell"
                                >
                                    <bdi>{{
                                        lead.enriched_at
                                            ? date(lead.enriched_at)
                                            : lead.collected_at
                                              ? date(lead.collected_at)
                                              : '—'
                                    }}</bdi>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

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
