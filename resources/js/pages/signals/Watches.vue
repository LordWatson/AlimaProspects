<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pause, Play, Satellite, Trash2 } from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    watch as onChange,
} from 'vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import TuningSuggestions from '@/components/signals/TuningSuggestions.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Progress } from '@/components/ui/progress';
import { Textarea } from '@/components/ui/textarea';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    SignalCadenceOption,
    SignalDefinition,
    SignalRegionOption,
    SignalRunProgressPayload,
    SignalRunStatus,
    SignalWatch,
    SignalWatchDefaults,
    SignalWatchForm,
    SignalWatchlistOption,
    SignalTuningSuggestion,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Signals', href: '/signals' },
            { title: 'Watch settings', href: '/signals/watches' },
        ],
    },
});

const props = defineProps<{
    watches: SignalWatch[];
    options: {
        /** The team's own signal types — what a watch is built from since S7 (ADR 0018). */
        definitions: SignalDefinition[];
        /** Where a watch may look. Only regions the business has a compliance position for. */
        regions: SignalRegionOption[];
        /** The cap on places, so the field can say it rather than the server enforcing it alone. */
        max_locations: number;
        cadences: SignalCadenceOption[];
        icps: { id: number; name: string; is_active: boolean }[];
        /**
         * The lists a watch can follow instead of places (S10). Only the team's active lists with
         * something on them: pointing a watch at an empty one is a watch that searches nothing.
         */
        watchlists: SignalWatchlistOption[];
    };
    defaults: SignalWatchDefaults;
    /**
     * What the product suggests the team change, with the evidence (S12).
     *
     * Open proposals only, capped by `signals.tuning.max_open` — a panel of thirty suggestions is
     * a backlog, and a backlog is ignored.
     */
    suggestions: SignalTuningSuggestion[];
    can: { manage: boolean };
}>();

const { t, locale } = useI18n();
const { currency, dateTime } = useFormat();
const page = usePage();

// --- The editor -------------------------------------------------------------------------------
// One form serves both create and edit; `editing` holds the watch being changed, or null for new.
const editing = ref<SignalWatch | null>(null);
const showEditor = ref(false);

const form = useForm<SignalWatchForm>({
    name: '',
    locations: [...props.defaults.locations],
    regions: [...props.defaults.regions],
    signal_definition_ids: [],
    ignore_domains: [],
    cadence: props.defaults.cadence,
    is_active: true,
    icp_id: null,
    signal_watchlist_id: null,
});

/**
 * One place per line, which is how somebody thinks about them, parsed into the form as they type.
 *
 * **Deliberately a ref and a one-way sync rather than a two-way computed.** The first version was
 * a computed whose setter dropped blank lines and whose getter wrote the filtered text straight
 * back into the control — so pressing Enter at the end of the text removed the line break it had
 * just inserted, and the only way to add a second place was to split an existing line or paste
 * one in. The textarea was not keyboard-operable for the thing it exists to do (WCAG 2.1.1,
 * found by the S8 brand review). The raw text owns the control, the parse feeds the form, and
 * nothing writes back.
 */
const locationsText = ref(props.defaults.locations.join('\n'));

/** One entry per non-blank line. The server squishes, de-duplicates and caps them again. */
function parseLines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}

onChange(locationsText, (value) => {
    form.locations = parseLines(value);
});

/**
 * How many paid searches one check of this watch would ask for, as the member builds it.
 *
 * Places × regions × every template of every type ticked — the arithmetic from ADR 0019 §4, which
 * is the number that actually matters and the one a member cannot otherwise see. "Two places cost
 * twice as much" is true and abstract; "48 searches" is a number somebody can decide about.
 */
const searchesPerCheck = computed(() => {
    const places = Math.max(form.locations.length, 1);
    const regions = Math.max(form.regions.length, 1);
    const templates = props.options.definitions
        .filter((definition) =>
            form.signal_definition_ids.includes(definition.id),
        )
        .reduce(
            (total, definition) => total + definition.query_templates.length,
            0,
        );

    return places * regions * templates;
});

/**
 * Every place the one-click watch would cover, as a list somebody can read.
 *
 * It named only the first place until the S8 brand review caught it: the UAE default is Dubai *and*
 * Abu Dhabi, so "watch Dubai every day" was quietly committing the team to twice the searches it
 * described. A promise on a button has to be the whole promise.
 */
const defaultPlaces = computed(() =>
    new Intl.ListFormat(locale.value, {
        style: 'long',
        type: 'conjunction',
    }).format(props.defaults.locations),
);

/** The first place of the region the team's own market points at — never a hard-coded city. */
const placeholderPlace = computed(
    () =>
        props.options.regions.find(
            (region) => region.value === props.defaults.regions[0],
        )?.default_locations?.[0] ?? '',
);

