<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Check, Satellite, Settings2, SlidersHorizontal } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import Pagination from '@/components/Pagination.vue';
import ConvertSignalModal from '@/components/signals/ConvertSignalModal.vue';
import DismissSignalDialog from '@/components/signals/DismissSignalDialog.vue';
import SignalCard from '@/components/signals/SignalCard.vue';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type {
    PaginationLink,
    PipelineStage,
    Signal,
    SignalDismissReasonOption,
    SignalEventType,
    SignalEventTypeOption,
    SignalFilterState,
    SignalsDetectedPayload,
    SignalStatus,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Signals', href: '/signals' }],
    },
});

const props = defineProps<{
    signals: {
        data: Signal[];
        links: PaginationLink[];
        meta: {
            current_page: number;
            last_page: number;
            from: number | null;
            to: number | null;
            total: number;
        };
    };
    filters: SignalFilterState;
    counts: Record<SignalStatus, number>;
    options: {
        event_types: SignalEventTypeOption[];
        dismiss_reasons: SignalDismissReasonOption[];
    };
    stages: PipelineStage[];
    watchCount: number;
    /** What a first watch would cover, so the empty state can name the place. */
    defaults: { location: string };
    can: { action: boolean; manageWatches: boolean };
}>();

const { t } = useI18n();
const page = usePage();

/**
 * Where focus goes when the card that was focused leaves the list — converting or dismissing
 * unmounts the button that opened the dialog, and restoring focus to a node that no longer exists
 * silently drops it on `<body>`. The list container takes it instead, and the live region below
 * says what happened.
 */
const listRef = ref<HTMLElement | null>(null);
const announcement = ref('');

function announce(message: string): void {
    announcement.value = message;
    // Focus lands somewhere real, so the next Tab continues from the list rather than the top.
    listRef.value?.focus();
}

const tabs: { value: SignalStatus; label: string }[] = [
    { value: 'new', label: 'New' },
    { value: 'converted', label: 'Converted' },
    { value: 'dismissed', label: 'Dismissed' },
];

// --- Server-driven filtering --------------------------------------------------------------------
// Every change is a real navigation, so the URL always reproduces what is on screen and a shared
// link opens the same feed.
const showFilters = ref(
    props.filters.event_types.length > 0 ||
        props.filters.from !== null ||
        props.filters.to !== null ||
        props.filters.min_relevance !== null,
);

type SignalQueryParams = {
    status: SignalStatus;
    event_types: SignalEventType[];
    from?: string;
    to?: string;
    min_relevance?: number;
};

/** The query a given filter state corresponds to — one definition for both links and navigation. */
function queryFor(changes: Partial<SignalFilterState>): SignalQueryParams {
    const next = { ...props.filters, ...changes };

    return {
        status: next.status,
        event_types: next.event_types,
        from: next.from ?? undefined,
        to: next.to ?? undefined,
        min_relevance: next.min_relevance ?? undefined,
    };
}

/** The tab's href, so a tab is a real, shareable URL rather than a button that fetches. */
function tabHref(status: SignalStatus): string {
    const query = queryFor({ status });
    const params = new URLSearchParams();

    params.set('status', query.status);
    query.event_types.forEach((type) => params.append('event_types[]', type));
    if (query.from) params.set('from', query.from);
    if (query.to) params.set('to', query.to);
    if (query.min_relevance !== undefined) {
        params.set('min_relevance', String(query.min_relevance));
    }

    return `/signals?${params.toString()}`;
}

function navigate(changes: Partial<SignalFilterState>): void {
    router.get('/signals', queryFor(changes), {
        preserveScroll: true,
        preserveState: true,
        replace: true,
    });
}

function toggleEventType(type: SignalEventType): void {
    navigate({
        event_types: props.filters.event_types.includes(type)
            ? props.filters.event_types.filter((t) => t !== type)
            : [...props.filters.event_types, type],
    });
}

function clearFilters(): void {
    navigate({
        event_types: [],
        from: null,
        to: null,
        min_relevance: null,
    });
}

const isNarrowed = computed(
    () =>
        props.filters.event_types.length > 0 ||
        props.filters.from !== null ||
        props.filters.to !== null ||
        props.filters.min_relevance !== null,
);

// --- Modals -------------------------------------------------------------------------------------
const converting = ref<Signal | null>(null);
const dismissing = ref<Signal | null>(null);

function restore(signal: Signal): void {
    router.post(
        `/signals/${signal.id}/restore`,
        {},
        {
            preserveScroll: true,
            onSuccess: () => announce(t('Signal restored.')),
        },
    );
}

function onConverted(): void {
    converting.value = null;
    announce(t('Signal converted.'));
}

