<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Ban,
    CornerDownLeft,
    Loader2,
    Plus,
    Sparkles,
    Wand,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import {
    FormActions,
    FormErrorSummary,
    FormField,
    FormSection,
    UnsavedChangesDialog,
} from '@/components/forms';
import MeterBar from '@/components/MeterBar.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import { useUnsavedGuard } from '@/composables/useUnsavedGuard';
import { importanceKey } from '@/lib/importance';
import type {
    CriterionFormRow,
    CriterionTypeMeta,
    Icp,
    IcpDraft,
} from '@/types';

/*
 * The ideal-customer editor — the screen that decides how every lead in the account is graded.
 *
 * What the rebuild changed:
 *
 *  - **A weight says what it means.** The slider ran 1–100 with the number beside it and nothing
 *    to say whether 50 was a lot. Each row now reads "Important · 50", on the same three-band
 *    scale the profile list shows (`lib/importance`), so the two screens cannot drift.
 *  - **The profile tells you what it will do**, in a summary beside the sections: how many signals
 *    it carries, what it leads on, and how many businesses it rules out. This is the one form in
 *    the product whose output is a *behaviour* rather than a record, and it was asking members to
 *    hold that in their heads.
 *  - **Failures are summarised and reachable.** A server error on the fourth criterion used to be
 *    a red line somewhere down the page; each one is now a link into its own field, and the
 *    criterion rows carry real ids for it to land on.
 *  - **The save bar stays within reach** and says whether anything is unsaved, and leaving with
 *    unsaved work asks first.
 *  - The hand-rolled `fieldClass` copy of the input styles is gone; the description is a
 *    `Textarea` and the type picker a `NativeSelect`, which is the one that keeps the native
 *    picker on a phone.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Ideal customers', href: '/icp' },
            { title: 'Edit profile', href: '/icp' },
        ],
    },
});

const props = defineProps<{
    icp: Icp | null;
    criterionTypes: CriterionTypeMeta[];
}>();

const { t } = useI18n();
const { number } = useFormat();

const isEditing = computed(() => props.icp !== null);
const signalTypes = computed(() =>
    props.criterionTypes.filter((c) => !c.isExclusion),
);

// Build the initial criteria: an existing ICP's rows, or a short scaffold that guides a new one.
function initialCriteria(): CriterionFormRow[] {
    if (props.icp) {
        return props.icp.criteria.map((c) => ({
            type: c.type,
            value: c.value,
            weight: c.weight,
        }));
    }
    return [
        { type: 'industry', value: '', weight: 70 },
        { type: 'company_size', value: '', weight: 50 },
        { type: 'geography', value: '', weight: 50 },
    ];
}

const form = useForm<{
    name: string;
    description: string;
    is_active: boolean;
    criteria: CriterionFormRow[];
}>({
    name: props.icp?.name ?? '',
    description: props.icp?.description ?? '',
    // A new profile defaults to active (the one Alima will use); an existing one keeps its flag.
    is_active: props.icp?.is_active ?? true,
    criteria: initialCriteria(),
});

const guard = useUnsavedGuard(() => form.isDirty);

// Index-preserving views of the two groups, so error keys (criteria.N.value) still line up.
const signalRows = computed(() =>
    form.criteria
        .map((row, index) => ({ row, index }))
        .filter((r) => r.row.type !== 'exclusion'),
);
const exclusionRows = computed(() =>
    form.criteria
        .map((row, index) => ({ row, index }))
        .filter((r) => r.row.type === 'exclusion'),
);

/**
 * The summary's names for the server's error keys, and the ids they point at.
 *
 * `criteria.4.value` means nothing to a member, so each one is named by the row it belongs to —
 * by its value if it has one, and by its position if it is still blank.
 */
