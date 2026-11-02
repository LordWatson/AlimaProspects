<script setup lang="ts">
/**
 * The signal-type library (S7, ADR 0018).
 *
 * Two halves, and the difference between them is the whole decision: **your types**, which the team
 * owns and can change, and **Alima's library**, which they cannot — a preset is copied into their
 * own types and tuned from there, so a change we make never silently alters what they are paying to
 * run. The copy on screen says that in words rather than leaving it to be discovered.
 *
 * This is the plumbing screen for S7, not the builder. S9 replaces the plain form below with the
 * plain-language composer and the preview, which is what makes authoring a type pleasant rather
 * than merely possible.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Pause, Play, Shapes, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    FormErrorSummary,
    FormField,
    UnsavedChangesDialog,
} from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
import SignalTypeIcon from '@/components/signals/SignalTypeIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
    SignalCategory,
    SignalCategoryOption,
    SignalDefinition,
    SignalDefinitionDefaults,
    SignalDefinitionForm,
    SignalIcon,
    SignalIconOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Signals', href: '/signals' },
            { title: 'Signal types', href: '/signals/types' },
        ],
    },
});

const props = defineProps<{
    definitions: SignalDefinition[];
    presets: SignalDefinition[];
    options: {
        categories: SignalCategoryOption[];
        icons: SignalIconOption[];
    };
    defaults: SignalDefinitionDefaults;
    can: { manage: boolean };
}>();

const { t } = useI18n();

/**
 * A type's name and explainer are **either** Alima's words or the team's, and only the server can
 * say which (`SignalDefinition::usesPlatformWording()`). Translating a team's own wording would be
 * the app overwriting what somebody typed, so `translatable` decides rather than a guess.
 */
function label(definition: SignalDefinition): string {
    return definition.translatable ? t(definition.name) : definition.name;
}

function explainer(definition: SignalDefinition): string {
    return definition.translatable
        ? t(definition.description)
        : definition.description;
}

const atCeiling = computed(
    () => props.definitions.length >= props.defaults.max_definitions,
);

// --- The library ------------------------------------------------------------------------------
// Grouped by category, in the order the server declared them, so the sections read in the order
// they were designed in rather than alphabetically.
const presetGroups = computed(() =>
    props.options.categories
        .map((category) => ({
            category,
            presets: props.presets.filter(
                (preset) => preset.category === category.value,
            ),
        }))
        .filter((group) => group.presets.length > 0),
);

const adding = ref<string[]>([]);

function addPreset(preset: SignalDefinition): void {
    router.post(
        '/signals/types/clone',
        { keys: [preset.key] },
        {
            preserveScroll: true,
            onStart: () => {
                adding.value = [...adding.value, preset.key];
            },
            onFinish: () => {
                adding.value = adding.value.filter((k) => k !== preset.key);
            },
        },
    );
}

function addEveryPreset(): void {
    router.post(
        '/signals/types/clone',
        { keys: props.presets.map((preset) => preset.key) },
        { preserveScroll: true },
    );
}

// --- The editor -------------------------------------------------------------------------------
// One form serves create and edit; `editing` holds the type being changed, or null for new.
const editing = ref<SignalDefinition | null>(null);
const showEditor = ref(false);

const form = useForm<SignalDefinitionForm>({
    name: '',
    description: '',
    category: 'custom',
    icon: 'satellite',
    query_templates: [],
    match_keywords: [],
    includes: [],
    excludes: [],
    min_confidence: null,
    min_relevance: null,
    suggested_approach_hint: null,
    is_active: true,
});

/** What the error summary calls each field, so "Name" links to the box rather than `name` does. */
const fieldLabels = computed<Record<string, string>>(() => ({
    name: t('Name'),
    description: t('What it means, in one line'),
    category: t('Group it under'),
    icon: t('Icon'),
    query_templates: t('What to search for'),
    includes: t('What counts'),
    excludes: t('What doesn’t'),
    match_keywords: t('Buying-signal words'),
    min_confidence: t('How sure Alima must be'),
    min_relevance: t('How well it must fit you'),
}));

