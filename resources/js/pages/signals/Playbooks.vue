<script setup lang="ts">
/**
 * The rule builder (S14).
 *
 * This is the screen where Signals stops being something a member reads every morning and becomes
 * something that works while they are out. A rule says *when a signal looks like this, do these
 * things* — and the three things the screen has to get across, because none of them is guessable,
 * are the three it says out loud:
 *
 * 1. **A rule drafts an approach. It never sends one.** That is a compliance decision
 *    (`docs/compliance.md` §3.12), not an implementation detail, and a member who assumed otherwise
 *    would be making a legal judgement on a guess. So it is written beside the drafting step and
 *    repeated in the step's own description.
 * 2. **A new rule rehearses.** It records what it *would* have done and changes nothing, which is
 *    what makes anybody brave enough to switch one on. The mode is the first field, not a toggle
 *    hidden at the bottom.
 * 3. **The preview is the feature** — the same idea S9's definition preview rests on, applied to
 *    automation. "It matches 4 of 120" is a rule somebody can switch on; "118 of 120" is the one
 *    that would convert the whole feed, and the ratio is what tells them apart.
 *
 * Brand (§2a): one hero accent, Cloud Cream everywhere else. **Nothing here uses Signal Orange** —
 * a refusal is a state, so the run summary uses `status.*`, and the panel that warns about a
 * too-broad rule is a `status.warning` tint with the words left in `text-foreground`
 * (brand-tokens.md §7f.2–3). Steps that **spend** are marked in words, never by colour alone.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowDown,
    ArrowLeft,
    ArrowUp,
    CircleAlert,
    GripVertical,
    Plus,
    ScrollText,
    Trash2,
    Wand2,
    Workflow,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { FormErrorSummary, FormField } from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogScrollContent,
    DialogTitle,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useI18n } from '@/composables/useI18n';
import type {
    PlaybookActionKind,
    SignalPlaybook,
    SignalPlaybookForm,
    SignalPlaybookOptions,
    SignalPlaybookPreview,
    SignalPlaybookRun,
    SignalPlaybookStep,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Signals', href: '/signals' },
            { title: 'Rules', href: '/signals/playbooks' },
        ],
    },
});

const props = defineProps<{
    playbooks: SignalPlaybook[];
    recent: SignalPlaybookRun[];
    options: SignalPlaybookOptions;
    can: { manage: boolean };
}>();

const { t } = useI18n();

const atCeiling = computed(
    () => props.playbooks.length >= props.options.limits.max_playbooks,
);

/*
 * The preview arrives **flashed**, not as a prop, which is the channel this product's toasts and
 * the type builder's own preview already use: it lasts until the page is left, which is exactly how
 * long a preview of an unsaved rule should exist. It is read off `router.on('flash')` rather than
 * `usePage().props`, because that is where Inertia delivers flashed data here.
 */
const preview = ref<SignalPlaybookPreview | null>(null);
const previewing = ref(false);

const stopListening = router.on('flash', (event) => {
    const flash = (event as CustomEvent).detail?.flash as
        | { playbookPreview?: SignalPlaybookPreview }
        | undefined;

    if (flash?.playbookPreview) {
        preview.value = flash.playbookPreview;
        revealPreview();
    }
});

onBeforeUnmount(() => stopListening());

/**
 * Move focus to the result when it arrives.
 *
 * A screen-reader user pressed a button and would otherwise hear nothing: the panel's content is a
 * plain `v-if` with no live region. Focus rather than `aria-live`, following the type builder's own
 * reasoning (brand-tokens.md §7h.10) — a polite region would read every sample, and landing on the
 * panel is more useful than hearing it.
 */
const previewPanel = ref<HTMLElement | null>(null);

function revealPreview(): void {
    requestAnimationFrame(() => {
        previewPanel.value?.focus();
        previewPanel.value?.scrollIntoView({ block: 'nearest' });
    });
}

const drafting = computed(() => props.options.channels.length > 0);

function emptyConditions(): SignalPlaybookForm['conditions'] {
    return {
        definitions: [],
        sources: [],
        jurisdictions: [],
        min_heat: null,
        min_amount_minor: null,
        max_amount_minor: null,
        watchlist: 'any',
        watchlist_ids: [],
    };
}

// --- The editor ------------------------------------------------------------------------------
const editing = ref<SignalPlaybook | null>(null);
const showEditor = ref(false);

const form = useForm<SignalPlaybookForm>({
    name: '',
    mode: 'dry_run',
    is_active: true,
    stop_after_first_match: false,
    priority: 100,
    conditions: emptyConditions(),
    actions: [],
});

/*
 * The error summary's maps, **derived from the form rather than written out.**
 *
 * This is the one place a long builder cannot take a shortcut. The server addresses a step's
 * failure as `actions.2.params.stage_id`, and `FormErrorSummary` falls back to turning underscores
 * into hyphens — which gives `actions.2.params.stage-id`, an id that exists nowhere. So a per-step
 * failure linked to nothing and showed no label, which on a form this tall is the member seeing a
 * panel that says something is wrong and offers no way to it. (Found by `brand-reviewer`.)
 *
 * Built per step, so a reordered or removed step cannot leave a stale anchor behind.
 */
const stepFields = [
    'stage_id',
    'user_ids',
    'tags',
    'title',
    'channel',
    'tone',
    'due_in_days',
] as const;

const conditionFields = [
    ['definitions', 'Signal types'],
    ['sources', 'Where it came from'],
    ['jurisdictions', 'Where it happened'],
    ['min_heat', 'Heat, at least'],
    ['min_amount_minor', 'Amount, at least'],
    ['max_amount_minor', 'Amount, at most'],
    ['watchlist', 'Companies you already have'],
    ['watchlist_ids', 'Which lists'],
] as const;

const fieldLabels = computed<Record<string, string>>(() => {
    const labels: Record<string, string> = {
        name: t('Name'),
        mode: t('What it does'),
        actions: t('What happens'),
    };

    for (const [field, label] of conditionFields) {
        labels[`conditions.${field}`] = t(label);
    }

    form.actions.forEach((step, index) => {
        const name = t(kindFor(step.kind)?.label ?? step.kind);

        labels[`actions.${index}.kind`] = name;

        for (const field of stepFields) {
            labels[`actions.${index}.params.${field}`] = name;
        }
    });

    return labels;
});

const fieldAnchors = computed<Record<string, string>>(() => {
    const anchors: Record<string, string> = {
        name: 'playbook-name',
        mode: 'playbook-mode',
        actions: 'playbook-actions',
    };

    for (const [field] of conditionFields) {
        anchors[`conditions.${field}`] =
            `playbook-${field.replaceAll('_', '-')}`;
    }

    form.actions.forEach((step, index) => {
        anchors[`actions.${index}.kind`] = `step-${index}-kind`;

        for (const field of stepFields) {
            anchors[`actions.${index}.params.${field}`] =
                `step-${index}-params-${field.replaceAll('_', '-')}`;
        }
    });

    return anchors;
});

