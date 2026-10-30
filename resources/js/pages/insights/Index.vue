<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Lightbulb } from '@lucide/vue';
import { computed, ref } from 'vue';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import InsightCard from '@/components/insights/InsightCard.vue';
import { EmptyState } from '@/components/ui/empty-state';
import { useI18n } from '@/composables/useI18n';
import { isolate } from '@/lib/bidi';
import type {
    Insight,
    InsightSchedule,
    InsightStatus,
    InsightStatusCounts,
    PaginationLink,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Insights', href: '/insights' }],
    },
});

const props = defineProps<{
    insights: {
        data: Insight[];
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
    };
    filters: { status: InsightStatus };
    counts: InsightStatusCounts;
    schedule: InsightSchedule;
    can: { dismiss: boolean };
}>();

const { t } = useI18n();

/**
 * Where focus goes when the card that was focused leaves the list.
 *
 * Dismissing unmounts the button that was focused, and restoring focus to a node that no longer
 * exists silently drops it on `<body>`. The target is the **section** wrapper rather than the list
 * itself, because dismissing the last open insight unmounts the list too — so the one node that is
 * guaranteed to still be there after any dismissal is the one outside it. The live region below
 * says what happened.
 */
const sectionRef = ref<HTMLElement | null>(null);
const announcement = ref('');

// "Still open" rather than "Open": the existing shared key translates as the imperative verb
// ("افتح!"), which is not what a tab describing a state should say.
const tabs: { value: InsightStatus; label: string }[] = [
    { value: 'new', label: 'Still open' },
    { value: 'dismissed', label: 'Dismissed' },
];

/** Each tab is a real, shareable URL rather than a button that fetches. */
function tabHref(status: InsightStatus): string {
    return `/insights?status=${status}`;
}

function dismiss(insight: Insight): void {
    router.post(
        `/insights/${insight.id}/dismiss`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => {
                announcement.value = t('Insight dismissed.');
                // Focus lands somewhere real, so the next Tab continues from the feed rather than
                // from the top of the document.
                sectionRef.value?.focus();
            },
        },
    );
}

/**
 * "07:00" in the team's own clock — what the empty state promises. Isolated, like the timezone
 * beside it: both are left-to-right runs inside what may be an Arabic sentence, and an unisolated
 * one lets the bidi algorithm move the punctuation around it (see `lib/bidi`).
 */
const nextRun = computed(() =>
    isolate(`${String(props.schedule.hour).padStart(2, '0')}:00`),
);

const timezone = computed(() => isolate(props.schedule.timezone));
</script>

<template>
    <Head :title="t('Insights')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="09"
            :eyebrow="t('Insights')"
            :title="t('What Alima|noticed.')"
            :description="
                t(
                    'Every morning: the companies going cold, the strong matches nobody has approached, and what the week’s numbers say. Read it, act on it, clear it.',
                )
            "
            :stat="{
                label: t('Waiting on you'),
                value: counts.new,
                caption: t('Insights to read'),
            }"
        />

        <!-- Always mounted: a live region inserted by v-if is often never announced. -->
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <!-- Tabs. Links, not `role="tab"`: each one navigates, so it can be opened in a new tab,
             bookmarked and shared, and a tablist would promise arrow-key traversal and a tabpanel
             that aren't there. -->
        <div
            ref="sectionRef"
            tabindex="-1"
            class="space-y-4 focus:outline-none"
        >
            <nav
                class="flex flex-wrap items-center gap-2 border-b border-border"
                :aria-label="t('Insights')"
            >
                <Link
                    v-for="tab in tabs"
                    :key="tab.value"
                    :href="tabHref(tab.value)"
                    preserve-scroll
                    :aria-current="
                        filters.status === tab.value ? 'page' : undefined
                    "
                    :data-test="`tab-${tab.value}`"
                    class="-mb-px rounded-t-md border-b-2 px-3 py-2 text-sm font-medium transition-colors focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        filters.status === tab.value
                            ? 'border-brand text-brand'
                            : 'border-transparent text-muted-foreground hover:text-foreground'
                    "
                >
                    {{ t(tab.label) }}
                    <span
                        class="ms-1 font-mono text-xs tabular-nums"
                        dir="ltr"
                        >{{ counts[tab.value] ?? 0 }}</span
                    >
                </Link>
            </nav>

            <div
                v-if="insights.data.length"
                class="space-y-4"
                data-test="insight-list"
            >
                <InsightCard
                    v-for="insight in insights.data"
                    :key="insight.id"
                    :insight="insight"
                    :can-dismiss="can.dismiss"
                    @dismiss="dismiss"
                />

                <Pagination
                    v-if="insights.meta.last_page > 1"
                    :links="insights.links"
                    :meta="insights.meta"
                    :label="t('Insight pages')"
                />
            </div>

            <!-- Nothing yet is not the same as nothing left, and they need different sentences.
                 An empty Open tab has to say when the next run is, in the team's own clock —
                 otherwise a member who looks at four in the afternoon reads an empty screen as a
                 broken one. -->
            <EmptyState
                v-else-if="filters.status === 'new'"
                :icon="Lightbulb"
                :title="t('Nothing waiting on you')"
                :description="
                    t(
                        'Alima works through your leads and your week every morning at :time, :timezone, and puts what it finds here.',
                        { time: nextRun, timezone },
                    )
                "
                data-test="no-insights"
            />

            <EmptyState
                v-else
                :icon="Lightbulb"
                :title="t('Nothing cleared away yet')"
                :description="
                    t(
                        'Insights you dismiss wait here until they expire, in case you change your mind.',
                    )
                "
                data-test="no-insights"
            />
        </div>
    </div>
</template>
