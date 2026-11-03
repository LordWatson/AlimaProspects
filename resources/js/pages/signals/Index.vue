<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    Building2,
    Satellite,
    Settings2,
    SlidersHorizontal,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { ActiveFilters, FilterGroup, FilterToggle } from '@/components/list';
import PageHero from '@/components/PageHero.vue';
import Pagination from '@/components/Pagination.vue';
import ConvertSignalModal from '@/components/signals/ConvertSignalModal.vue';
import DismissSignalDialog from '@/components/signals/DismissSignalDialog.vue';
import SignalCard from '@/components/signals/SignalCard.vue';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import { useNavigating } from '@/composables/useNavigating';
import type {
    ActiveFilterChip,
    PaginationLink,
    PipelineStage,
    Signal,
    SignalDefinition,
    SignalDismissReasonOption,
    SignalEventKey,
    SignalFilterState,
    SignalScope,
    SignalScopeOption,
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
        /** The team's own signal types — the chips are drawn from these since S7 (ADR 0018). */
        definitions: SignalDefinition[];
        dismiss_reasons: SignalDismissReasonOption[];
        /** The two halves of the feed (S10), each with the sentence its empty state shows. */
        scopes: SignalScopeOption[];
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

/**
 * The second axis (S10): whose companies these events are about.
 *
 * Deliberately **not** a fourth status tab. "New events about my accounts" and "converted events
 * about my accounts" are both questions somebody asks, so the two sets of tabs cross rather than
 * concatenate — and the status row keeps its counts, which are scoped to the chosen half.
 */
const scope = computed<SignalScope>(() => props.filters.scope ?? 'all');

const currentScope = computed<SignalScopeOption | undefined>(() =>
    props.options.scopes.find((option) => option.value === scope.value),
);

// --- Server-driven filtering --------------------------------------------------------------------
// Every change is a real navigation, so the URL always reproduces what is on screen and a shared
// link opens the same feed.
const showFilters = ref(
    props.filters.event_keys.length > 0 ||
        props.filters.from !== null ||
        props.filters.to !== null ||
        props.filters.min_relevance !== null,
);

/**
 * A type's name is Alima's words or the team's, and only the server knows which. Translating the
 * team's own wording would be the app overwriting what somebody typed.
 */
function typeLabel(definition: SignalDefinition): string {
    return definition.translatable ? t(definition.name) : definition.name;
}

type SignalQueryParams = {
    status: SignalStatus;
    /** Omitted when it is "everything", so the plain feed keeps the URL it always had. */
    scope?: SignalScope;
    event_keys: SignalEventKey[];
    from?: string;
    to?: string;
    min_relevance?: number;
};

/** The query a given filter state corresponds to — one definition for both links and navigation. */
function queryFor(changes: Partial<SignalFilterState>): SignalQueryParams {
    const next = { ...props.filters, ...changes };

    return {
        status: next.status,
        scope: next.scope === 'accounts' ? 'accounts' : undefined,
        event_keys: next.event_keys,
        from: next.from ?? undefined,
        to: next.to ?? undefined,
        min_relevance: next.min_relevance ?? undefined,
    };
}

/** The tab's href, so a tab is a real, shareable URL rather than a button that fetches. */
function tabHref(changes: Partial<SignalFilterState>): string {
    const query = queryFor(changes);
    const params = new URLSearchParams();

    params.set('status', query.status);
    if (query.scope) params.set('scope', query.scope);
    query.event_keys.forEach((key) => params.append('event_keys[]', key));
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

function toggleEventKey(key: SignalEventKey): void {
    navigate({
        event_keys: props.filters.event_keys.includes(key)
            ? props.filters.event_keys.filter((candidate) => candidate !== key)
            : [...props.filters.event_keys, key],
    });
}

function clearFilters(): void {
    navigate({
        event_keys: [],
        from: null,
        to: null,
        min_relevance: null,
    });
}

/**
 * Whether anything on this page is one event reported by several outlets (S11).
 *
 * The caption below the tabs is conditional on it rather than always on, because it explains
 * something the member can see on screen — the alternative is a standing sentence about clustering
 * on a feed where nothing happens to be clustered.
 */
const hasClusters = computed(() =>
    props.signals.data.some((signal) => signal.cluster !== null),
);

const isNarrowed = computed(
    () =>
        props.filters.event_keys.length > 0 ||
        props.filters.from !== null ||
        props.filters.to !== null ||
        props.filters.min_relevance !== null,
);

// A filter change is a round trip, so the feed says it is thinking rather than leaving stale
// cards looking like the answer.
const busy = useNavigating();

/**
 * What is narrowing the feed, in words, above it.
 *
 * The filters live behind a disclosure, which is right — they are used occasionally — but it means
 * a member can land on a shared link showing four signals out of two hundred with nothing on
 * screen to say why. Each chip names its group and removes only itself.
 */
const activeChips = computed<ActiveFilterChip[]>(() => {
    const chips: ActiveFilterChip[] = [];

    for (const key of props.filters.event_keys) {
        const definition = props.options.definitions.find(
            (candidate) => candidate.key === key,
        );

        chips.push({
            id: 'event_keys:' + key,
            group: t('Signal type'),
            // The server drops a key the team no longer has, so a chip without a definition should
            // not happen — the key is shown rather than a blank if it ever does.
            label: definition ? typeLabel(definition) : key,
        });
    }

    if (props.filters.from !== null) {
        chips.push({
            id: 'from:1',
            group: t('From'),
            label: props.filters.from,
        });
    }

    if (props.filters.to !== null) {
        chips.push({ id: 'to:1', group: t('To'), label: props.filters.to });
    }

    if (props.filters.min_relevance !== null) {
        chips.push({
            id: 'min_relevance:1',
            group: t('Minimum relevance'),
            label: String(props.filters.min_relevance),
        });
    }

    return chips;
});

function removeChip(chip: ActiveFilterChip): void {
    const [key, raw] = chip.id.split(':');

    if (key === 'event_keys') {
        navigate({
            event_keys: props.filters.event_keys.filter(
                (candidate) => candidate !== raw,
            ),
        });

        return;
    }

    if (key === 'from') navigate({ from: null });
    if (key === 'to') navigate({ to: null });
    if (key === 'min_relevance') navigate({ min_relevance: null });
}

// --- Modals -------------------------------------------------------------------------------------
const converting = ref<Signal | null>(null);
const dismissing = ref<Signal | null>(null);

/**
 * "Add this to Acme Ltd" — the match, accepted (S10).
 *
 * No modal: there is nothing to decide. Converting asks for a stage and an owner because it makes
 * a lead; this one adds an event to a lead that already has both. **Convert anyway** stays on the
 * card for the member who thinks the match is wrong, which is the whole reason the confidence is
 * shown next to it.
 */
function attach(signal: Signal): void {
    router.post(
        `/signals/${signal.id}/attach`,
        {},
        {
            preserveScroll: true,
            onSuccess: () =>
                announce(
                    t('Added to :company.', {
                        company: signal.matched_lead?.company_name ?? '',
                    }),
                ),
        },
    );
}

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
        <PageHero
            index="07"
            :eyebrow="t('Signals')"
            :title="t('Who just came into|money.')"
            :description="
                t(
                    'Company sales, funding rounds and founders moving to town — found in published news, summarised, and ranked for how well they fit you.',
                )
            "
            :stat="{
                label: t('New signals'),
                value: counts.new,
                caption: t('Waiting on you'),
            }"
        >
            <template v-if="can.manageWatches" #actions>
                <Button variant="hero" as-child>
                    <Link href="/signals/watches">
                        <Settings2 class="size-4" aria-hidden="true" />
                        {{ t('Watch settings') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

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
                <!-- Which half of the feed. Pills rather than a second underlined row, so the
                     two axes do not read as one list of six. -->
                <nav
                    class="flex flex-wrap items-center gap-2"
                    :aria-label="t('Which signals to show')"
                >
                    <Link
                        v-for="option in options.scopes"
                        :key="option.value"
                        :href="tabHref({ scope: option.value })"
                        preserve-scroll
                        :aria-current="
                            scope === option.value ? 'page' : undefined
                        "
                        :data-test="`scope-${option.value}`"
                        class="rounded-full border px-3 py-1.5 text-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :class="
                            scope === option.value
                                ? 'border-brand bg-brand/10 font-semibold text-brand rtl:font-bold'
                                : 'border-border font-medium text-muted-foreground hover:text-foreground'
                        "
                    >
                        {{ t(option.label) }}
                    </Link>

                    <Button
                        v-if="can.manageWatches"
                        variant="ghost"
                        size="sm"
                        class="ms-auto"
                        as-child
                    >
                        <Link href="/signals/accounts">
                            <Building2 class="size-4" aria-hidden="true" />
                            {{ t('Accounts') }}
                        </Link>
                    </Button>
                </nav>

                <!-- Links, not tabs: each one navigates, so it can be opened in a new tab,
                     bookmarked and shared. `role="tab"` would promise arrow-key traversal and a
                     tabpanel that aren't there — and the Filters button could not live inside a
                     tablist, which only allows tabs. -->
                <div
                    class="flex flex-wrap items-center gap-2 border-b border-border"
                >
                    <nav
                        class="flex flex-wrap items-center gap-2"
                        :aria-label="t('Signal status')"
                    >
                        <Link
                            v-for="tab in tabs"
                            :key="tab.value"
                            :href="tabHref({ status: tab.value })"
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

                <!-- What the numbers on those tabs count, said once and only where it applies:
                     one event, however many outlets ran it (S11). -->
                <p
                    v-if="hasClusters"
                    class="text-xs text-muted-foreground"
                    data-test="cluster-note"
                >
                    {{
                        t(
                            'One card per event, however many outlets reported it. The counts above work the same way.',
                        )
                    }}
                </p>

                <!-- Filters -->
                <div
                    v-show="showFilters"
                    id="signal-filters"
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                >
                    <!-- The same chip as the leads list, so a filter looks like a filter
                         wherever a member meets one. The tick is what keeps the on state from
                         being colour alone (WCAG 1.4.1). -->
                    <FilterGroup
                        v-if="options.definitions.length"
                        :legend="t('Signal type')"
                    >
                        <FilterToggle
                            v-for="definition in options.definitions"
                            :key="definition.id"
                            :pressed="
                                filters.event_keys.includes(definition.key)
                            "
                            :label="typeLabel(definition)"
                            :data-test="'filter-event-' + definition.key"
                            @toggle="toggleEventKey(definition.key)"
                        />
                    </FilterGroup>

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
                </div>

                <!-- What the filters are doing, where it cannot be scrolled past or folded
                     away with them. -->
                <ActiveFilters
                    :chips="activeChips"
                    @remove="removeChip"
                    @clear="clearFilters"
                />
            </div>

            <!-- The feed -->
            <div
                v-if="signals.data.length"
                ref="listRef"
                tabindex="-1"
                class="duration-fast space-y-4 transition-opacity ease-brand focus:outline-none"
                :class="busy ? 'opacity-55' : ''"
                :aria-busy="busy ? 'true' : undefined"
                data-test="signal-list"
            >
                <SignalCard
                    v-for="signal in signals.data"
                    :key="signal.id"
                    :signal="signal"
                    :can-action="can.action"
                    @convert="converting = $event"
                    @attach="attach"
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
                v-else-if="scope === 'accounts'"
                :icon="Building2"
                :title="t('Nothing about your own accounts yet')"
                :description="t(currentScope?.empty_message ?? '')"
                data-test="no-account-signals"
            >
                <Button v-if="can.manageWatches" variant="outline" as-child>
                    <Link href="/signals/accounts">{{
                        t('Set up an account list')
                    }}</Link>
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