// Bumped on every submit so a second rejection for the same reason still announces itself.
const attempt = ref(0);

function defaults(playbook: SignalPlaybook | null): SignalPlaybookForm {
    if (!playbook) {
        return {
            name: '',
            // Rehearsing, deliberately: a rule nobody has watched yet should watch.
            mode: 'dry_run',
            is_active: true,
            stop_after_first_match: false,
            priority: 100,
            conditions: emptyConditions(),
            actions: [],
        };
    }

    return {
        name: playbook.name,
        mode: playbook.mode,
        is_active: playbook.is_active,
        stop_after_first_match: playbook.stop_after_first_match,
        priority: playbook.priority,
        conditions: { ...playbook.conditions },
        actions: playbook.actions.map((step) => ({
            kind: step.kind,
            params: { ...step.params },
        })),
    };
}

function open(playbook: SignalPlaybook | null): void {
    editing.value = playbook;
    form.clearErrors();
    form.defaults(defaults(playbook));
    form.reset();
    showEditor.value = true;
}

function submit(): void {
    attempt.value += 1;

    const opts = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        form.patch(`/signals/playbooks/${editing.value.id}`, opts);
    } else {
        form.post('/signals/playbooks', opts);
    }
}

/** Pausing stops a rule being evaluated at all; it does not forget what it has done. */
function toggleActive(playbook: SignalPlaybook): void {
    router.patch(
        `/signals/playbooks/${playbook.id}`,
        { ...defaults(playbook), is_active: !playbook.is_active },
        { preserveScroll: true },
    );
}

/** Switching a rehearsal on is the one edit that changes what the product does unattended. */
function goLive(playbook: SignalPlaybook): void {
    router.patch(
        `/signals/playbooks/${playbook.id}`,
        { ...defaults(playbook), mode: 'live' },
        { preserveScroll: true },
    );
}

const removing = ref<SignalPlaybook | null>(null);