const errorLabels = computed<Record<string, string>>(() => {
    const labels: Record<string, string> = {
        name: t('Profile name'),
        description: t('Description'),
        criteria: t('Signals'),
    };

    form.criteria.forEach((row, index) => {
        const isExclusion = row.type === 'exclusion';
        const position =
            (isExclusion ? exclusionRows.value : signalRows.value).findIndex(
                (candidate) => candidate.index === index,
            ) + 1;

        const name =
            row.value.trim() !== ''
                ? row.value
                : isExclusion
                  ? t('Exclusion :number', { number: position })
                  : t('Signal :number', { number: position });

        labels[`criteria.${index}.value`] = name;
        labels[`criteria.${index}.weight`] = name;
    });

    return labels;
});

const errorAnchors = computed<Record<string, string>>(() => {
    const anchors: Record<string, string> = {};

    form.criteria.forEach((row, index) => {
        const id =
            row.type === 'exclusion'
                ? `exclusion-value-${index}`
                : `signal-value-${index}`;

        anchors[`criteria.${index}.value`] = id;
        anchors[`criteria.${index}.weight`] = id;
    });

    return anchors;
});

function placeholderFor(type: string): string {
    return (
        props.criterionTypes.find((c) => c.value === type)?.placeholder ?? ''
    );
}

/** What a weight means, in words — the same scale the profile list shows. */
function importance(weight: number | null): string {
    const key = importanceKey(weight);

    return key === null ? '' : t(key);
}

function addSignal(): void {
    form.criteria.push({
        type: signalTypes.value[0]?.value ?? 'industry',
        value: '',
        weight: 50,
    });
}

function addExclusion(): void {
    form.criteria.push({ type: 'exclusion', value: '', weight: null });
}

function removeAt(index: number): void {
    form.criteria.splice(index, 1);
}

// --- What this profile will do -------------------------------------------------------------
// The form's output is a behaviour, not a record, so the screen says what the behaviour is as it
// is being built. Blank scaffold rows are left out: they are not signals yet.
const filledSignals = computed(() =>
    signalRows.value.filter((r) => r.row.value.trim() !== ''),
);

const filledExclusions = computed(() =>
    exclusionRows.value.filter((r) => r.row.value.trim() !== ''),
);

const leadingSignals = computed(() =>
    [...filledSignals.value]
        .sort((a, b) => (b.row.weight ?? 0) - (a.row.weight ?? 0))
        .slice(0, 3),
);

const topWeight = computed(() =>
    Math.max(1, ...filledSignals.value.map((r) => r.row.weight ?? 0)),
);

// --- Draft it from a sentence --------------------------------------------------------------
// A description in, a filled-in form out. Offered on a new profile only: on an existing one it
// would replace criteria a member has already tuned, and "redraft my profile" is not a thing
// anybody asked for.
const brief = ref('');
const composing = ref(false);
const composeError = ref<string | null>(null);
const draftSummary = ref<string | null>(null);

/** Examples in brand voice — deliberately not three variations of the same shape. */
const examples = computed(() => [
    t(
        'We are a design agency looking for startup food brands that need packaging or social media work.',
    ),
    t(
        'We fit commercial kitchens, so we want independent restaurants and cafés opening a second site.',
    ),
    t('Small UK law firms with no in-house marketing team.'),
]);

const briefField = ref<HTMLTextAreaElement | null>(null);

/** Take an example into the box, and put the cursor where the member now has to type. */
function useExample(example: string): void {
    brief.value = example;
    requestAnimationFrame(() => briefField.value?.focus());
}

function composeDraft(): void {
    // Guarded here rather than by `disabled` on the button, because a focused button that becomes
    // disabled drops focus to `<body>` (WCAG 2.4.3).
    if (composing.value || brief.value.trim() === '') {
        return;
    }

    composeError.value = null;

    router.post(
        '/icp/compose',
        { brief: brief.value },
        {
            preserveScroll: true,
            onStart: () => {
                composing.value = true;
            },
            onFinish: () => {
                composing.value = false;
            },
            onError: (errors) => {
                composeError.value = errors.brief ?? null;
            },
        },
    );
}

