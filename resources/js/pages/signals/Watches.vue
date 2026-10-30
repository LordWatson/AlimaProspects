<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Pause, Play, Satellite, Trash2 } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
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
    SignalEventType,
    SignalEventTypeOption,
    SignalRunProgressPayload,
    SignalRunStatus,
    SignalWatch,
    SignalWatchDefaults,
    SignalWatchForm,
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
        event_types: SignalEventTypeOption[];
        cadences: SignalCadenceOption[];
        icps: { id: number; name: string; is_active: boolean }[];
    };
    defaults: SignalWatchDefaults;
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { currency, dateTime } = useFormat();
const page = usePage();

// --- The editor -------------------------------------------------------------------------------
// One form serves both create and edit; `editing` holds the watch being changed, or null for new.
const editing = ref<SignalWatch | null>(null);
const showEditor = ref(false);

const form = useForm<SignalWatchForm>({
    name: '',
    location: props.defaults.location,
    event_types: [...props.defaults.event_types],
    ignore_domains: [],
    cadence: props.defaults.cadence,
    is_active: true,
    icp_id: null,
});

/**
 * The exclusion list is edited as one domain per line, which is how a team thinks about it, and
 * sent as a list. Blank lines are dropped on the way out; the server clamps and de-duplicates.
 */
const ignoreDomainsText = computed({
    get: () => form.ignore_domains.join('\n'),
    set: (value: string) => {
        form.ignore_domains = value
            .split('\n')
            .map((line) => line.trim())
            .filter(Boolean);
    },
});

function openNew(): void {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        name: '',
        location: props.defaults.location,
        event_types: [...props.defaults.event_types],
        ignore_domains: [],
        cadence: props.defaults.cadence,
        is_active: true,
        icp_id: props.options.icps.find((icp) => icp.is_active)?.id ?? null,
    });
    form.reset();
    showEditor.value = true;
}

function openEdit(watch: SignalWatch): void {
    editing.value = watch;
    form.clearErrors();
    form.defaults({
        name: watch.name,
        location: watch.location,
        event_types: watch.event_types.map((type) => type.value),
        ignore_domains: [...watch.ignore_domains],
        cadence: watch.cadence,
        is_active: watch.is_active,
        icp_id: watch.icp?.id ?? null,
    });
    form.reset();
    showEditor.value = true;
}

function toggleEventType(type: SignalEventType): void {
    form.event_types = form.event_types.includes(type)
        ? form.event_types.filter((t) => t !== type)
        : [...form.event_types, type];
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
            location: watch.location,
            event_types: watch.event_types.map((type) => type.value),
            // Carried through unchanged: this posts the whole watch, and dropping the list here
            // would quietly clear it every time someone paused a watch.
            ignore_domains: watch.ignore_domains,
            cadence: watch.cadence,
            is_active: !watch.is_active,
            icp_id: watch.icp?.id ?? null,
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

        <!-- First run: one click to the broadest useful watch. -->
        <EmptyState
            v-if="!watches.length"
            :icon="Satellite"
            :title="
                t('Set up your first :location watch', {
                    location: defaults.location,
                })
            "
            :description="
                t(
                    'We’ll watch :location every day for company sales, funding rounds and founders moving in. You can change any of it afterwards.',
                    { location: defaults.location },
                )
            "
            data-test="no-watches"
        >
            <template v-if="can.manage">
                <Button data-test="create-default-watch" @click="createDefault">
                    {{
                        t('Watch :location for me', {
                            location: defaults.location,
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
                            <p class="text-sm text-muted-foreground" dir="auto">
                                {{ watch.location }} ·
                                {{ t(watch.cadence_label) }}
                                <template v-if="watch.icp">
                                    · {{ t('shaped by') }} {{ watch.icp.name }}
                                </template>
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
                            v-for="type in watch.event_types"
                            :key="type.value"
                            variant="lilac"
                        >
                            {{ t(type.label) }}
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

                        <div class="space-y-2">
                            <Label for="watch-location">{{ t('Where') }}</Label>
                            <Input
                                id="watch-location"
                                v-model="form.location"
                                name="location"
                                required
                                aria-describedby="watch-location-hint"
                                dir="auto"
                            />
                            <p
                                id="watch-location-hint"
                                class="text-xs text-muted-foreground"
                            >
                                {{
                                    t(
                                        'A city or emirate. Alima currently watches the UAE.',
                                    )
                                }}
                            </p>
                            <InputError :message="form.errors.location" />
                        </div>

                        <fieldset class="space-y-3">
                            <legend
                                class="text-sm font-medium text-foreground rtl:font-bold"
                            >
                                {{ t('What counts as an event') }}
                            </legend>
                            <div class="space-y-2">
                                <Label
                                    v-for="option in options.event_types"
                                    :key="option.value"
                                    class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="mt-0.5 size-4 accent-brand"
                                        :value="option.value"
                                        :checked="
                                            form.event_types.includes(
                                                option.value,
                                            )
                                        "
                                        @change="toggleEventType(option.value)"
                                    />
                                    <span class="space-y-0.5">
                                        <span
                                            class="block text-sm font-medium text-foreground"
                                            >{{ t(option.label) }}</span
                                        >
                                        <span
                                            class="block text-xs text-muted-foreground"
                                            >{{ t(option.description) }}</span
                                        >
                                    </span>
                                </Label>
                            </div>
                            <InputError :message="form.errors.event_types" />
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