function confirmRemove(): void {
    const target = removing.value;

    if (!target) return;

    router.delete(`/signals/playbooks/${target.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = null;
        },
    });
}

// --- Steps -----------------------------------------------------------------------------------
const kindFor = (kind: PlaybookActionKind) =>
    props.options.action_kinds.find((option) => option.value === kind) ?? null;

const addableKinds = computed(() =>
    props.options.action_kinds.filter(
        (option) =>
            option.repeatable ||
            !form.actions.some((step) => step.kind === option.value),
    ),
);

const atStepCeiling = computed(
    () => form.actions.length >= props.options.limits.max_actions,
);

function addStep(kind: PlaybookActionKind): void {
    // Guarded here rather than `disabled` on the button: a disabled control is not focusable, so
    // its explanation is never announced (brand-tokens.md §7j.3).
    if (atStepCeiling.value) return;

    form.actions = [...form.actions, { kind, params: starterParams(kind) }];
}

/**
 * The parameters a newly added step starts with.
 *
 * Pre-filled where there is an obvious answer, so a member who adds "Tell someone" and saves does
 * not meet a validation error about a field they never saw. The drafting step is the exception: the
 * channel is a decision with a compliance consequence, so it is left for them to make.
 */
function starterParams(kind: PlaybookActionKind): SignalPlaybookStep['params'] {
    switch (kind) {
        case 'assign_to_member':
            return { strategy: 'named', user_ids: [] };
        case 'notify':
            return { audience: 'lead_owners', user_ids: [] };
        case 'add_tag':
            return { tags: [] };
        case 'create_task':
            return { title: '', due_in_days: 2, assign_to_lead_owner: true };
        case 'move_to_pipeline_stage':
            return { stage_id: props.options.stages[0]?.id ?? null };
        case 'draft_outreach':
            return {
                channel: null,
                tone: props.options.tones[0]?.value ?? null,
            };
        default:
            return {};
    }
}

function removeStep(index: number): void {
    form.actions = form.actions.filter((_, i) => i !== index);
}

/**
 * What a reorder announces, and where focus goes afterwards.
 *
 * Both exist because the first cut lost focus twice over: the move buttons were `disabled` at the
 * ends, so pressing one until the step reached the top disabled the very control the member was
 * on, and the list was keyed by index, so a reorder re-keyed the DOM and dropped focus anyway.
 * `aria-disabled` plus a guard keeps the button focusable, the keys are stable client ids, and the
 * move is announced because otherwise nothing tells a screen-reader user it happened. (Found by
 * `brand-reviewer`.)
 */
const moveAnnouncement = ref('');

function moveStep(index: number, by: number): void {
    const target = index + by;

    if (target < 0 || target >= form.actions.length) return;

    const next = [...form.actions];
    [next[index], next[target]] = [next[target], next[index]];
    form.actions = next;

    moveAnnouncement.value = t('Moved to step :position of :total', {
        position: target + 1,
        total: next.length,
    });

    // The row has moved, so the button the member pressed is now somewhere else. Put focus back on
    // the same control in its new position rather than leaving it on `<body>`.
    requestAnimationFrame(() => {
        document
            .querySelector<HTMLElement>(
                `[data-test="step-${target}-move-${by < 0 ? 'up' : 'down'}"]`,
            )
            ?.focus();
    });
}

/**
 * A client-side id per step, so the `v-for` key survives a reorder.
 *
 * Keyed on the step object itself rather than on its index or its kind: two "Tag the lead" steps
 * are legitimate, and an index key makes Vue re-use the wrong DOM node when they swap.
 */
const stepKeys = new WeakMap<SignalPlaybookStep, number>();
let nextStepKey = 0;

function stepKey(step: SignalPlaybookStep): number {
    let key = stepKeys.get(step);

    if (key === undefined) {
        key = nextStepKey++;
        stepKeys.set(step, key);
    }

    return key;
}

/** A free-text list of labels, kept as an array because that is what the server takes. */
function tagsText(step: SignalPlaybookStep): string {
    return (step.params.tags ?? []).join(', ');
}

function setTags(step: SignalPlaybookStep, value: string): void {
    step.params.tags = value
        .split(',')
        .map((tag) => tag.trim())
        .filter((tag) => tag !== '');
}

function toggleMember(step: SignalPlaybookStep, id: number): void {
    const current = step.params.user_ids ?? [];

    step.params.user_ids = current.includes(id)
        ? current.filter((member) => member !== id)
        : [...current, id];
}

/**
 * Whether a step needs a lead and has nothing before it that would supply one.
 *
 * Said on the step rather than left for the run log, because "there was no lead to act on" is the
 * single most common way a first rule disappoints somebody, and the fix — put "Make it a lead"
 * first — is one they can make here in a second.
 */
function needsLeadWarning(index: number): boolean {
    const step = form.actions[index];

    if (!kindFor(step.kind)?.needs_lead) return false;

    return !form.actions
        .slice(0, index)
        .some(
            (earlier) =>
                earlier.kind === 'convert_to_lead' ||
                earlier.kind === 'attach_to_matched_lead',
        );
}

// --- Conditions ------------------------------------------------------------------------------
function toggleIn(list: number[], id: number): number[] {
    return list.includes(id)
        ? list.filter((entry) => entry !== id)
        : [...list, id];
}

function toggleString(list: string[], value: string): string[] {
    return list.includes(value)
        ? list.filter((entry) => entry !== value)
        : [...list, value];
}

/** Major units in the box, minor units on the wire (CLAUDE.md §2). */
function amountMajor(minor: number | null): string {
    return minor === null ? '' : String(Math.round(minor / 100));
}

function setAmount(
    key: 'min_amount_minor' | 'max_amount_minor',
    value: string,
): void {
    const parsed = Number(value);

    form.conditions[key] =
        value.trim() === '' || Number.isNaN(parsed)
            ? null
            : Math.max(0, Math.round(parsed * 100));
}

const narrowsNothing = computed(() => {
    const c = form.conditions;

    return (
        c.definitions.length === 0 &&
        c.sources.length === 0 &&
        c.jurisdictions.length === 0 &&
        c.min_heat === null &&
        c.min_amount_minor === null &&
        c.max_amount_minor === null &&
        c.watchlist === 'any' &&
        c.watchlist_ids.length === 0
    );
});

const spends = computed(() =>
    form.actions.some((step) => kindFor(step.kind)?.spends),
);

function runPreview(): void {
    if (previewing.value) {
        return;
    }

    router.post(
        '/signals/playbooks/preview',
        {
            conditions: form.conditions,
            kinds: form.actions.map((step) => step.kind),
        },
        {
            preserveScroll: true,
            // `preserveState`, so the half-built rule in the dialog survives the round trip — which
            // is the whole point of previewing before saving.
            preserveState: true,
            onStart: () => {
                previewing.value = true;
            },
            onFinish: () => {
                previewing.value = false;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('Rules')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="08"
            :eyebrow="t('Rules')"
            :title="t('Let the morning|handle itself.')"
            :description="
                t(
                    'A rule watches for the signals you care about and does the first few steps for you — makes the lead, hands it to someone, moves it on, writes an approach for you to read. It never sends anything.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="can.manage && playbooks.length"
                    variant="lime"
                    :disabled="atCeiling"
                    :aria-describedby="
                        atCeiling ? 'playbook-ceiling' : undefined
                    "
                    data-test="new-playbook"
                    @click="open(null)"
                >
                    {{ t('New rule') }}
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/signals/playbooks/activity">
                        <ScrollText class="size-4" aria-hidden="true" />
                        {{ t('What it has done') }}
                    </Link>
                </Button>
                <Button variant="hero" as-child>
                    <Link href="/signals/watches">
                        <ArrowLeft
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                        {{ t('Watch settings') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <!-- The one sentence this screen exists to make unambiguous. Stated as plain copy rather
             than as a warning panel: it is how the product works, not a problem. -->
        <p class="max-w-prose text-base text-muted-foreground">
            {{
                t(
                    'Alima never sends a message on its own. A rule can write one and keep it as a draft for you to read, and it will refuse even that where the rules for that prospect say you need their consent first.',
                )
            }}
        </p>

        <p
            v-if="atCeiling && can.manage"
            id="playbook-ceiling"
            class="text-base text-muted-foreground"
            data-test="playbook-ceiling"
        >
            {{
                t(
                    'That is as many rules as a team can keep (:max). Pause or remove one to add another.',
                    { max: options.limits.max_playbooks },
                )
            }}
        </p>

        <EmptyState
            v-if="!playbooks.length"
            :icon="Workflow"
            :title="t('Nothing is automated yet')"
            :description="
                t(
                    'Start with a rehearsal: a rule that records what it would have done and changes nothing. Watch it for a week, then switch it on.',
                )
            "
            data-test="no-playbooks"
        >
            <template v-if="can.manage">
                <Button data-test="first-playbook" @click="open(null)">
                    {{ t('Build a rule') }}
                </Button>
            </template>
            <p v-else class="text-base text-muted-foreground">
                {{ t('Ask an admin on your team to set these up.') }}
            </p>
        </EmptyState>

        <section
            v-for="playbook in playbooks"
            :key="playbook.id"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
            :data-test="`playbook-${playbook.id}`"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h2
                        :id="`playbook-${playbook.id}-name`"
                        tabindex="-1"
                        class="flex flex-wrap items-center gap-2 text-lg font-semibold text-foreground focus:outline-none rtl:font-bold"
                        dir="auto"
                    >
                        {{ playbook.name }}
                        <Badge v-if="!playbook.is_active" variant="outline">
                            {{ t('Paused') }}
                        </Badge>
                        <Badge v-if="playbook.is_rehearsing" variant="outline">
                            {{ t(playbook.mode_label) }}
                        </Badge>
                    </h2>
                    <p class="max-w-prose text-base text-muted-foreground">
                        {{ t(playbook.mode_description) }}
                    </p>
                </div>

                <div
                    v-if="can.manage"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Button
                        v-if="playbook.is_rehearsing"
                        variant="outline"
                        size="sm"
                        :data-test="`go-live-${playbook.id}`"
                        :aria-describedby="`playbook-${playbook.id}-name`"
                        @click="goLive(playbook)"
                    >
                        {{ t('Switch it on') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`toggle-${playbook.id}`"
                        :aria-describedby="`playbook-${playbook.id}-name`"
                        @click="toggleActive(playbook)"
                    >
                        {{ playbook.is_active ? t('Pause') : t('Resume') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`edit-${playbook.id}`"
                        :aria-describedby="`playbook-${playbook.id}-name`"
                        @click="open(playbook)"
                    >
                        {{ t('Edit') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`remove-${playbook.id}`"
                        @click="removing = playbook"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        <span class="sr-only">
                            {{ t('Remove :name', { name: playbook.name }) }}
                        </span>
                    </Button>
                </div>
            </div>

            <!-- What it does, in order. The order is the rule: a conversion first is what supplies
                 the lead the later steps need. -->
            <ol class="flex flex-wrap items-center gap-2">
                <li
                    v-for="(step, index) in playbook.actions"
                    :key="`${playbook.id}-${index}-${step.kind}`"
                    class="rounded-full border border-border px-3 py-1 text-base text-foreground"
                    dir="auto"
                >
                    {{ t(kindFor(step.kind)?.label ?? step.kind) }}
                </li>
            </ol>

            <!-- The labels are **sans**, not mono: they are translated, and Arabic set in DM Mono
                 falls out of Tajawal into a fallback at 12px (brand-tokens.md §3). Mono and
                 tabular are kept for the figures, which is what they are for. -->
            <dl
                class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground"
            >
                <div class="flex gap-1.5">
                    <dt>{{ t('Times it has fired') }}:</dt>
                    <dd class="font-mono tabular-nums" dir="ltr">
                        {{ playbook.runs_count ?? 0 }}
                    </dd>
                </div>
                <div class="flex gap-1.5">
                    <dt>{{ t('Runs in order') }}:</dt>
                    <dd class="font-mono tabular-nums" dir="ltr">
                        {{ playbook.priority }}
                    </dd>
                </div>
            </dl>

            <!-- A rule built on a newer release, read by this one: it is doing less than it says,
                 and the honest thing is to say so rather than leave somebody to notice. -->
            <p
                v-if="playbook.has_unknown_steps"
                class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                :data-test="`unknown-steps-${playbook.id}`"
            >
                <CircleAlert
                    class="mt-0.5 size-4 shrink-0 text-warning"
                    aria-hidden="true"
                />
                {{
                    t(
                        'This rule has a step this version of Alima does not recognise, and it is being skipped. Open it and save it again to tidy it up.',
                    )
                }}
            </p>
        </section>

        <!-- The last few things that happened, so somebody who has just switched a rehearsal on
             sees it working without leaving the screen. -->
        <section v-if="recent.length" class="space-y-3" data-test="recent-runs">
            <h2 class="text-lg font-semibold text-foreground rtl:font-bold">
                {{ t('Just now') }}
            </h2>
            <ul class="space-y-2">
                <li
                    v-for="run in recent"
                    :key="run.id"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 rounded-2xl border border-border bg-card px-4 py-3"
                >
                    <span
                        class="text-base font-semibold text-foreground rtl:font-bold"
                        dir="auto"
                    >
                        {{ run.playbook_name }}
                    </span>
                    <span
                        class="min-w-0 flex-1 truncate text-base text-muted-foreground"
                        dir="auto"
                    >
                        {{ run.signal_headline }}
                    </span>
                    <Badge v-if="run.is_rehearsal" variant="outline">
                        {{ t('Rehearsal') }}
                    </Badge>
                    <Badge v-if="run.needs_attention" variant="outline">
                        {{ t('Worth a look') }}
                    </Badge>
                </li>
            </ul>
            <Link
                href="/signals/playbooks/activity"
                class="inline-flex rounded text-base text-foreground underline decoration-dotted underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ t('See everything it has done') }}
            </Link>
        </section>

        <!-- The builder. -->
        <Dialog
            :open="showEditor"
            @update:open="(value: boolean) => !value && (showEditor = false)"
        >
            <DialogScrollContent
                class="sm:max-w-3xl"
                data-test="playbook-editor"
            >
                <form class="space-y-6" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>
                            {{ editing ? t('Edit rule') : t('New rule') }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'Say which signals this is about, then what should happen. Preview it against the last few days before you keep it.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <FormErrorSummary
                        :errors="form.errors"
                        :labels="fieldLabels"
                        :anchors="fieldAnchors"
                        :attempt="attempt"
                    />

                    <FormField
                        id="playbook-name"
                        :label="t('Name')"
                        :hint="t('What you will recognise it by in the log.')"
                        required
                        :error="form.errors.name"
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Input
                                :id="id"
                                v-model="form.name"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                required
                                :placeholder="t('e.g. Handle big acquisitions')"
                                autocomplete="off"
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <!-- The mode first, not last: it is the difference between a rule that watches
                         and a rule that acts on somebody's pipeline. -->
                    <fieldset
                        id="playbook-mode"
                        tabindex="-1"
                        class="space-y-3 focus:outline-none"
                    >
                        <legend
                            class="text-base font-medium text-foreground rtl:font-bold"
                        >
                            {{ t('What it does') }}
                        </legend>
                        <div class="space-y-2">
                            <Label
                                v-for="mode in options.modes"
                                :key="mode.value"
                                class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                            >
                                <input
                                    v-model="form.mode"
                                    type="radio"
                                    name="mode"
                                    class="mt-0.5 size-4 accent-brand"
                                    :value="mode.value"
                                />
                                <span class="space-y-0.5">
                                    <span
                                        class="block text-base font-semibold text-foreground rtl:font-bold"
                                    >
                                        {{ t(mode.label) }}
                                    </span>
                                    <span
                                        class="block text-base text-muted-foreground"
                                    >
                                        {{ t(mode.description) }}
                                    </span>
                                </span>
                            </Label>
                        </div>
                        <p class="max-w-prose text-base text-muted-foreground">
                            {{
                                t(
                                    'A rehearsal is the safe way in. Give it about :days days, read the log, then switch it on.',
                                    {
                                        days: options.limits
                                            .suggested_dry_run_days,
                                    },
                                )
                            }}
                        </p>
                    </fieldset>

                    <!-- Conditions. -->
                    <fieldset class="space-y-4">
                        <legend
                            class="text-base font-medium text-foreground rtl:font-bold"
                        >
                            {{ t('Which signals') }}
                        </legend>
                        <p class="max-w-prose text-base text-muted-foreground">
                            {{
                                t(
                                    'Leave something blank and the rule does not mind about it. Leave everything blank and it matches every signal.',
                                )
                            }}
                        </p>

                        <!-- `fieldset`/`legend`, not a `span`: a bare label beside a group of
                             checkboxes names nothing to a screen reader, and the id is what the
                             error summary moves focus to. -->
                        <fieldset
                            v-if="options.definitions.length"
                            id="playbook-definitions"
                            tabindex="-1"
                            class="space-y-2 focus:outline-none"
                        >
                            <legend class="text-base text-foreground">
                                {{ t('Signal types') }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <Label
                                    v-for="definition in options.definitions"
                                    :key="definition.id"
                                    class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-brand"
                                        :checked="
                                            form.conditions.definitions.includes(
                                                definition.id,
                                            )
                                        "
                                        @change="
                                            form.conditions.definitions =
                                                toggleIn(
                                                    form.conditions.definitions,
                                                    definition.id,
                                                )
                                        "
                                    />
                                    <span
                                        class="text-base text-foreground"
                                        dir="auto"
                                    >
                                        {{ definition.name }}
                                    </span>
                                </Label>
                            </div>
                        </fieldset>

                        <fieldset
                            id="playbook-sources"
                            tabindex="-1"
                            class="space-y-2 focus:outline-none"
                        >
                            <legend class="text-base text-foreground">
                                {{ t('Where it came from') }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <Label
                                    v-for="source in options.sources"
                                    :key="source.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-brand"
                                        :checked="
                                            form.conditions.sources.includes(
                                                source.value,
                                            )
                                        "
                                        @change="
                                            form.conditions.sources =
                                                toggleString(
                                                    form.conditions.sources,
                                                    source.value,
                                                ) as typeof form.conditions.sources
                                        "
                                    />
                                    <span class="text-base text-foreground">{{
                                        t(source.label)
                                    }}</span>
                                </Label>
                            </div>
                        </fieldset>

                        <fieldset
                            id="playbook-jurisdictions"
                            tabindex="-1"
                            class="space-y-2 focus:outline-none"
                        >
                            <legend class="text-base text-foreground">
                                {{ t('Where it happened') }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <Label
                                    v-for="place in options.jurisdictions"
                                    :key="place.value"
                                    class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-brand"
                                        :checked="
                                            form.conditions.jurisdictions.includes(
                                                place.value,
                                            )
                                        "
                                        @change="
                                            form.conditions.jurisdictions =
                                                toggleString(
                                                    form.conditions
                                                        .jurisdictions,
                                                    place.value,
                                                )
                                        "
                                    />
                                    <span
                                        class="font-mono text-xs text-foreground"
                                        dir="ltr"
                                    >
                                        {{ place.label }}
                                    </span>
                                </Label>
                            </div>
                            <p
                                class="max-w-prose text-base text-muted-foreground"
                            >
                                {{
                                    t(
                                        'A story Alima could not place does not match a rule that names a country. That is on purpose: a rule like this may go on to write an approach.',
                                    )
                                }}
                            </p>
                        </fieldset>

                        <!-- Which account lists, when the team keeps any. Offered because the
                             server condition exists, and a condition only reachable over the API is
                             one the builder is lying about by omission. -->
                        <fieldset
                            v-if="options.watchlists.length"
                            id="playbook-watchlist-ids"
                            tabindex="-1"
                            class="space-y-2 focus:outline-none"
                        >
                            <legend class="text-base text-foreground">
                                {{ t('Which lists') }}
                            </legend>
                            <div class="flex flex-wrap gap-2">
                                <Label
                                    v-for="list in options.watchlists"
                                    :key="list.id"
                                    class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                >
                                    <input
                                        type="checkbox"
                                        class="size-4 accent-brand"
                                        :checked="
                                            form.conditions.watchlist_ids.includes(
                                                list.id,
                                            )
                                        "
                                        @change="
                                            form.conditions.watchlist_ids =
                                                toggleIn(
                                                    form.conditions
                                                        .watchlist_ids,
                                                    list.id,
                                                )
                                        "
                                    />
                                    <span
                                        class="text-base text-foreground"
                                        dir="auto"
                                        >{{ list.name }}</span
                                    >
                                </Label>
                            </div>
                        </fieldset>

                        <div class="grid gap-4 sm:grid-cols-3">
                            <FormField
                                id="playbook-min-heat"
                                :label="t('Heat, at least')"
                                :error="form.errors['conditions.min_heat']"
                                :hint="
                                    t(
                                        'How strong the signal is, 0 to 100. A signal Alima has not scored does not match.',
                                    )
                                "
                                optional
                            >
                                <template #default="{ id, describedBy }">
                                    <Input
                                        :id="id"
                                        :model-value="
                                            form.conditions.min_heat ?? ''
                                        "
                                        :aria-describedby="describedBy"
                                        type="number"
                                        min="0"
                                        max="100"
                                        inputmode="numeric"
                                        dir="ltr"
                                        @update:model-value="
                                            (value: string | number) =>
                                                (form.conditions.min_heat =
                                                    String(value).trim() === ''
                                                        ? null
                                                        : Number(value))
                                        "
                                    />
                                </template>
                            </FormField>

                            <FormField
                                id="playbook-min-amount-minor"
                                :label="t('Amount, at least')"
                                :error="
                                    form.errors['conditions.min_amount_minor']
                                "
                                :hint="
                                    t(
                                        'In whole units. A story with no amount does not match.',
                                    )
                                "
                                optional
                            >
                                <template #default="{ id, describedBy }">
                                    <Input
                                        :id="id"
                                        :model-value="
                                            amountMajor(
                                                form.conditions
                                                    .min_amount_minor,
                                            )
                                        "
                                        :aria-describedby="describedBy"
                                        type="number"
                                        min="0"
                                        inputmode="numeric"
                                        dir="ltr"
                                        @update:model-value="
                                            (value: string | number) =>
                                                setAmount(
                                                    'min_amount_minor',
                                                    String(value),
                                                )
                                        "
                                    />
                                </template>
                            </FormField>

                            <FormField
                                id="playbook-max-amount-minor"
                                :label="t('Amount, at most')"
                                :error="
                                    form.errors['conditions.max_amount_minor']
                                "
                                optional
                            >
                                <template #default="{ id, describedBy }">
                                    <Input
                                        :id="id"
                                        :model-value="
                                            amountMajor(
                                                form.conditions
                                                    .max_amount_minor,
                                            )
                                        "
                                        :aria-describedby="describedBy"
                                        type="number"
                                        min="0"
                                        inputmode="numeric"
                                        dir="ltr"
                                        @update:model-value="
                                            (value: string | number) =>
                                                setAmount(
                                                    'max_amount_minor',
                                                    String(value),
                                                )
                                        "
                                    />
                                </template>
                            </FormField>
                        </div>

                        <FormField
                            id="playbook-watchlist"
                            :label="t('Companies you already have')"
                        >
                            <template #default="{ id, describedBy }">
                                <NativeSelect
                                    :id="id"
                                    v-model="form.conditions.watchlist"
                                    :aria-describedby="describedBy"
                                >
                                    <option
                                        v-for="condition in options.watchlist_conditions"
                                        :key="condition.value"
                                        :value="condition.value"
                                    >
                                        {{ t(condition.label) }}
                                    </option>
                                </NativeSelect>
                            </template>
                        </FormField>
                    </fieldset>

                    <!-- The steps. -->
                    <fieldset
                        id="playbook-actions"
                        tabindex="-1"
                        class="space-y-4 focus:outline-none"
                    >
                        <legend
                            class="text-base font-medium text-foreground rtl:font-bold"
                        >
                            {{ t('What happens') }}
                        </legend>
                        <p class="max-w-prose text-base text-muted-foreground">
                            {{
                                t(
                                    'In order, top to bottom. Most steps need a lead, so “Make it a lead” usually goes first.',
                                )
                            }}
                        </p>

                        <!-- `text-destructive`, like every other error line in the product: the
                             first cut set this in plain `text-foreground` with no marker, so a
                             reader could not tell it was an error at all. -->
                        <p
                            v-if="form.errors.actions"
                            class="text-base text-destructive"
                            data-test="actions-error"
                        >
                            {{ form.errors.actions }}
                        </p>

                        <!-- A reorder is announced, because nothing else tells a screen-reader
                             user that the step moved. -->
                        <p aria-live="polite" class="sr-only">
                            {{ moveAnnouncement }}
                        </p>

                        <ol v-if="form.actions.length" class="space-y-3">
                            <li
                                v-for="(step, index) in form.actions"
                                :key="stepKey(step)"
                                class="space-y-3 rounded-2xl border border-border p-4"
                                :data-test="`step-${index}`"
                            >
                                <div
                                    class="flex flex-wrap items-start justify-between gap-2"
                                >
                                    <div class="min-w-0 space-y-0.5">
                                        <p
                                            :id="`step-${index}-kind`"
                                            tabindex="-1"
                                            class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground focus:outline-none rtl:font-bold"
                                        >
                                            <GripVertical
                                                class="size-4 text-muted-foreground"
                                                aria-hidden="true"
                                            />
                                            {{
                                                t(
                                                    kindFor(step.kind)?.label ??
                                                        step.kind,
                                                )
                                            }}
                                            <!-- Said in a word, not marked in a colour: a colour
                                                 is not a label (WCAG 1.4.1). -->
                                            <Badge
                                                v-if="
                                                    kindFor(step.kind)?.spends
                                                "
                                                variant="outline"
                                            >
                                                {{ t('Costs money') }}
                                            </Badge>
                                        </p>
                                        <p
                                            class="max-w-prose text-base text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    kindFor(step.kind)
                                                        ?.description ?? '',
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <!-- `aria-disabled` rather than `disabled`, and the move
                                         guarded in the handler: pressing "up" until the step
                                         reaches the top would otherwise disable the control the
                                         member is standing on and drop focus to `<body>`
                                         (brand-tokens.md §7j.3). Each name carries the step, so
                                         three identical "Move up" buttons are tellable apart. -->
                                    <div class="flex items-center gap-1">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :aria-disabled="
                                                index === 0 ? true : undefined
                                            "
                                            :data-test="`step-${index}-move-up`"
                                            @click="moveStep(index, -1)"
                                        >
                                            <ArrowUp
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            <span class="sr-only">{{
                                                t('Move :step up', {
                                                    step: t(
                                                        kindFor(step.kind)
                                                            ?.label ??
                                                            step.kind,
                                                    ),
                                                })
                                            }}</span>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :aria-disabled="
                                                index ===
                                                form.actions.length - 1
                                                    ? true
                                                    : undefined
                                            "
                                            :data-test="`step-${index}-move-down`"
                                            @click="moveStep(index, 1)"
                                        >
                                            <ArrowDown
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            <span class="sr-only">{{
                                                t('Move :step down', {
                                                    step: t(
                                                        kindFor(step.kind)
                                                            ?.label ??
                                                            step.kind,
                                                    ),
                                                })
                                            }}</span>
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            :data-test="`remove-step-${index}`"
                                            @click="removeStep(index)"
                                        >
                                            <Trash2
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            <span class="sr-only">{{
                                                t('Remove :step', {
                                                    step: t(
                                                        kindFor(step.kind)
                                                            ?.label ??
                                                            step.kind,
                                                    ),
                                                })
                                            }}</span>
                                        </Button>
                                    </div>
                                </div>

                                <p
                                    v-if="needsLeadWarning(index)"
                                    class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                                    :data-test="`needs-lead-${index}`"
                                >
                                    <CircleAlert
                                        class="mt-0.5 size-4 shrink-0 text-warning"
                                        aria-hidden="true"
                                    />
                                    {{
                                        t(
                                            'This step needs a lead. Add “Make it a lead” above it, or it will be skipped.',
                                        )
                                    }}
                                </p>

                                <!-- Assign -->
                                <div
                                    v-if="step.kind === 'assign_to_member'"
                                    class="space-y-3"
                                >
                                    <FormField
                                        :id="`step-${index}-strategy`"
                                        :label="t('Who gets it')"
                                    >
                                        <template #default="{ id }">
                                            <NativeSelect
                                                :id="id"
                                                v-model="step.params.strategy"
                                            >
                                                <option
                                                    v-for="strategy in options.assign_strategies"
                                                    :key="strategy.value"
                                                    :value="strategy.value"
                                                >
                                                    {{ t(strategy.label) }}
                                                </option>
                                            </NativeSelect>
                                        </template>
                                    </FormField>
                                    <div
                                        :id="`step-${index}-params-user-ids`"
                                        tabindex="-1"
                                        class="space-y-2 focus:outline-none"
                                    >
                                        <span
                                            class="block text-base text-foreground"
                                            >{{ t('People') }}</span
                                        >
                                        <p
                                            v-if="
                                                form.errors[
                                                    `actions.${index}.params.user_ids`
                                                ]
                                            "
                                            class="text-base text-destructive"
                                        >
                                            {{
                                                form.errors[
                                                    `actions.${index}.params.user_ids`
                                                ]
                                            }}
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            <Label
                                                v-for="member in options.members"
                                                :key="member.id"
                                                class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="size-4 accent-brand"
                                                    :checked="
                                                        (
                                                            step.params
                                                                .user_ids ?? []
                                                        ).includes(member.id)
                                                    "
                                                    @change="
                                                        toggleMember(
                                                            step,
                                                            member.id,
                                                        )
                                                    "
                                                />
                                                <span
                                                    class="text-base text-foreground"
                                                    dir="auto"
                                                >
                                                    {{ member.name }}
                                                </span>
                                            </Label>
                                        </div>
                                    </div>
                                </div>

                                <!-- Stage -->
                                <FormField
                                    v-else-if="
                                        step.kind === 'move_to_pipeline_stage'
                                    "
                                    :id="`step-${index}-params-stage-id`"
                                    :label="t('Which stage')"
                                    :error="
                                        form.errors[
                                            `actions.${index}.params.stage_id`
                                        ]
                                    "
                                >
                                    <template #default="{ id, invalid }">
                                        <NativeSelect
                                            :id="id"
                                            v-model="step.params.stage_id"
                                            :aria-invalid="invalid"
                                        >
                                            <option
                                                v-for="stage in options.stages"
                                                :key="stage.id"
                                                :value="stage.id"
                                            >
                                                {{ stage.name }}
                                            </option>
                                        </NativeSelect>
                                    </template>
                                </FormField>

                                <!-- Tags -->
                                <FormField
                                    v-else-if="step.kind === 'add_tag'"
                                    :id="`step-${index}-params-tags`"
                                    :label="t('Labels')"
                                    :hint="t('Separate them with commas.')"
                                    :error="
                                        form.errors[
                                            `actions.${index}.params.tags`
                                        ]
                                    "
                                >
                                    <template #default="{ id, invalid }">
                                        <Input
                                            :id="id"
                                            :model-value="tagsText(step)"
                                            :aria-invalid="invalid"
                                            :placeholder="
                                                t('e.g. Hot, Acquisition')
                                            "
                                            autocomplete="off"
                                            dir="auto"
                                            @update:model-value="
                                                (value: string | number) =>
                                                    setTags(step, String(value))
                                            "
                                        />
                                    </template>
                                </FormField>

                                <!-- Notify -->
                                <div
                                    v-else-if="step.kind === 'notify'"
                                    class="space-y-3"
                                >
                                    <FormField
                                        :id="`step-${index}-audience`"
                                        :label="t('Who to tell')"
                                    >
                                        <template #default="{ id }">
                                            <NativeSelect
                                                :id="id"
                                                v-model="step.params.audience"
                                            >
                                                <option
                                                    v-for="audience in options.notify_audiences"
                                                    :key="audience.value"
                                                    :value="audience.value"
                                                >
                                                    {{ t(audience.label) }}
                                                </option>
                                            </NativeSelect>
                                        </template>
                                    </FormField>
                                    <div
                                        v-if="step.params.audience === 'named'"
                                        :id="`step-${index}-params-user-ids`"
                                        tabindex="-1"
                                        class="space-y-2 focus:outline-none"
                                    >
                                        <span
                                            class="block text-base text-foreground"
                                            >{{ t('People') }}</span
                                        >
                                        <p
                                            v-if="
                                                form.errors[
                                                    `actions.${index}.params.user_ids`
                                                ]
                                            "
                                            class="text-base text-destructive"
                                        >
                                            {{
                                                form.errors[
                                                    `actions.${index}.params.user_ids`
                                                ]
                                            }}
                                        </p>
                                        <div class="flex flex-wrap gap-2">
                                            <Label
                                                v-for="member in options.members"
                                                :key="member.id"
                                                class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                                            >
                                                <input
                                                    type="checkbox"
                                                    class="size-4 accent-brand"
                                                    :checked="
                                                        (
                                                            step.params
                                                                .user_ids ?? []
                                                        ).includes(member.id)
                                                    "
                                                    @change="
                                                        toggleMember(
                                                            step,
                                                            member.id,
                                                        )
                                                    "
                                                />
                                                <span
                                                    class="text-base text-foreground"
                                                    dir="auto"
                                                >
                                                    {{ member.name }}
                                                </span>
                                            </Label>
                                        </div>
                                    </div>
                                    <p
                                        class="max-w-prose text-base text-muted-foreground"
                                    >
                                        {{
                                            t(
                                                'One message per person each time Alima looks, however many signals matched.',
                                            )
                                        }}
                                    </p>
                                </div>

                                <!-- Draft -->
                                <div
                                    v-else-if="step.kind === 'draft_outreach'"
                                    class="space-y-3"
                                >
                                    <p
                                        v-if="!drafting"
                                        class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                                        data-test="drafting-unavailable"
                                    >
                                        <CircleAlert
                                            class="mt-0.5 size-4 shrink-0 text-warning"
                                            aria-hidden="true"
                                        />
                                        {{
                                            t(
                                                'Drafting is not available on this account.',
                                            )
                                        }}
                                    </p>
                                    <template v-else>
                                        <FormField
                                            :id="`step-${index}-params-channel`"
                                            :label="
                                                t('How it should be written')
                                            "
                                            :error="
                                                form.errors[
                                                    `actions.${index}.params.channel`
                                                ]
                                            "
                                        >
                                            <template
                                                #default="{ id, invalid }"
                                            >
                                                <NativeSelect
                                                    :id="id"
                                                    v-model="
                                                        step.params.channel
                                                    "
                                                    :aria-invalid="invalid"
                                                >
                                                    <option
                                                        :value="null"
                                                        disabled
                                                    >
                                                        {{ t('Choose one') }}
                                                    </option>
                                                    <option
                                                        v-for="channel in options.channels"
                                                        :key="channel.value"
                                                        :value="channel.value"
                                                    >
                                                        {{ t(channel.label) }}
                                                    </option>
                                                </NativeSelect>
                                            </template>
                                        </FormField>
                                        <FormField
                                            :id="`step-${index}-params-tone`"
                                            :label="t('Tone')"
                                            :error="
                                                form.errors[
                                                    `actions.${index}.params.tone`
                                                ]
                                            "
                                        >
                                            <template
                                                #default="{ id, invalid }"
                                            >
                                                <NativeSelect
                                                    :id="id"
                                                    v-model="step.params.tone"
                                                    :aria-invalid="invalid"
                                                >
                                                    <option
                                                        v-for="tone in options.tones"
                                                        :key="tone.value"
                                                        :value="tone.value"
                                                    >
                                                        {{ t(tone.label) }}
                                                    </option>
                                                </NativeSelect>
                                            </template>
                                        </FormField>
                                        <!-- The sentence this whole phase turns on. -->
                                        <p
                                            class="max-w-prose text-base text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    'Alima writes it and keeps it as a draft for someone to read. It is never sent, and it will be refused where the prospect may not be approached — the log says which rule said no.',
                                                )
                                            }}
                                        </p>
                                    </template>
                                </div>

                                <!-- Task -->
                                <div
                                    v-else-if="step.kind === 'create_task'"
                                    class="space-y-3"
                                >
                                    <FormField
                                        :id="`step-${index}-params-title`"
                                        :label="t('Task')"
                                        :error="
                                            form.errors[
                                                `actions.${index}.params.title`
                                            ]
                                        "
                                    >
                                        <template #default="{ id, invalid }">
                                            <!-- `model-value` plus an explicit handler rather
                                                 than `v-model`: the stored value is nullable (a
                                                 step written by an older release may have no
                                                 title at all) and the input's own value is not. -->
                                            <Input
                                                :id="id"
                                                :model-value="
                                                    step.params.title ?? ''
                                                "
                                                :aria-invalid="invalid"
                                                :placeholder="
                                                    t(
                                                        'e.g. Call them about the round',
                                                    )
                                                "
                                                autocomplete="off"
                                                dir="auto"
                                                @update:model-value="
                                                    (value: string | number) =>
                                                        (step.params.title =
                                                            String(value))
                                                "
                                            />
                                        </template>
                                    </FormField>
                                    <FormField
                                        :id="`step-${index}-params-due-in-days`"
                                        :label="t('Days until it is due')"
                                        optional
                                    >
                                        <template #default="{ id }">
                                            <Input
                                                :id="id"
                                                :model-value="
                                                    step.params.due_in_days ??
                                                    ''
                                                "
                                                type="number"
                                                min="1"
                                                :max="365"
                                                inputmode="numeric"
                                                dir="ltr"
                                                @update:model-value="
                                                    (value: string | number) =>
                                                        (step.params.due_in_days =
                                                            String(
                                                                value,
                                                            ).trim() === ''
                                                                ? null
                                                                : Number(value))
                                                "
                                            />
                                        </template>
                                    </FormField>
                                    <Label
                                        class="flex cursor-pointer items-center gap-2 text-base text-foreground"
                                    >
                                        <input
                                            v-model="
                                                step.params.assign_to_lead_owner
                                            "
                                            type="checkbox"
                                            class="size-4 accent-brand"
                                        />
                                        {{
                                            t(
                                                'Give it to whoever is working the lead',
                                            )
                                        }}
                                    </Label>
                                </div>
                            </li>
                        </ol>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="kind in addableKinds"
                                :key="kind.value"
                                type="button"
                                variant="outline"
                                size="sm"
                                :disabled="atStepCeiling"
                                :data-test="`add-step-${kind.value}`"
                                @click="addStep(kind.value)"
                            >
                                <Plus class="size-4" aria-hidden="true" />
                                {{ t(kind.label) }}
                            </Button>
                        </div>

                        <p
                            v-if="atStepCeiling"
                            class="text-base text-muted-foreground"
                            data-test="step-ceiling"
                        >
                            {{
                                t(
                                    'That is as many steps as one rule can carry (:max). A rule nobody can read is a rule nobody can predict.',
                                    { max: options.limits.max_actions },
                                )
                            }}
                        </p>
                    </fieldset>

                    <!-- The preview: the thing that makes somebody brave enough to switch it on. -->
                    <section
                        ref="previewPanel"
                        tabindex="-1"
                        aria-labelledby="playbook-preview-heading"
                        class="space-y-3 rounded-2xl border border-border p-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        <div
                            class="flex flex-wrap items-center justify-between gap-2"
                        >
                            <h3
                                id="playbook-preview-heading"
                                class="text-base font-semibold text-foreground rtl:font-bold"
                            >
                                {{ t('What this would have done') }}
                            </h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                :aria-disabled="previewing ? true : undefined"
                                :aria-busy="previewing ? true : undefined"
                                data-test="run-preview"
                                @click="runPreview"
                            >
                                <Wand2 class="size-4" aria-hidden="true" />
                                {{ t('Check the last few days') }}
                            </Button>
                        </div>

                        <p
                            v-if="narrowsNothing && spends"
                            class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                            data-test="matches-everything"
                        >
                            <CircleAlert
                                class="mt-0.5 size-4 shrink-0 text-warning"
                                aria-hidden="true"
                            />
                            {{
                                t(
                                    'This rule matches every signal and costs money each time. Narrow it, or rehearse it first.',
                                )
                            }}
                        </p>

                        <template v-if="preview">
                            <!-- A sentence, not a bare `4 / 120 · 7`: read aloud, that line has no
                                 units at all. The figures keep mono and tabular inside it. -->
                            <p
                                class="max-w-prose text-base text-foreground"
                                data-test="preview-counts"
                            >
                                {{
                                    t(
                                        'It would have picked :matched of the :considered signals Alima found in the last :days days.',
                                        {
                                            matched: preview.matched,
                                            considered: preview.considered,
                                            days: preview.days,
                                        },
                                    )
                                }}
                            </p>
                            <p
                                v-if="
                                    preview.would_exceed_conversions ||
                                    preview.would_exceed_drafts
                                "
                                class="flex items-start gap-2 rounded-2xl border border-warning/30 bg-warning/12 p-3 text-base text-foreground"
                                data-test="preview-cap"
                            >
                                <CircleAlert
                                    class="mt-0.5 size-4 shrink-0 text-warning"
                                    aria-hidden="true"
                                />
                                {{
                                    t(
                                        'At that rate the rule would reach the limit on how much one run may do, and the rest would wait for the next one. Narrowing it is the fix.',
                                    )
                                }}
                            </p>
                            <ul v-if="preview.samples.length" class="space-y-2">
                                <li
                                    v-for="sample in preview.samples"
                                    :key="sample.id"
                                    class="flex flex-wrap items-center gap-x-3 gap-y-1"
                                >
                                    <span
                                        class="min-w-0 flex-1 text-base text-foreground"
                                        dir="auto"
                                    >
                                        {{ sample.headline }}
                                    </span>
                                    <Badge variant="outline">{{
                                        t(sample.event_label)
                                    }}</Badge>
                                    <Badge
                                        v-if="sample.about_existing_lead"
                                        variant="outline"
                                    >
                                        {{ t('One of yours') }}
                                    </Badge>
                                </li>
                            </ul>
                            <p
                                v-else-if="preview.matched === 0"
                                class="text-base text-muted-foreground"
                            >
                                {{
                                    t(
                                        'Nothing in the last few days would have matched. That may be right — or the rule may be too narrow.',
                                    )
                                }}
                            </p>
                        </template>
                        <p
                            v-else
                            class="max-w-prose text-base text-muted-foreground"
                        >
                            {{
                                t(
                                    'Nothing is changed and nothing is spent: Alima looks at the signals you already have and tells you which ones this rule would have picked.',
                                )
                            }}
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
                            data-test="save-playbook"
                        >
                            {{ editing ? t('Save changes') : t('Create rule') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>

        <!-- Removing a rule keeps its history, which is not what somebody would assume. -->
        <Dialog
            :open="removing !== null"
            @update:open="(value: boolean) => !value && (removing = null)"
        >
            <DialogScrollContent
                class="sm:max-w-lg"
                data-test="remove-playbook-dialog"
            >
                <DialogHeader>
                    <DialogTitle>{{ t('Remove this rule?') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'It stops running straight away. What it has already done stays in the log, and nothing it created is undone.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="removing = null">
                        {{ t('Keep it') }}
                    </Button>
                    <Button
                        variant="destructive"
                        data-test="confirm-remove-playbook"
                        @click="confirmRemove"
                    >
                        {{ t('Remove it') }}
                    </Button>
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