// The draft arrives **flashed**, not as a prop: it lasts until this page is left, which is exactly
// how long an unsaved draft should exist. Same channel the product's toasts arrive on.
const stopListening = router.on('flash', (event) => {
    const flash = (event as CustomEvent).detail?.flash as
        | { draft?: IcpDraft; brief?: string }
        | undefined;

    if (!flash) {
        return;
    }

    if (typeof flash.brief === 'string') {
        brief.value = flash.brief;
    }

    if (flash.draft) {
        applyDraft(flash.draft);
    }
});

onBeforeUnmount(() => stopListening());

/**
 * Fill the form from a draft.
 *
 * A refusal fills nothing: the toast carries the model's own reason, and a half-filled form
 * underneath it would read as though something had been drafted anyway.
 *
 * Assigned rather than set as defaults, so the form stays **dirty** — a draft is unsaved work, and
 * the leave-guard should treat it as such.
 */
function applyDraft(draft: IcpDraft): void {
    if (!draft.viable || draft.profile === null) {
        return;
    }

    draftSummary.value = draft.summary;
    form.clearErrors();
    form.name = draft.profile.name;
    form.description = draft.profile.description ?? '';
    form.criteria = draft.profile.criteria.map((c) => ({ ...c }));
}

// Bumped on every submit so the error summary announces a repeat rejection rather than
// sitting still because the number of failures happens to be the same.
const attempt = ref(0);

function submit(): void {
    attempt.value += 1;

    // Drop blank rows so leftover scaffold rows never block a save; this keeps the
    // submitted set identical to what's shown, so server error indices still match.
    form.criteria = form.criteria.filter((r) => r.value.trim() !== '');

    const options = { onSuccess: () => guard.allowOnce() };

    if (isEditing.value && props.icp) {
        form.put(`/icp/${props.icp.id}`, options);
    } else {
        form.post('/icp', options);
    }
}
</script>