function toggleRegion(value: string): void {
    form.regions = form.regions.includes(value)
        ? form.regions.filter((region) => region !== value)
        : [...form.regions, value];
}

/**
 * What choosing these regions means for how the resulting leads may be approached.
 *
 * Shown because the watch form is where a member picks where to look, and that is also where they
 * pick which rules apply — the one thing on this screen with a legal consequence, and nothing else
 * would say so (ADR 0019).
 */
const approachNotes = computed(() =>
    props.options.regions
        .filter((region: SignalRegionOption) =>
            form.regions.includes(region.value),
        )
        .map((region: SignalRegionOption) => ({
            value: region.value,
            label: region.label,
            note: region.approach_note,
        })),
);

/**
 * A type's name is Alima's words or the team's, and only the server knows which — see
 * `SignalDefinition::usesPlatformWording()`. Translating the team's own wording would be the app
 * overwriting what somebody typed.
 */
function typeLabel(definition: SignalDefinition): string {
    return definition.translatable ? t(definition.name) : definition.name;
}

function typeExplainer(definition: SignalDefinition): string {
    return definition.translatable
        ? t(definition.description)
        : definition.description;
}

/** Every active type the team has, which is what a new watch starts ticked. */
const everyTypeId = computed(() =>
    props.options.definitions.map((definition) => definition.id),
);

/**
 * The exclusion list, one domain per line. Same shape as `locationsText`, and fixed in S8 for the
 * same reason: this one had the Enter-key defect since S6, and nobody had tried to add a second
 * domain from the keyboard.
 */
const ignoreDomainsText = ref('');

onChange(ignoreDomainsText, (value) => {
    form.ignore_domains = parseLines(value);
});

function openNew(): void {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        name: '',
        locations: [...props.defaults.locations],
        regions: [...props.defaults.regions],
        // Pre-ticked: the broadest useful watch is the one most likely to find something, and a
        // first watch that finds nothing teaches the team that Signals doesn't work.
        signal_definition_ids: [...everyTypeId.value],
        ignore_domains: [],
        cadence: props.defaults.cadence,
        is_active: true,
        icp_id: props.options.icps.find((icp) => icp.is_active)?.id ?? null,
        // Places by default. A watch over the team's own accounts is the warmer question, but it
        // is a deliberate choice rather than something to be opted into by omission.
        signal_watchlist_id: null,
    });
    form.reset();
    // The two free-text lists own their own control, so they are seeded alongside the form rather
    // than derived from it — see `locationsText`.
    locationsText.value = props.defaults.locations.join('\n');
    ignoreDomainsText.value = '';
    showEditor.value = true;
}

function openEdit(watch: SignalWatch): void {
    editing.value = watch;
    form.clearErrors();
    form.defaults({
        name: watch.name,
        locations: [...watch.locations],
        regions: watch.regions.map((region) => region.value),
        signal_definition_ids: (watch.definitions ?? []).map(
            (definition) => definition.id,
        ),
        ignore_domains: [...watch.ignore_domains],
        cadence: watch.cadence,
        is_active: watch.is_active,
        icp_id: watch.icp?.id ?? null,
        signal_watchlist_id: watch.signal_watchlist_id,
    });
    form.reset();
    locationsText.value = watch.locations.join('\n');
    ignoreDomainsText.value = watch.ignore_domains.join('\n');
    showEditor.value = true;
}

/** The list the form is currently pointed at, if any (S10). */
const followedList = computed(() =>
    props.options.watchlists.find(
        (list) => list.id === form.signal_watchlist_id,
    ),
);

function toggleDefinition(id: number): void {
    form.signal_definition_ids = form.signal_definition_ids.includes(id)
        ? form.signal_definition_ids.filter((candidate) => candidate !== id)
        : [...form.signal_definition_ids, id];
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        form.patch(`/signals/watches/${editing.value.id}`, options);
    } else {
        form.post('/signals/watches', options);
    }
}

/** Switch a watch on or off without opening the editor — the one change members make most. */
function toggleActive(watch: SignalWatch): void {
    router.patch(
        `/signals/watches/${watch.id}`,
        {
            name: watch.name,
            locations: watch.locations,
            regions: watch.regions.map((region) => region.value),
            signal_definition_ids: (watch.definitions ?? []).map(
                (definition) => definition.id,
            ),
            // Carried through unchanged: this posts the whole watch, and dropping the list here
            // would quietly clear it every time someone paused a watch.
            ignore_domains: watch.ignore_domains,
            cadence: watch.cadence,
            is_active: !watch.is_active,
            icp_id: watch.icp?.id ?? null,
            // Carried through for the same reason as the skip-list: this posts the whole watch,
            // and dropping it here would quietly unpoint the watch every time somebody paused it.
            signal_watchlist_id: watch.signal_watchlist_id,
        },
        { preserveScroll: true },
    );
}