/**
 * The list fields are edited one entry per line, which is how somebody thinks about them, and sent
 * as lists. Blank lines go on the way out; the server clamps, squishes and de-duplicates.
 */
function lines(
    key: 'query_templates' | 'match_keywords' | 'includes' | 'excludes',
) {
    return computed({
        get: () => form[key].join('\n'),
        set: (value: string) => {
            form[key] = value
                .split('\n')
                .map((line) => line.trim())
                .filter(Boolean);
        },
    });
}

const templatesText = lines('query_templates');
const keywordsText = lines('match_keywords');
const includesText = lines('includes');
const excludesText = lines('excludes');

/**
 * The two floors are nullable — empty means "use Alima's" — and an `<input>` has no null, only an
 * empty string. These map between the two so a cleared box posts `null` rather than `0`, which
 * would be a floor of zero and the opposite of what clearing it means.
 */
function floor(key: 'min_confidence' | 'min_relevance') {
    return computed({
        get: () => (form[key] === null ? '' : String(form[key])),
        set: (value: string | number) => {
            const text = String(value).trim();
            form[key] = text === '' ? null : Number(text);
        },
    });
}

const confidenceFloor = floor('min_confidence');
const relevanceFloor = floor('min_relevance');

function openNew(): void {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        name: '',
        description: '',
        category: 'custom' as SignalCategory,
        icon: 'satellite' as SignalIcon,
        query_templates: [],
        match_keywords: [],
        includes: [],
        excludes: [],
        min_confidence: null,
        min_relevance: null,
        suggested_approach_hint: null,
        is_active: true,
    });
    form.reset();
    showEditor.value = true;
}

function openEdit(definition: SignalDefinition): void {
    editing.value = definition;
    form.clearErrors();
    form.defaults({
        name: definition.name,
        description: definition.description,
        category: definition.category,
        icon: definition.icon,
        query_templates: [...definition.query_templates],
        match_keywords: [...definition.match_keywords],
        includes: [...definition.includes],
        excludes: [...definition.excludes],
        min_confidence: definition.min_confidence,
        min_relevance: definition.min_relevance,
        suggested_approach_hint: definition.suggested_approach_hint,
        is_active: definition.is_active,
    });
    form.reset();
    showEditor.value = true;
}

/**
 * Closing the editor is guarded when there is something to lose.
 *
 * `useUnsavedGuard` holds back a *navigation*; this is a dialog, and Esc or a click on the overlay
 * would throw away rule text somebody thought about. Same prompt, same words — the one the
 * product's other long forms use (brand-tokens.md §7h.11).
 */
const confirmingDiscard = ref(false);

function requestClose(): void {
    if (form.isDirty) {
        confirmingDiscard.value = true;

        return;
    }

    showEditor.value = false;
}

function discardEdits(): void {
    confirmingDiscard.value = false;
    showEditor.value = false;
    form.reset();
    form.clearErrors();
}

function submit(): void {
    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        form.patch(`/signals/types/${editing.value.id}`, options);
    } else {
        form.post('/signals/types', options);
    }
}

/** Switch a type on or off without opening the editor — the change members make most. */
function toggleActive(definition: SignalDefinition): void {
    router.patch(
        `/signals/types/${definition.id}`,
        {
            name: definition.name,
            description: definition.description,
            category: definition.category,
            icon: definition.icon,
            // Carried through unchanged: this posts the whole type, and dropping any of these here
            // would quietly empty them every time somebody paused one.
            query_templates: definition.query_templates,
            match_keywords: definition.match_keywords,
            includes: definition.includes,
            excludes: definition.excludes,
            min_confidence: definition.min_confidence,
            min_relevance: definition.min_relevance,
            suggested_approach_hint: definition.suggested_approach_hint,
            is_active: !definition.is_active,
        },
        { preserveScroll: true },
    );
}

// --- Removal ----------------------------------------------------------------------------------
// Refused while a watch still uses it, which the server says plainly (422). The confirmation says
// what survives: the signals this type already found are history and are kept.
const removing = ref<SignalDefinition | null>(null);
// Held separately so the title does not read "Remove ?" while the dialog animates shut.
const removingName = ref('');