<template>
    <Head :title="isEditing ? t('Edit profile') : t('New profile')" />

    <form
        class="mx-auto flex w-full max-w-3xl flex-col gap-6 py-2"
        @submit.prevent="submit"
    >
        <header class="space-y-2">
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('Ideal customer profile') }}
            </p>
            <h1
                class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
            >
                {{
                    isEditing
                        ? t('Refine your profile')
                        : t('Build your profile')
                }}<span class="text-orange">.</span>
            </h1>
            <p class="text-base text-muted-foreground">
                {{
                    t(
                        'Add the signals that describe a great-fit business, and weight each one by how much it matters. Alima scores every prospect against your active profile.',
                    )
                }}
            </p>
        </header>

        <FormErrorSummary
            :errors="form.errors"
            :labels="errorLabels"
            :anchors="errorAnchors"
            :attempt="attempt"
        />

        <!-- Draft it from a sentence. New profiles only (see `composeDraft`). -->
        <section
            v-if="!isEditing"
            class="space-y-4 rounded-2xl border border-border bg-background/40 p-6"
            aria-labelledby="composer-heading"
        >
            <div class="flex items-start gap-3">
                <Wand
                    class="mt-1 size-5 shrink-0 text-brand"
                    aria-hidden="true"
                />
                <div class="space-y-1">
                    <h2
                        id="composer-heading"
                        class="text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Describe who you want to reach') }}
                    </h2>
                    <p class="max-w-prose text-sm text-muted-foreground">
                        {{
                            t(
                                'Say what you do and the kind of business you are after. Alima fills in the profile below; you change anything you like before saving.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <div class="space-y-2">
                <!-- Labelled by the heading above rather than by an `sr-only` copy of the same
                     words, and described by its error only: the examples are three long buttons,
                     and reading all three every time the field takes focus is noise. -->
                <Textarea
                    id="composer-brief"
                    ref="briefField"
                    v-model="brief"
                    rows="3"
                    aria-labelledby="composer-heading"
                    :placeholder="
                        t(
                            'We are a design agency looking for startup food businesses that need branding on their packaging.',
                        )
                    "
                    :aria-invalid="composeError ? true : undefined"
                    aria-describedby="composer-error"
                    dir="auto"
                    data-test="composer-brief"
                />
                <p
                    v-if="composeError"
                    id="composer-error"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ composeError }}
                </p>
            </div>

            <!-- Examples as buttons rather than prose: the point of an example here is to be
                 taken, and a member reading three sentences they have to retype types nothing. -->
            <div class="space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Or start from one of these') }}
                </p>
                <ul class="flex flex-col gap-1.5">
                    <li v-for="example in examples" :key="example">
                        <button
                            type="button"
                            class="flex w-full items-start gap-2 rounded-xl border border-border px-3 py-2 text-start text-sm text-foreground transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            dir="auto"
                            @click="useExample(example)"
                        >
                            <CornerDownLeft
                                class="mt-0.5 size-4 shrink-0 text-muted-foreground rtl:rotate-180"
                                aria-hidden="true"
                            />
                            {{ example }}
                        </button>
                    </li>
                </ul>
            </div>

            <Button
                type="button"
                variant="lime"
                :disabled="brief.trim() === ''"
                :aria-disabled="composing ? true : undefined"
                :aria-busy="composing ? true : undefined"
                data-test="compose-draft"
                @click="composeDraft"
            >
                <Loader2 v-if="composing" class="size-4 animate-spin" />
                {{ composing ? t('Drafting…') : t('Draft my profile') }}
            </Button>

            <!-- What the composer took the brief to mean. Shown because a draft a member did not
                 expect is one to correct rather than trust. -->
            <p
                v-if="draftSummary"
                class="max-w-prose border-s-2 border-brand ps-3 text-sm text-muted-foreground"
                dir="auto"
                data-test="draft-summary"
            >
                {{ draftSummary }}
            </p>
        </section>

        <!-- Profile basics -->
        <FormSection step="01" :title="t('The profile')">
            <FormField
                id="name"
                :label="t('Profile name')"
                required
                :error="form.errors.name"
            >
                <template #default="{ id, describedBy, invalid }">
                    <Input
                        :id="id"
                        v-model="form.name"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :placeholder="t('e.g. UK growth agencies')"
                        autocomplete="off"
                        dir="auto"
                        data-test="icp-name"
                    />
                </template>
            </FormField>

            <FormField
                id="description"
                :label="t('Description')"
                optional
                :error="form.errors.description"
            >
                <template #default="{ id, describedBy, invalid }">
                    <Textarea
                        :id="id"
                        v-model="form.description"
                        rows="2"
                        :aria-describedby="describedBy"
                        :aria-invalid="invalid"
                        :placeholder="
                            t(
                                'A one-line reminder of who this profile targets.',
                            )
                        "
                        dir="auto"
                    />
                </template>
            </FormField>

            <label
                class="flex items-start gap-3 rounded-md border border-input px-3 py-2.5 text-sm"
            >
                <Checkbox v-model="form.is_active" class="mt-0.5" />
                <span class="space-y-0.5">
                    <span
                        class="flex items-center gap-1.5 font-medium text-foreground"
                    >
                        <Sparkles class="size-4 text-brand" />
                        {{ t('Use this as my active profile') }}
                    </span>
                    <!-- Switching the active profile re-grades the account's leads, which is not
                         obvious from a tick box labelled "active". -->
                    <span class="block text-xs text-muted-foreground">
                        {{
                            t(
                                'Only one profile is active at a time. New leads are graded against it.',
                            )
                        }}
                    </span>
                </span>
            </label>
        </FormSection>

        <!-- Signals -->
        <FormSection
            step="02"
            :title="t('Signals')"
            :description="t('What a great-fit business looks like.')"
        >
            <ul v-if="signalRows.length" class="space-y-3">
                <li
                    v-for="{ row, index } in signalRows"
                    :key="index"
                    class="rounded-xl border border-border bg-background/40 p-4"
                >
                    <div class="flex flex-wrap items-center gap-3">
                        <Label :for="`signal-type-${index}`" class="sr-only">{{
                            t('Signal type')
                        }}</Label>
                        <NativeSelect
                            :id="`signal-type-${index}`"
                            v-model="row.type"
                            class="w-40 shrink-0"
                        >
                            <option
                                v-for="o in signalTypes"
                                :key="o.value"
                                :value="o.value"
                            >
                                {{ t(o.label) }}
                            </option>
                        </NativeSelect>
                        <Label :for="`signal-value-${index}`" class="sr-only">{{
                            t('Signal value')
                        }}</Label>
                        <Input
                            :id="`signal-value-${index}`"
                            v-model="row.value"
                            class="min-w-48 flex-1"
                            dir="auto"
                            :aria-invalid="
                                form.errors[`criteria.${index}.value`]
                                    ? true
                                    : undefined
                            "
                            :placeholder="t(placeholderFor(row.type))"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="
                                t('Remove the signal “:name”', {
                                    name: row.value || t('this signal'),
                                })
                            "
                            @click="removeAt(index)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <div class="mt-3 flex items-center gap-3">
                        <Label
                            :for="`signal-weight-${index}`"
                            class="w-24 shrink-0 font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Importance') }}
                        </Label>
                        <input
                            :id="`signal-weight-${index}`"
                            v-model.number="row.weight"
                            type="range"
                            min="1"
                            max="100"
                            class="h-1.5 flex-1 cursor-pointer rounded-full accent-brand outline-none focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background"
                            :aria-valuetext="
                                t(':importance — :weight out of 100', {
                                    importance: importance(row.weight),
                                    weight: row.weight ?? 0,
                                })
                            "
                        />
                        <!-- The band and the figure together: the word says what it means, the
                             number says exactly what was set. -->
                        <span
                            class="w-28 shrink-0 text-end text-xs text-muted-foreground"
                            aria-hidden="true"
                        >
                            {{ importance(row.weight) }}
                            <span
                                class="font-mono font-semibold text-brand tabular-nums"
                                dir="ltr"
                                >{{ row.weight }}</span
                            >
                        </span>
                    </div>
                    <p
                        v-if="form.errors[`criteria.${index}.value`]"
                        class="mt-2 text-sm text-destructive"
                        dir="auto"
                    >
                        {{ form.errors[`criteria.${index}.value`] }}
                    </p>
                </li>
            </ul>
            <p
                v-else
                class="rounded-xl border border-dashed border-border px-4 py-6 text-center text-sm text-muted-foreground"
            >
                {{
                    t(
                        'Add at least one signal so Alima knows what to look for.',
                    )
                }}
            </p>

            <template #aside>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="add-signal"
                    @click="addSignal"
                >
                    <Plus class="size-4" />
                    {{ t('Add signal') }}
                </Button>
            </template>
        </FormSection>

        <!-- Exclusions -->
        <FormSection
            step="03"
            :title="t('Exclusions')"
            :description="
                t(
                    'Businesses to always skip — competitors, existing customers or specific domains.',
                )
            "
        >
            <ul v-if="exclusionRows.length" class="space-y-2">
                <li
                    v-for="{ row, index } in exclusionRows"
                    :key="index"
                    class="space-y-1"
                >
                    <div class="flex items-center gap-3">
                        <Ban
                            class="size-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Label
                            :for="`exclusion-value-${index}`"
                            class="sr-only"
                            >{{ t('Exclusion value') }}</Label
                        >
                        <Input
                            :id="`exclusion-value-${index}`"
                            v-model="row.value"
                            class="flex-1"
                            dir="auto"
                            :aria-invalid="
                                form.errors[`criteria.${index}.value`]
                                    ? true
                                    : undefined
                            "
                            :placeholder="t(placeholderFor('exclusion'))"
                        />
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="
                                t('Remove the exclusion “:name”', {
                                    name: row.value || t('this exclusion'),
                                })
                            "
                            @click="removeAt(index)"
                        >
                            <X class="size-4" />
                        </Button>
                    </div>
                    <p
                        v-if="form.errors[`criteria.${index}.value`]"
                        class="ps-7 text-sm text-destructive"
                        dir="auto"
                    >
                        {{ form.errors[`criteria.${index}.value`] }}
                    </p>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Nothing is excluded. Most profiles don’t need any.') }}
            </p>

            <template #aside>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    data-test="add-exclusion"
                    @click="addExclusion"
                >
                    <Plus class="size-4" />
                    {{ t('Add exclusion') }}
                </Button>
            </template>
        </FormSection>

        <!-- What this profile will do, as it is being built -->
        <section
            class="space-y-4 rounded-2xl border border-brand/30 bg-brand/5 p-6"
            :aria-label="t('What this profile will do')"
            data-test="icp-preview"
        >
            <h2
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('What this profile will do') }}
            </h2>

            <p
                v-if="!filledSignals.length"
                class="text-sm text-muted-foreground"
            >
                {{
                    t(
                        'Add a signal and Alima will tell you what it is going to look for.',
                    )
                }}
            </p>

            <template v-else>
                <p class="text-sm text-foreground">
                    {{
                        t(
                            'Alima will look for :signals signals and rule out :exclusions kinds of business.',
                            {
                                signals: number(filledSignals.length),
                                exclusions: number(filledExclusions.length),
                            },
                        )
                    }}
                </p>

                <ul class="space-y-2">
                    <li
                        v-for="{ row, index } in leadingSignals"
                        :key="index"
                        class="flex items-center gap-3 text-sm"
                    >
                        <span class="min-w-0 flex-1 truncate" dir="auto">
                            <span class="text-foreground">{{ row.value }}</span>
                        </span>
                        <MeterBar
                            :value="row.weight ?? 0"
                            :max="topWeight"
                            width="sm"
                            :label="
                                t(':importance — :weight out of 100', {
                                    importance: importance(row.weight),
                                    weight: row.weight ?? 0,
                                })
                            "
                        />
                        <span
                            class="w-20 shrink-0 text-end text-xs text-muted-foreground"
                            aria-hidden="true"
                            >{{ importance(row.weight) }}</span
                        >
                    </li>
                </ul>

                <div
                    v-if="filledExclusions.length"
                    class="flex flex-wrap items-center gap-1.5"
                >
                    <span class="text-xs text-muted-foreground">{{
                        t('Always skip:')
                    }}</span>
                    <Badge
                        v-for="{ row, index } in filledExclusions.slice(0, 6)"
                        :key="index"
                        variant="outline"
                        class="border-input"
                        dir="auto"
                        >{{ row.value }}</Badge
                    >
                </div>
            </template>
        </section>

        <!-- No bleed: this form is centred at `max-w-3xl`, so the bar lines up with the cards
             above it rather than reaching past them. -->
        <FormActions
            :dirty="form.isDirty"
            :processing="form.processing"
            :bleed="false"
        >
            <Button variant="outline" type="button" as-child>
                <Link href="/icp">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('Cancel') }}
                </Link>
            </Button>
            <Button
                variant="brand"
                type="submit"
                :disabled="form.processing"
                data-test="save-icp"
            >
                <Loader2 v-if="form.processing" class="size-4 animate-spin" />
                {{ isEditing ? t('Save changes') : t('Save profile') }}
            </Button>
        </FormActions>

        <UnsavedChangesDialog
            :open="guard.isPrompting.value"
            @stay="guard.stay"
            @discard="guard.discard"
        />
    </form>
</template>