function createDefault(): void {
    router.post('/signals/watches/default', {}, { preserveScroll: true });
}

// --- Removal ----------------------------------------------------------------------------------
// Confirmed, and the confirmation says what goes with it — deleting a watch takes its signals.
const removing = ref<SignalWatch | null>(null);

function confirmRemove(): void {
    if (!removing.value) return;

    router.delete(`/signals/watches/${removing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = null;
        },
    });
}

// --- Run now, with live progress ----------------------------------------------------------------

/**
 * What the bar shows between the click and the worker's first broadcast. The run really is only
 * queued at that point, so it opens at a sliver rather than claiming a stage it hasn't reached —
 * the same opening figure `lead-sourcing/Show.vue` and `business-intel/Review.vue` use.
 */
const INITIAL_PERCENT = 10;

/** Watches whose "Run now" request is still in flight, so the button answers the click itself. */
const posting = ref<number[]>([]);

function runNow(watch: SignalWatch): void {
    router.post(
        `/signals/watches/${watch.id}/run`,
        {},
        {
            preserveScroll: true,
            // The press needs an answer before the round trip finishes. `onFinish` fires whether
            // the run was queued or refused (a paused watch and the 15-minute throttle both come
            // back as a toast), so the button can't stick.
            onStart: () => {
                posting.value = [...posting.value, watch.id];
                // Announce the press itself: the queued stretch before the worker's first
                // broadcast would otherwise be silent, which is the complaint this change is
                // about. A refusal lands as an error toast moments later and supersedes it.
                announcement.value = t(':name: :step', {
                    name: watch.name,
                    step: t('Starting the check'),
                });
            },
            onFinish: () => {
                posting.value = posting.value.filter((id) => id !== watch.id);
            },
        },
    );
}

function isPosting(watch: SignalWatch): boolean {
    return posting.value.includes(watch.id);
}

const teamId = computed(() => page.props.auth.currentTeam?.id ?? null);

/**
 * Live progress **by watch id**, not by run id. "Run now" redirects as soon as the run is queued,
 * and a card cannot know the id of a run it is waiting on — keyed by watch, each card picks up its
 * own progress from the first broadcast. A watch has at most one live run (`ShouldBeUnique` on the
 * job), so its id is an unambiguous key.
 */
const liveProgress = ref<Record<number, SignalRunProgressPayload>>({});
let channelName: string | null = null;

/** What the live region says about the most recent stage, so the work isn't sighted-only. */
const announcement = ref('');

/** The newest run of a watch — what "last run" and the live progress both read. */
function latestRun(watch: SignalWatch) {
    return watch.runs?.[0] ?? null;
}

function progressFor(watch: SignalWatch): SignalRunProgressPayload | null {
    return liveProgress.value[watch.id] ?? null;
}

function statusFor(watch: SignalWatch): SignalRunStatus | null {
    return progressFor(watch)?.status ?? latestRun(watch)?.status ?? null;
}

function isRunning(watch: SignalWatch): boolean {
    const status = statusFor(watch);

    return status === 'queued' || status === 'running';
}

/** 0–100 for the bar: the broadcast's own figure once one has arrived, else the opening sliver. */
function percentFor(watch: SignalWatch): number {
    return progressFor(watch)?.percent ?? INITIAL_PERCENT;
}

/**
 * The stage line under the bar. A broadcast carries its own ("Searching the news", "Analysing what
 * we found"); before the first one the honest answer depends on whether a worker has the job yet.
 */
function stepFor(watch: SignalWatch): string {
    const step = progressFor(watch)?.step;

    if (step) {
        return t(step);
    }

    return statusFor(watch) === 'queued'
        ? t('Waiting its turn')
        : t('Starting the check');
}

// Labels mirror SignalRunStatus::label() so a live (Reverb) status reads like a server one.
const runStatusLabel: Record<SignalRunStatus, string> = {
    queued: 'Queued',
    running: 'Looking',
    completed: 'Done',
    failed: 'Failed',
};

const runStatusVariant: Record<
    SignalRunStatus,
    'success' | 'info' | 'outline' | 'error'
> = {
    // Queued is waiting, not a problem — `outline`, not `warning`.
    queued: 'outline',
    running: 'info',
    completed: 'success',
    failed: 'error',
};

function onProgress(payload: SignalRunProgressPayload): void {
    liveProgress.value = { ...liveProgress.value, [payload.watch_id]: payload };

    const name =
        props.watches.find((watch) => watch.id === payload.watch_id)?.name ??
        '';

    if (payload.status === 'completed') {
        announcement.value = t(':name finished. Signals found: :count', {
            name,
            count: payload.signals_created,
        });
    } else if (payload.status === 'failed') {
        announcement.value = t(':name failed', { name });
    } else {
        // A key, not `${name}: ${step}` — the separator and the order are the translator's to
        // decide, and a hardcoded colon doesn't reorder under RTL.
        announcement.value = t(':name: :step', { name, step: t(payload.step) });
    }

    // When a run settles, pull the real counts and cost for the whole list, then let go of the
    // live entry — the refreshed card carries the outcome in "Recent checks", and a stale
    // `completed` here would hide the next run's `queued`.
    if (payload.status === 'completed' || payload.status === 'failed') {
        router.reload({
            only: ['watches'],
            onSuccess: () => {
                const remaining = { ...liveProgress.value };
                delete remaining[payload.watch_id];

                liveProgress.value = remaining;
            },
        });
    }
}

onMounted(() => {
    if (teamId.value == null || !window.Echo) return;

    channelName = `teams.${teamId.value}`;
    window.Echo.private(channelName).listen(
        '.signal-run.progressed',
        onProgress,
    );
});

onBeforeUnmount(() => {
    if (channelName && window.Echo) {
        window.Echo.leave(channelName);
    }
});
</script>

<template>
    <Head :title="t('Watch settings')" />

    <div class="flex flex-col gap-8 py-2">
        <!-- Compact: a settings screen under Signals, not a destination of its own. -->
        <PageHero
            index="07"
            :eyebrow="t('Watch settings')"
            :title="t('Tell Alima where|to look.')"
            :description="
                t(
                    'A watch is a standing instruction: a place, the kinds of event that matter, and how often to check.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="can.manage && watches.length"
                    variant="lime"
                    @click="openNew"
                >
                    {{ t('New watch') }}
                </Button>
                <Button variant="outline" as-child>
                    <Link href="/signals/types">
                        {{ t('Signal types') }}
                    </Link>
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/signals">
                        <ArrowLeft
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                        {{ t('Back to signals') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <!-- Always mounted, so a screen reader is already listening when a stage lands: a region
             that appears with its own first message is announced inconsistently. Carries the
             watch's name, because any of the cards below could be the one that moved. -->
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <!-- What the team could tune, from what they have kept and dismissed (S12).
             Above the watches, because it is the thing they have not seen before — and it renders
             nothing at all when there is nothing to say, which is most weeks. -->
        <TuningSuggestions
            :suggestions="suggestions"
            :can-manage="can.manage"
        />

        <!-- First run: one click to the broadest useful watch. -->
        <EmptyState
            v-if="!watches.length"
            :icon="Satellite"
            :title="
                t('Set up your first :location watch', {
                    location: defaultPlaces,
                })
            "
            :description="
                t(
                    'We’ll add Alima’s signal types and watch :location every day — company sales, funding rounds, founders moving in. You can change any of it afterwards.',
                    { location: defaultPlaces },
                )
            "
            data-test="no-watches"
        >
            <template v-if="can.manage">
                <Button data-test="create-default-watch" @click="createDefault">
                    {{
                        t('Watch :location for me', {
                            location: defaultPlaces,
                        })
                    }}
                </Button>
                <Button variant="outline" @click="openNew">
                    {{ t('Set one up myself') }}
                </Button>
            </template>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Ask an admin on your team to set one up.') }}
            </p>
        </EmptyState>

        <!-- The watches -->
        <div v-else class="space-y-4" data-test="watch-list">
            <Card
                v-for="watch in watches"
                :key="watch.id"
                :data-test="`watch-${watch.id}`"
            >
                <CardContent class="space-y-5">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <h2
                                    class="text-lg font-semibold text-foreground rtl:font-bold"
                                    dir="auto"
                                >
                                    {{ watch.name }}
                                </h2>
                                <Badge
                                    :variant="
                                        watch.is_active ? 'success' : 'outline'
                                    "
                                >
                                    {{
                                        watch.is_active ? t('On') : t('Paused')
                                    }}
                                </Badge>
                                <Badge
                                    v-if="isRunning(watch)"
                                    variant="info"
                                    data-test="watch-running"
                                >
                                    <!-- Decorative: the badge's own text is the status, and
                                         `Spinner` otherwise contributes a `role="status"` of its
                                         own — one per running card, all talking over the single
                                         live region above the list. -->
                                    <Spinner
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                    {{
                                        t(
                                            runStatusLabel[
                                                statusFor(watch) ?? 'running'
                                            ],
                                        )
                                    }}
                                </Badge>
                            </div>
                            <!-- What it covers. A watch pointed at a list says so instead of its
                                 places, because the places are not what it searches (S10). -->
                            <p
                                class="text-base text-muted-foreground"
                                dir="auto"
                            >
                                <template v-if="watch.watchlist_name">
                                    {{
                                        t('Following :name', {
                                            name: watch.watchlist_name,
                                        })
                                    }}
                                </template>
                                <template v-else>
                                    {{ watch.locations.join(' · ') }}
                                </template>
                                · {{ t(watch.cadence_label) }}
                                <template v-if="watch.icp">
                                    · {{ t('shaped by') }} {{ watch.icp.name }}
                                </template>
                            </p>
                            <!-- Where it searches, and the compliance note each region implies.
                                 A member reading a watch should be able to see why its leads may
                                 only be approached one way. -->
                            <p
                                v-for="region in watch.regions"
                                :key="region.value"
                                class="text-base text-muted-foreground"
                                dir="auto"
                            >
                                {{ t(region.label) }} —
                                {{ t(region.approach_note) }}
                            </p>
                        </div>

                        <div
                            v-if="can.manage"
                            class="flex flex-wrap items-center gap-2"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                :data-test="`toggle-${watch.id}`"
                                @click="toggleActive(watch)"
                            >
                                <!-- Play is a directional triangle, so it mirrors under RTL;
                                     Pause is symmetrical and must not. -->
                                <component
                                    :is="watch.is_active ? Pause : Play"
                                    class="size-4"
                                    :class="
                                        !watch.is_active && 'rtl:rotate-180'
                                    "
                                    aria-hidden="true"
                                />
                                {{
                                    watch.is_active
                                        ? t('Pause')
                                        : t('Switch on')
                                }}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                @click="openEdit(watch)"
                            >
                                {{ t('Edit') }}
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :disabled="
                                    !watch.is_active ||
                                    isRunning(watch) ||
                                    isPosting(watch)
                                "
                                :data-test="`run-${watch.id}`"
                                @click="runNow(watch)"
                            >
                                <!-- Decorative, so the button's accessible name stays "Running"
                                     rather than `Spinner`'s untranslated "Loading" plus it. -->
                                <Spinner
                                    v-if="isPosting(watch) || isRunning(watch)"
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                {{
                                    isPosting(watch) || isRunning(watch)
                                        ? t('Running')
                                        : t('Run now')
                                }}
                            </Button>
                            <Button
                                variant="ghost"
                                size="sm"
                                :aria-label="
                                    t('Remove :name', { name: watch.name })
                                "
                                :data-test="`remove-${watch.id}`"
                                @click="removing = watch"
                            >
                                <Trash2 class="size-4" aria-hidden="true" />
                            </Button>
                        </div>
                    </div>

                    <!-- Live progress. The badge above says *that* something is happening; this
                         says what stage it has reached and how far along, which is the difference
                         between a spinner and feedback. No `aria-live` here on purpose — several
                         cards can be running, so the announcement is made once at page level with
                         the watch's name attached (see the live region above the list). -->
                    <div
                        v-if="isRunning(watch)"
                        class="space-y-2"
                        :data-test="`progress-${watch.id}`"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                        >
                            <p
                                :id="`progress-step-${watch.id}`"
                                class="text-sm text-foreground"
                                dir="auto"
                            >
                                {{ stepFor(watch) }}
                            </p>
                            <p
                                class="font-mono text-xs text-muted-foreground tabular-nums"
                                dir="ltr"
                            >
                                {{ percentFor(watch) }}%
                            </p>
                        </div>
                        <Progress
                            :value="percentFor(watch)"
                            :label="t('Checking :name', { name: watch.name })"
                            :aria-describedby="`progress-step-${watch.id}`"
                        />
                        <!-- Only until the worker reports in. Reassurance is what the wait needs;
                             once the stages are moving they say it better than a sentence can. -->
                        <p
                            v-if="!progressFor(watch)"
                            class="text-xs text-muted-foreground"
                        >
                            {{
                                t(
                                    'A check takes a minute or two. You can leave this page — we’ll keep going.',
                                )
                            }}
                        </p>
                    </div>

                    <!-- What it watches for -->
                    <div class="flex flex-wrap gap-2">
                        <Badge
                            v-for="definition in watch.definitions ?? []"
                            :key="definition.id"
                            variant="lilac"
                            dir="auto"
                        >
                            {{ typeLabel(definition) }}
                        </Badge>
                    </div>

                    <!-- Last run, next due, and what it has found -->
                    <dl
                        class="grid gap-x-6 gap-y-3 border-t border-border pt-4 sm:grid-cols-4"
                    >
                        <div class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Signals found') }}
                            </dt>
                            <dd
                                class="font-mono text-sm text-foreground tabular-nums"
                                dir="ltr"
                            >
                                {{ watch.signals_count ?? 0 }}
                            </dd>
                        </div>
                        <div class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Last checked') }}
                            </dt>
                            <dd class="text-sm text-foreground">
                                <time
                                    v-if="watch.last_run_at"
                                    :datetime="watch.last_run_at"
                                    >{{ dateTime(watch.last_run_at) }}</time
                                >
                                <span v-else class="text-muted-foreground">{{
                                    t('Not yet')
                                }}</span>
                            </dd>
                        </div>
                        <div class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Next check') }}
                            </dt>
                            <dd class="text-sm text-foreground">
                                <time
                                    v-if="watch.next_due_at"
                                    :datetime="watch.next_due_at"
                                    >{{ dateTime(watch.next_due_at) }}</time
                                >
                                <span v-else class="text-muted-foreground">{{
                                    watch.is_active
                                        ? t('Within the hour')
                                        : t('Paused')
                                }}</span>
                            </dd>
                        </div>
                        <div class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Search cost so far (USD)') }}
                            </dt>
                            <dd
                                class="font-mono text-sm text-foreground tabular-nums"
                                dir="ltr"
                            >
                                {{
                                    currency(
                                        Math.round(
                                            (watch.runs ?? []).reduce(
                                                (total, run) =>
                                                    total + run.cost_micros,
                                                0,
                                            ) / 10_000,
                                        ),
                                        'USD',
                                    )
                                }}
                            </dd>
                        </div>
                    </dl>

                    <!-- Recent runs -->
                    <div v-if="watch.runs?.length" class="space-y-2">
                        <h3
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Recent checks') }}
                        </h3>
                        <ul class="space-y-1.5">
                            <li
                                v-for="run in watch.runs"
                                :key="run.id"
                                class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                            >
                                <Badge :variant="runStatusVariant[run.status]">
                                    {{ t(runStatusLabel[run.status]) }}
                                </Badge>
                                <time
                                    v-if="run.created_at"
                                    class="text-muted-foreground"
                                    :datetime="run.created_at"
                                    >{{ dateTime(run.created_at) }}</time
                                >
                                <span class="text-foreground">
                                    {{
                                        t('Found: :count', {
                                            count: run.signals_created,
                                        })
                                    }}
                                </span>
                                <!-- A trim is coverage this run did not have, so it is said rather
                                     than left for somebody to infer from a count that stopped
                                     growing (ADR 0019 §4). -->
                                <span
                                    v-if="run.queries_trimmed > 0"
                                    class="text-warning"
                                    :data-test="`trimmed-${run.id}`"
                                >
                                    {{
                                        t(
                                            'Searches skipped to stay in budget: :count — use fewer places or types to cover everything.',
                                            {
                                                count: run.queries_trimmed,
                                            },
                                        )
                                    }}
                                </span>
                                <span
                                    v-if="run.error"
                                    class="text-destructive"
                                    dir="auto"
                                    >{{ run.error }}</span
                                >
                            </li>
                        </ul>
                    </div>
                </CardContent>
            </Card>
        </div>

        <!-- Editor -->
        <Dialog v-model:open="showEditor">
            <DialogContent class="sm:max-w-xl" data-test="watch-editor">
                <form class="space-y-6" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>
                            {{ editing ? t('Edit watch') : t('New watch') }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'Alima searches published news for these kinds of event in this place.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-4">
                        <div class="space-y-2">
                            <Label for="watch-name">{{ t('Name') }}</Label>
                            <Input
                                id="watch-name"
                                v-model="form.name"
                                name="name"
                                required
                                dir="auto"
                            />
                            <InputError :message="form.errors.name" />
                        </div>

                        <!-- What this watch follows (S10, ADR 0021). A watch asks what happened
                             **in a place** or what happened **to these companies**, never both —
                             so this is one select rather than two half-used sections, and the
                             places below stay on the row either way, so unpointing a watch gives
                             the member back the geography they chose. -->
                        <div v-if="options.watchlists.length" class="space-y-2">
                            <Label for="watch-follows">{{
                                t('What this watch follows')
                            }}</Label>
                            <select
                                id="watch-follows"
                                v-model="form.signal_watchlist_id"
                                name="signal_watchlist_id"
                                class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-sm transition-colors focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                aria-describedby="watch-follows-hint"
                                data-test="watch-follows"
                            >
                                <option :value="null">
                                    {{
                                        t('Places — what happens where I sell')
                                    }}
                                </option>
                                <option
                                    v-for="list in options.watchlists"
                                    :key="list.id"
                                    :value="list.id"
                                >
                                    {{
                                        t('My accounts: :name (:count)', {
                                            name: list.name,
                                            count: list.entries_count,
                                        })
                                    }}
                                </option>
                            </select>
                            <p
                                id="watch-follows-hint"
                                class="text-base text-muted-foreground"
                            >
                                {{
                                    followedList
                                        ? t(
                                              'This watch asks what happened to those companies, a few at a time, instead of what happened in the places below.',
                                          )
                                        : t(
                                              'Point it at a list of accounts to watch companies you already work instead of places.',
                                          )
                                }}
                            </p>
                            <InputError
                                :message="form.errors.signal_watchlist_id"
                            />
                        </div>

                        <div class="space-y-2">
                            <Label for="watch-locations">{{
                                t('Where')
                            }}</Label>
                            <Textarea
                                id="watch-locations"
                                v-model="locationsText"
                                rows="3"
                                required
                                :aria-invalid="
                                    form.errors.locations ? true : undefined
                                "
                                aria-describedby="watch-locations-hint watch-locations-cost watch-locations-error"
                                :placeholder="placeholderPlace"
                                dir="auto"
                            />
                            <p
                                id="watch-locations-hint"
                                class="text-base text-muted-foreground"
                            >
                                {{
                                    t(
                                        'One place per line — a city, an emirate, a region. Up to :max.',
                                        { max: options.max_locations },
                                    )
                                }}
                            </p>
                            <!-- The arithmetic, live. Every place is searched for every type in
                                 every region, and that multiplication is the one thing about this
                                 field a member cannot see for themselves (ADR 0019 §4). -->
                            <p
                                id="watch-locations-cost"
                                class="font-mono text-xs text-muted-foreground"
                                data-test="searches-per-check"
                            >
                                {{
                                    t('Searches per check: :count', {
                                        count: searchesPerCheck,
                                    })
                                }}
                            </p>
                            <InputError
                                id="watch-locations-error"
                                :message="form.errors.locations"
                            />
                        </div>

                        <fieldset
                            class="space-y-3"
                            :aria-invalid="
                                form.errors.regions ? true : undefined
                            "
                            aria-describedby="watch-regions-error"
                        >
                            <legend
                                class="text-base font-medium text-foreground rtl:font-bold"
                            >
                                {{ t('Which news to search') }}
                            </legend>
                            <div class="space-y-2">
                                <Label
                                    v-for="region in options.regions"
                                    :key="region.value"
                                    class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 accent-brand"
                                        :value="region.value"
                                        :checked="
                                            form.regions.includes(region.value)
                                        "
                                        @change="toggleRegion(region.value)"
                                    />
                                    <span class="space-y-0.5">
                                        <span
                                            class="block text-base font-semibold text-foreground rtl:font-bold"
                                            >{{ t(region.label) }}</span
                                        >
                                        <!-- Not muted. This sentence is the compliance
                                             consequence of ticking the box, not a hint about
                                             how to use the control. -->
                                        <span
                                            class="block text-base text-foreground"
                                            dir="auto"
                                            >{{ t(region.approach_note) }}</span
                                        >
                                    </span>
                                </Label>
                            </div>
                            <InputError
                                id="watch-regions-error"
                                :message="form.errors.regions"
                            />
                        </fieldset>

                        <fieldset class="space-y-3">
                            <legend
                                class="text-sm font-medium text-foreground rtl:font-bold"
                            >
                                {{ t('What counts as an event') }}
                            </legend>

                            <!-- A checkbox per type the team has, rather than three fixed ones
                                 (S7, ADR 0018). A team with none is sent to the library instead of
                                 shown an empty fieldset — the one-click first watch is the path
                                 that never reaches this state. -->
                            <div
                                v-if="options.definitions.length"
                                class="space-y-2"
                            >
                                <Label
                                    v-for="definition in options.definitions"
                                    :key="definition.id"
                                    class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 accent-brand"
                                        :value="definition.id"
                                        :checked="
                                            form.signal_definition_ids.includes(
                                                definition.id,
                                            )
                                        "
                                        @change="
                                            toggleDefinition(definition.id)
                                        "
                                    />
                                    <span class="space-y-0.5" dir="auto">
                                        <span
                                            class="block text-base font-semibold text-foreground rtl:font-bold"
                                            >{{ typeLabel(definition) }}</span
                                        >
                                        <span
                                            class="block text-base text-muted-foreground"
                                            >{{
                                                typeExplainer(definition)
                                            }}</span
                                        >
                                    </span>
                                </Label>
                            </div>
                            <div
                                v-else
                                class="space-y-3 rounded-2xl border border-border p-4"
                                data-test="no-types-for-watch"
                            >
                                <p class="text-base text-foreground">
                                    {{
                                        t(
                                            'You have no signal types yet, so there is nothing for a watch to look for.',
                                        )
                                    }}
                                </p>
                                <Button variant="outline" size="sm" as-child>
                                    <Link href="/signals/types">
                                        {{ t('Pick your signal types') }}
                                    </Link>
                                </Button>
                            </div>
                            <InputError
                                :message="form.errors.signal_definition_ids"
                            />
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="space-y-2">
                                <Label for="watch-cadence">{{
                                    t('How often')
                                }}</Label>
                                <select
                                    id="watch-cadence"
                                    v-model="form.cadence"
                                    name="cadence"
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm"
                                >
                                    <option
                                        v-for="cadence in options.cadences"
                                        :key="cadence.value"
                                        :value="cadence.value"
                                    >
                                        {{ t(cadence.label) }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.cadence" />
                            </div>

                            <div v-if="options.icps.length" class="space-y-2">
                                <Label for="watch-icp">{{
                                    t('Shaped by (optional)')
                                }}</Label>
                                <select
                                    id="watch-icp"
                                    v-model="form.icp_id"
                                    name="icp_id"
                                    class="h-9 w-full rounded-md border border-input bg-transparent px-3 py-1 text-base shadow-xs transition-[color,box-shadow] focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none md:text-sm"
                                >
                                    <option :value="null">
                                        {{ t('No ideal customer profile') }}
                                    </option>
                                    <option
                                        v-for="icp in options.icps"
                                        :key="icp.id"
                                        :value="icp.id"
                                    >
                                        {{ icp.name }}
                                    </option>
                                </select>
                                <InputError :message="form.errors.icp_id" />
                            </div>
                        </div>

                        <div class="space-y-2">
                            <Label for="watch-ignore">{{
                                t('Sources to skip (optional)')
                            }}</Label>
                            <Textarea
                                id="watch-ignore"
                                v-model="ignoreDomainsText"
                                rows="3"
                                aria-describedby="watch-ignore-hint"
                                placeholder="rival-agency.ae"
                                dir="ltr"
                            />
                            <p
                                id="watch-ignore-hint"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    t(
                                        'One website per line. Use this for competitors whose news keeps turning up.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.ignore_domains" />
                        </div>

                        <Label
                            class="flex cursor-pointer items-center gap-3 rounded-2xl border border-border p-3 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                name="is_active"
                                class="size-4 accent-brand"
                            />
                            <span class="text-sm text-foreground">{{
                                t('Check for events automatically')
                            }}</span>
                        </Label>
                    </div>

                    <!-- Said again, together, immediately before Save: picking where to look
                         is also picking how these leads may be approached, and that is the one
                         consequence on this form somebody should not be able to miss. It sat under
                         the region choices until the S8 brand review pointed out that four more
                         groups came after it, so at phone width it was off screen by the time
                         anybody reached the button it claimed to sit beside. -->
                    <section
                        v-if="approachNotes.length"
                        class="space-y-1 rounded-2xl border border-border p-4"
                        aria-labelledby="approach-notes-heading"
                        data-test="approach-notes"
                    >
                        <h3
                            id="approach-notes-heading"
                            class="text-base font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('How you may approach these leads') }}
                        </h3>
                        <p
                            v-for="note in approachNotes"
                            :key="note.value"
                            class="text-base text-foreground"
                            dir="auto"
                        >
                            {{ t(note.label) }} — {{ t(note.note) }}
                        </p>
                    </section>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showEditor = false"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="watch-submit"
                        >
                            {{
                                form.processing
                                    ? t('Saving…')
                                    : editing
                                      ? t('Save changes')
                                      : t('Start watching')
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>

        <!-- Removal confirmation: says what goes with it, because it isn't obvious. -->
        <Dialog
            :open="removing !== null"
            @update:open="(open) => !open && (removing = null)"
        >
            <DialogContent class="sm:max-w-md" data-test="remove-watch-modal">
                <DialogHeader>
                    <DialogTitle>{{ t('Remove this watch?') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Its signals go too. Any you already turned into leads stay in your leads — those are untouched.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <p
                    v-if="removing?.signals_count"
                    class="text-sm text-foreground"
                >
                    {{
                        t('Signals to be removed with “:name”: :count', {
                            count: removing.signals_count,
                            name: removing.name,
                        })
                    }}
                </p>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="removing = null"
                    >
                        {{ t('Keep it') }}
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        data-test="confirm-remove-watch"
                        @click="confirmRemove"
                    >
                        {{ t('Remove the watch') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