function onDismissed(): void {
    dismissing.value = null;
    announce(t('Signal dismissed.'));
}

// --- Reverb: a run finishing while the feed is open ---------------------------------------------
const teamId = computed(() => page.props.auth.currentTeam?.id ?? null);
/** New signals that landed since this page was rendered, so the banner can offer them. */
const arrived = ref(0);
let channelName: string | null = null;

function onDetected(payload: SignalsDetectedPayload): void {
    arrived.value += payload.count;
}

function showArrived(): void {
    arrived.value = 0;
    router.reload({ only: ['signals', 'counts'] });
}

onMounted(() => {
    if (teamId.value == null || !window.Echo) return;

    channelName = `teams.${teamId.value}`;
    window.Echo.private(channelName).listen('.signals.detected', onDetected);
});

onBeforeUnmount(() => {
    if (channelName && window.Echo) {
        window.Echo.leave(channelName);
    }
});
</script>

<template>
    <Head :title="t('Signals')" />

    <div class="flex flex-col gap-8 py-2">
        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="max-w-2xl space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Signals') }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                >
                    {{ t('Who just came into money')
                    }}<span class="text-orange">.</span>
                </h1>
                <p class="text-base text-muted-foreground">
                    {{
                        t(
                            'Company sales, funding rounds and founders moving to town — found in published news, summarised, and ranked for how well they fit you.',
                        )
                    }}
                </p>
            </div>

            <Button v-if="can.manageWatches" variant="outline" as-child>
                <Link href="/signals/watches">
                    <Settings2 class="size-4" aria-hidden="true" />
                    {{ t('Watch settings') }}
                </Link>
            </Button>
        </header>

        <!-- A team with no watches has nothing to show and needs telling why. -->
        <EmptyState
            v-if="!watchCount"
            :icon="Satellite"
            :title="
                t('Set up your first :location watch', {
                    location: defaults.location,
                })
            "
            :description="
                t(
                    'Tell Alima where to look and what counts as an event. It checks the news every day and brings back what fits.',
                )
            "
            data-test="no-watches"
        >
            <Button v-if="can.manageWatches" as-child>
                <Link href="/signals/watches">{{ t('Set up a watch') }}</Link>
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Ask an admin on your team to set one up.') }}
            </p>
        </EmptyState>

        <template v-else>
            <!-- Always mounted: a live region inserted by v-if is often never announced. -->
            <p class="sr-only" role="status" aria-live="polite">
                {{ announcement }}
            </p>

            <!-- New signals arrived while the member was reading -->
            <div
                v-if="arrived > 0"
                class="flex flex-wrap items-center justify-between gap-3 rounded-2xl border border-border bg-card p-4"
                role="status"
                aria-live="polite"
                data-test="new-signals-banner"
            >
                <p class="text-sm text-foreground">
                    <!-- Count-neutral: Arabic needs dual and plural forms that a single
                         ":count signals" string can't give. -->
                    {{
                        t('New signals just landed: :count', { count: arrived })
                    }}
                </p>
                <Button variant="outline" size="sm" @click="showArrived">
                    {{ t('Show them') }}
                </Button>
            </div>

            <!-- Tabs -->
            <div class="space-y-4">
                <!-- Links, not tabs: each one navigates, so it can be opened in a new tab,
                     bookmarked and shared. `role="tab"` would promise arrow-key traversal and a
                     tabpanel that aren't there — and the Filters button could not live inside a
                     tablist, which only allows tabs. -->
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-border"
                >
                    <nav
                        class="flex flex-wrap items-center gap-2"
                        :aria-label="t('Signals')"
                    >
                        <Link
                            v-for="tab in tabs"
                            :key="tab.value"
                            :href="tabHref(tab.value)"
                            preserve-scroll
                            :aria-current="
                                filters.status === tab.value
                                    ? 'page'
                                    : undefined
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

                    <Button
                        variant="ghost"
                        size="sm"
                        class="ms-auto mb-1"
                        :aria-expanded="showFilters"
                        aria-controls="signal-filters"
                        @click="showFilters = !showFilters"
                    >
                        <SlidersHorizontal class="size-4" aria-hidden="true" />
                        {{ t('Filters') }}
                    </Button>
                </div>

                <!-- Filters -->
                <div
                    v-show="showFilters"
                    id="signal-filters"
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                >
                    <fieldset class="space-y-3">
                        <legend
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Event type') }}
                        </legend>
                        <div class="flex flex-wrap gap-2">
                            <Label
                                v-for="option in options.event_types"
                                :key="option.value"
                                class="flex cursor-pointer items-center gap-2 rounded-full border px-3 py-1.5 text-sm transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                :class="
                                    filters.event_types.includes(option.value)
                                        ? 'border-brand bg-brand/5 text-brand'
                                        : 'border-border text-foreground hover:border-brand/40'
                                "
                            >
                                <input
                                    type="checkbox"
                                    class="sr-only"
                                    :value="option.value"
                                    :checked="
                                        filters.event_types.includes(
                                            option.value,
                                        )
                                    "
                                    @change="toggleEventType(option.value)"
                                />
                                <!-- Selection has to be visible without colour (WCAG 1.4.1), so a
                                     tick appears rather than only the tint changing. -->
                                <Check
                                    v-if="
                                        filters.event_types.includes(
                                            option.value,
                                        )
                                    "
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{ t(option.label) }}
                            </Label>
                        </div>
                    </fieldset>

                    <div class="grid gap-4 sm:grid-cols-3">
                        <div class="space-y-2">
                            <Label for="filter-from">{{ t('From') }}</Label>
                            <Input
                                id="filter-from"
                                type="date"
                                :model-value="filters.from ?? ''"
                                @change="
                                    navigate({
                                        from:
                                            ($event.target as HTMLInputElement)
                                                .value || null,
                                    })
                                "
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="filter-to">{{ t('To') }}</Label>
                            <Input
                                id="filter-to"
                                type="date"
                                :model-value="filters.to ?? ''"
                                @change="
                                    navigate({
                                        to:
                                            ($event.target as HTMLInputElement)
                                                .value || null,
                                    })
                                "
                            />
                        </div>
                        <div class="space-y-2">
                            <Label for="filter-relevance">{{
                                t('Minimum relevance')
                            }}</Label>
                            <Input
                                id="filter-relevance"
                                type="number"
                                min="0"
                                max="100"
                                :model-value="filters.min_relevance ?? ''"
                                @change="
                                    navigate({
                                        min_relevance: (
                                            $event.target as HTMLInputElement
                                        ).value
                                            ? Number(
                                                  (
                                                      $event.target as HTMLInputElement
                                                  ).value,
                                              )
                                            : null,
                                    })
                                "
                            />
                        </div>
                    </div>

                    <Button
                        v-if="isNarrowed"
                        variant="ghost"
                        size="sm"
                        @click="clearFilters"
                    >
                        {{ t('Clear filters') }}
                    </Button>
                </div>
            </div>

            <!-- The feed -->
            <div
                v-if="signals.data.length"
                ref="listRef"
                tabindex="-1"
                class="space-y-4 focus:outline-none"
                data-test="signal-list"
            >
                <SignalCard
                    v-for="signal in signals.data"
                    :key="signal.id"
                    :signal="signal"
                    :can-action="can.action"
                    @convert="converting = $event"
                    @dismiss="dismissing = $event"
                    @restore="restore"
                />

                <Pagination
                    v-if="signals.meta.last_page > 1"
                    :links="signals.links"
                    :meta="signals.meta"
                    :label="t('Signal pages')"
                />
            </div>

            <!-- Narrowed to nothing is a different problem from nothing yet, and has a different fix. -->
            <EmptyState
                v-else-if="isNarrowed"
                :icon="SlidersHorizontal"
                :title="t('Nothing matches those filters')"
                :description="
                    t('Try widening the dates or lowering the minimum score.')
                "
                data-test="no-matches"
            >
                <Button variant="outline" @click="clearFilters">
                    {{ t('Clear filters') }}
                </Button>
            </EmptyState>

            <EmptyState
                v-else-if="filters.status === 'new'"
                :icon="Satellite"
                :title="t('No new signals right now')"
                :description="
                    t(
                        'Alima checks the news on your watch’s schedule. When something lands, it appears here — and we’ll tell you.',
                    )
                "
                data-test="no-signals"
            >
                <Button v-if="can.manageWatches" variant="outline" as-child>
                    <Link href="/signals/watches">{{
                        t('Check your watches')
                    }}</Link>
                </Button>
            </EmptyState>

            <EmptyState
                v-else
                :icon="Satellite"
                :title="
                    filters.status === 'converted'
                        ? t('Your first lead from a signal will land here')
                        : t('Nothing cleared away yet')
                "
                :description="
                    filters.status === 'converted'
                        ? t(
                              'Signals you turn into leads show up here, with a link straight to the lead.',
                          )
                        : t(
                              'Signals you dismiss wait here in case you change your mind.',
                          )
                "
                data-test="no-signals"
            />
        </template>

        <ConvertSignalModal
            :signal="converting"
            :stages="stages"
            @close="converting = null"
            @converted="onConverted"
        />
        <DismissSignalDialog
            :signal="dismissing"
            :reasons="options.dismiss_reasons"
            @close="dismissing = null"
            @dismissed="onDismissed"
        />
    </div>
</template>