function askRemove(definition: SignalDefinition): void {
    removing.value = definition;
    removingName.value = label(definition);
}

function confirmRemove(): void {
    if (!removing.value) return;

    router.delete(`/signals/types/${removing.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = null;
        },
    });
}
</script>

<template>
    <Head :title="t('Signal types')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="07"
            :eyebrow="t('Signal types')"
            :title="t('Say what a|moment looks like.')"
            :description="
                t(
                    'A signal type is what Alima looks for: the words that find it, and the rules that tell it apart from the noise. Start from ours, or write your own.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="can.manage && definitions.length"
                    variant="lime"
                    :disabled="atCeiling"
                    data-test="new-type"
                    @click="openNew"
                >
                    {{ t('New type') }}
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

        <!-- Your types -->
        <section class="space-y-4">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Your types') }}
                </h2>
                <p
                    v-if="definitions.length"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                    dir="ltr"
                >
                    <span class="sr-only">{{ t('Signal types used') }}: </span>
                    {{ definitions.length }} / {{ defaults.max_definitions }}
                </p>
            </div>

            <!-- Said in words, because a disabled button explains nothing and drops out of the tab
                 order. A member at the ceiling needs to know what to do, not just that they
                 cannot. -->
            <p
                v-if="atCeiling && can.manage"
                class="text-base text-muted-foreground"
                data-test="at-ceiling"
            >
                {{
                    t(
                        'You have as many signal types as your plan allows (:max). Remove or pause one to add another.',
                        { max: defaults.max_definitions },
                    )
                }}
            </p>

            <EmptyState
                v-if="!definitions.length"
                :icon="Shapes"
                :title="t('Pick what Alima should look for')"
                :description="
                    t(
                        'Nothing is being watched for yet. Take a few from the library below — you can change any of it afterwards — or write your own.',
                    )
                "
                data-test="no-types"
            >
                <template v-if="can.manage">
                    <Button
                        data-test="add-every-preset"
                        @click="addEveryPreset"
                    >
                        {{ t('Add Alima’s types') }}
                    </Button>
                    <Button variant="outline" @click="openNew">
                        {{ t('Write my own') }}
                    </Button>
                </template>
                <p v-else class="text-base text-muted-foreground">
                    {{ t('Ask an admin on your team to set these up.') }}
                </p>
            </EmptyState>

            <div v-else class="space-y-4" data-test="type-list">
                <Card
                    v-for="definition in definitions"
                    :key="definition.id"
                    :data-test="`type-${definition.id}`"
                >
                    <CardContent class="space-y-4">
                        <div
                            class="flex flex-wrap items-start justify-between gap-3"
                        >
                            <div class="flex min-w-0 gap-3">
                                <!-- A feature icon: a solid circle at 2x the glyph (brand §5), in
                                     the hero's one accent. Decorative — the heading beside it is
                                     the name. -->
                                <span
                                    class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-lilac-40 text-ink"
                                >
                                    <SignalTypeIcon
                                        :name="definition.icon"
                                        class="size-5"
                                    />
                                </span>
                                <div class="min-w-0 space-y-1">
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <h3
                                            class="text-lg font-semibold text-foreground rtl:font-bold"
                                            dir="auto"
                                        >
                                            {{ label(definition) }}
                                        </h3>
                                        <Badge
                                            :variant="
                                                definition.is_active
                                                    ? 'success'
                                                    : 'outline'
                                            "
                                        >
                                            {{
                                                definition.is_active
                                                    ? t('On')
                                                    : t('Paused')
                                            }}
                                        </Badge>
                                        <Badge variant="outline">
                                            {{ t(definition.category_label) }}
                                        </Badge>
                                    </div>
                                    <p
                                        class="text-base text-muted-foreground"
                                        dir="auto"
                                    >
                                        {{ explainer(definition) }}
                                    </p>
                                </div>
                            </div>

                            <div
                                v-if="can.manage"
                                class="flex flex-wrap items-center gap-2"
                            >
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :data-test="`toggle-type-${definition.id}`"
                                    @click="toggleActive(definition)"
                                >
                                    <!-- Play is a directional triangle, so it mirrors under RTL;
                                         Pause is symmetrical and must not. -->
                                    <component
                                        :is="
                                            definition.is_active ? Pause : Play
                                        "
                                        class="size-4"
                                        :class="
                                            !definition.is_active &&
                                            'rtl:rotate-180'
                                        "
                                        aria-hidden="true"
                                    />
                                    {{
                                        definition.is_active
                                            ? t('Pause')
                                            : t('Resume')
                                    }}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :data-test="`edit-type-${definition.id}`"
                                    @click="openEdit(definition)"
                                >
                                    {{ t('Edit') }}
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :aria-label="
                                        t('Remove :name', {
                                            name: label(definition),
                                        })
                                    "
                                    :data-test="`remove-type-${definition.id}`"
                                    @click="askRemove(definition)"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                </Button>
                            </div>
                        </div>

                        <!-- What it searches for, and what it has done. The counts are the honest
                             measure of whether a type earns its keep. The labels are eyebrows
                             (DM Mono, 12px), which is the pattern §7h.2.2 set for a dt/dd rail. -->
                        <dl
                            class="grid gap-x-6 gap-y-3 border-t border-border pt-4 sm:grid-cols-4"
                        >
                            <div class="space-y-0.5">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                                >
                                    {{ t('Searches') }}
                                </dt>
                                <dd
                                    class="font-mono text-base text-foreground tabular-nums"
                                    dir="ltr"
                                >
                                    {{ definition.query_templates.length }}
                                </dd>
                            </div>
                            <div class="space-y-0.5">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                                >
                                    {{ t('Used by watches') }}
                                </dt>
                                <dd
                                    class="font-mono text-base text-foreground tabular-nums"
                                    dir="ltr"
                                >
                                    {{ definition.watches_count ?? 0 }}
                                </dd>
                            </div>
                            <div class="space-y-0.5">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                                >
                                    {{ t('Signals found') }}
                                </dt>
                                <dd
                                    class="font-mono text-base text-foreground tabular-nums"
                                    dir="ltr"
                                >
                                    {{ definition.signals_count ?? 0 }}
                                </dd>
                            </div>
                            <div class="space-y-0.5">
                                <dt
                                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                                >
                                    {{ t('How sure Alima must be') }}
                                </dt>
                                <dd
                                    class="font-mono text-base text-foreground tabular-nums"
                                    dir="ltr"
                                >
                                    {{
                                        definition.effective_min_confidence
                                    }}/100
                                </dd>
                            </div>
                        </dl>
                    </CardContent>
                </Card>
            </div>
        </section>

        <!-- Alima's library -->
        <section
            v-if="presets.length"
            class="space-y-4"
            data-test="preset-library"
        >
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Alima’s library') }}
                </h2>
                <Button
                    v-if="can.manage && definitions.length"
                    variant="outline"
                    size="sm"
                    :disabled="atCeiling"
                    data-test="add-every-preset-inline"
                    @click="addEveryPreset"
                >
                    {{ t('Add all of them') }}
                </Button>
            </div>

            <p class="max-w-prose text-base text-muted-foreground">
                {{
                    t(
                        'Adding one makes your own copy of it, so tuning it is yours alone and nothing we change later moves under you.',
                    )
                }}
            </p>

            <div
                v-for="group in presetGroups"
                :key="group.category.value"
                class="space-y-3"
            >
                <div class="space-y-0.5">
                    <h3
                        class="text-base font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t(group.category.label) }}
                    </h3>
                    <p class="text-base text-muted-foreground">
                        {{ t(group.category.description) }}
                    </p>
                </div>

                <ul class="grid gap-3 sm:grid-cols-2">
                    <li
                        v-for="preset in group.presets"
                        :key="preset.key"
                        class="flex items-start justify-between gap-3 rounded-2xl border border-border bg-card p-4"
                        :data-test="`preset-${preset.key}`"
                    >
                        <div class="flex min-w-0 gap-3">
                            <span
                                class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-lilac-40 text-ink"
                            >
                                <SignalTypeIcon
                                    :name="preset.icon"
                                    class="size-4"
                                />
                            </span>
                            <div class="min-w-0 space-y-0.5">
                                <p
                                    class="text-base font-semibold text-foreground rtl:font-bold"
                                >
                                    {{ label(preset) }}
                                </p>
                                <p class="text-base text-muted-foreground">
                                    {{ explainer(preset) }}
                                </p>
                            </div>
                        </div>
                        <Button
                            v-if="can.manage"
                            variant="outline"
                            size="sm"
                            :disabled="atCeiling || adding.includes(preset.key)"
                            :data-test="`add-preset-${preset.key}`"
                            @click="addPreset(preset)"
                        >
                            {{ t('Add') }}
                        </Button>
                    </li>
                </ul>
            </div>
        </section>

        <!-- Editor. `DialogScrollContent`, not `DialogContent`: this form is ten fields and four
             textareas, and a fixed, centred panel with no overflow clips Save out of reach on a
             laptop and at 200% zoom (WCAG 1.4.10). -->
        <Dialog
            :open="showEditor"
            @update:open="(open: boolean) => !open && requestClose()"
        >
            <DialogScrollContent class="sm:max-w-2xl" data-test="type-editor">
                <form class="space-y-6" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>
                            {{ editing ? t('Edit type') : t('New type') }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'Alima searches published news for these words, then keeps only what matches your rules.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <!-- One announced panel for a ten-field form, each failure a link into its
                         field (§7h.3). -->
                    <FormErrorSummary
                        :errors="form.errors"
                        :labels="fieldLabels"
                    />

                    <div class="space-y-4">
                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="type-name"
                                :label="t('Name')"
                                required
                                :error="form.errors.name"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <Input
                                        :id="id"
                                        v-model="form.name"
                                        name="name"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        dir="auto"
                                    />
                                </template>
                            </FormField>

                            <FormField
                                id="type-category"
                                :label="t('Group it under')"
                                required
                                :error="form.errors.category"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <NativeSelect
                                        :id="id"
                                        v-model="form.category"
                                        name="category"
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                    >
                                        <option
                                            v-for="category in options.categories"
                                            :key="category.value"
                                            :value="category.value"
                                        >
                                            {{ t(category.label) }}
                                        </option>
                                    </NativeSelect>
                                </template>
                            </FormField>
                        </div>

                        <FormField
                            id="type-description"
                            :label="t('What it means, in one line')"
                            required
                            :hint="
                                t(
                                    'Alima reads this too, so write it for a colleague: “A business is sold and the owner takes cash out.”',
                                )
                            "
                            :error="form.errors.description"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Input
                                    :id="id"
                                    v-model="form.description"
                                    name="description"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    dir="auto"
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="type-icon"
                            :label="t('Icon')"
                            required
                            :error="form.errors.icon"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <NativeSelect
                                    :id="id"
                                    v-model="form.icon"
                                    name="icon"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                >
                                    <option
                                        v-for="icon in options.icons"
                                        :key="icon.value"
                                        :value="icon.value"
                                    >
                                        {{ t(icon.label) }}
                                    </option>
                                </NativeSelect>
                            </template>
                        </FormField>

                        <FormField
                            id="type-templates"
                            :label="t('What to search for')"
                            required
                            :error="form.errors.query_templates"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Textarea
                                    :id="id"
                                    v-model="templatesText"
                                    rows="4"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    placeholder="{location} company acquisition"
                                    dir="auto"
                                />
                            </template>
                        </FormField>
                        <!-- Outside the field so the `{location}` token can be set LTR in its own
                             element: braces are bidi-neutral and would mirror inside an Arabic
                             sentence. -->
                        <p class="text-base text-muted-foreground">
                            {{
                                t(
                                    'One search per line, in plain words — quote marks and search operators find almost nothing in news.',
                                )
                            }}
                            {{ t('Write') }}
                            <code dir="ltr" class="font-mono">{location}</code>
                            {{
                                t(
                                    'where the place goes, and each watch fills it in.',
                                )
                            }}
                        </p>

                        <FormField
                            id="type-includes"
                            :label="t('What counts')"
                            optional
                            :hint="
                                t(
                                    'One per line. Alima keeps an article only when it matches one of these.',
                                )
                            "
                            :error="form.errors.includes"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Textarea
                                    :id="id"
                                    v-model="includesText"
                                    rows="3"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    dir="auto"
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="type-excludes"
                            :label="t('What doesn’t')"
                            optional
                            :hint="
                                t(
                                    'One per line, and this is the half that does the work — round-ups, trackers and rumours read exactly like the real thing.',
                                )
                            "
                            :error="form.errors.excludes"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Textarea
                                    :id="id"
                                    v-model="excludesText"
                                    rows="3"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    dir="auto"
                                />
                            </template>
                        </FormField>

                        <FormField
                            id="type-keywords"
                            :label="t('Buying-signal words')"
                            optional
                            :hint="
                                t(
                                    'One per line. Words from your ideal customer’s buying signals that should also search under this type.',
                                )
                            "
                            :error="form.errors.match_keywords"
                        >
                            <template #default="{ id, describedBy, invalid }">
                                <Textarea
                                    :id="id"
                                    v-model="keywordsText"
                                    rows="2"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                    dir="auto"
                                />
                            </template>
                        </FormField>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="type-confidence"
                                :label="t('How sure Alima must be')"
                                optional
                                :error="form.errors.min_confidence"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <Input
                                        :id="id"
                                        v-model="confidenceFloor"
                                        name="min_confidence"
                                        type="number"
                                        min="0"
                                        max="100"
                                        :placeholder="
                                            String(defaults.min_confidence)
                                        "
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        dir="ltr"
                                    />
                                </template>
                            </FormField>
                            <FormField
                                id="type-relevance"
                                :label="t('How well it must fit you')"
                                optional
                                :error="form.errors.min_relevance"
                            >
                                <template
                                    #default="{ id, describedBy, invalid }"
                                >
                                    <Input
                                        :id="id"
                                        v-model="relevanceFloor"
                                        name="min_relevance"
                                        type="number"
                                        min="0"
                                        max="100"
                                        :placeholder="
                                            String(defaults.min_relevance)
                                        "
                                        :aria-describedby="describedBy"
                                        :aria-invalid="invalid"
                                        dir="ltr"
                                    />
                                </template>
                            </FormField>
                        </div>
                        <p class="text-base text-muted-foreground">
                            {{
                                t(
                                    'Both are out of 100. Leave them empty to use Alima’s, and raise them when a type keeps bringing you things you bin.',
                                )
                            }}
                        </p>

                        <Label
                            class="flex cursor-pointer items-center gap-3 rounded-2xl border border-border p-3 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                        >
                            <input
                                v-model="form.is_active"
                                type="checkbox"
                                name="is_active"
                                class="size-4 accent-brand"
                            />
                            <span class="text-base text-foreground">{{
                                t('Look for this type')
                            }}</span>
                        </Label>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="requestClose"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="form.processing"
                            data-test="save-type"
                        >
                            {{ editing ? t('Save type') : t('Create type') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>

        <!-- The guard on throwing the editor away. Same prompt as the product's other long
             forms. -->
        <UnsavedChangesDialog
            :open="confirmingDiscard"
            @stay="confirmingDiscard = false"
            @discard="discardEdits"
        />

        <!-- Removal -->
        <Dialog :open="removing !== null" @update:open="removing = null">
            <DialogContent data-test="remove-type-dialog">
                <DialogHeader>
                    <DialogTitle>
                        {{ t('Remove :name?', { name: removingName }) }}
                    </DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Alima stops looking for this. The signals it already found stay where they are, and so do any leads you made from them.',
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
                        data-test="confirm-remove-type"
                        @click="confirmRemove"
                    >
                        {{ t('Remove type') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
