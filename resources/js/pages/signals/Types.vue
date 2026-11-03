<script setup lang="ts">
/**
 * The signal-type library (S7, ADR 0018).
 *
 * Two halves, and the difference between them is the whole decision: **your types**, which the team
 * owns and can change, and **Alima's library**, which they cannot — a preset is copied into their
 * own types and tuned from there, so a change we make never silently alters what they are paying to
 * run. The copy on screen says that in words rather than leaving it to be discovered.
 *
 * **S9 made it the builder**, which is three doors into the same review form:
 *
 * 1. the **library**, grouped into vertical packs — the fast path, and the one that teaches the
 *    format by example;
 * 2. the **composer**, a sentence in a box, behind the one paid capability on this screen;
 * 3. **New type**, the hand-written form, on every plan.
 *
 * And one thing that matters more than any of them: **preview**. The S0 spike exists because nobody
 * can tell whether a query template works by reading it — seven of eight looked fine and returned
 * nothing. A builder without a preview would hand that discovery to customers, where it reads as
 * "Signals doesn't work" rather than "that template is too specific". So the editor can run the
 * draft against live news before anything is saved, and says what it would have kept and dropped.
 *
 * The composer's draft and the preview's result arrive as **flashed** data rather than props, which
 * is exactly their lifetime: they live until the member saves or walks away, and nothing about them
 * belongs in a URL or a history entry.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowLeft,
    Check,
    CornerDownLeft,
    Eye,
    Pause,
    Play,
    Shapes,
    Sparkles,
    Trash2,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch as onChange } from 'vue';
import {
    FormErrorSummary,
    FormField,
    UnsavedChangesDialog,
} from '@/components/forms';
import { FilterGroup, FilterToggle } from '@/components/list';
import PageHero from '@/components/PageHero.vue';
import SignalTypeIcon from '@/components/signals/SignalTypeIcon.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
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
import { Spinner } from '@/components/ui/spinner';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    SignalCategory,
    SignalCategoryOption,
    SignalCostFacts,
    SignalDefinition,
    SignalDefinitionDefaults,
    SignalDefinitionForm,
    SignalDraft,
    SignalIcon,
    SignalIconOption,
    SignalPackOption,
    SignalPresetPack,
    SignalPreviewResult,
    SignalPreviewVerdict,
    SignalSourceKind,
    SignalSourceOption,
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
        /** The vertical bundles the library is browsed by (S9). */
        packs: SignalPackOption[];
        /**
         * The short starter subset for this team's market.
         *
         * What "set these up for me" adds. It used to add **every** preset in the library, which
         * was a sensible offer at three and an absurd one at thirty: a UK team would land past
         * their type ceiling, having asked for a search per template per type on every run.
         */
        starter_keys: string[];
        /** The sources a type may run on, with what each is good for (S13). */
        sources: SignalSourceOption[];
    };
    defaults: SignalDefinitionDefaults;
    /** What one template would cost this team a month — the form multiplies as they type. */
    cost: SignalCostFacts;
    /**
     * The same facts per source (S13), keyed by source.
     *
     * A source is a cost multiplier: one template on two sources is two paid searches per run. The
     * form sums the entries for the ticked sources and multiplies by its template count, so the
     * number moves as a box is ticked without a round trip. Every figure in each entry is the
     * server's (ADR 0019 §4).
     */
    cost_by_source: Record<string, SignalCostFacts>;
    can: { manage: boolean; compose: boolean };
}>();

const { t } = useI18n();
const { currency } = useFormat();

/**
 * Wrap a formatted figure so it cannot reorder inside an Arabic sentence.
 *
 * `US$3.20` interpolated into RTL running text has no implicit boundary, so the symbol and the
 * digits can swap sides. The isolate characters are what `<bdi>` does for an element, for a value
 * that has to go through `t()` as a string.
 */
function isolate(value: string): string {
    return `\u2066${value}\u2069`;
}

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

// --- The composer (S9) ------------------------------------------------------------------------
// A sentence in, a draft out. The brief survives a refusal on purpose: a composer that cleared the
// box would make its own reason unactionable.
const brief = ref('');
const composing = ref(false);
const composeError = ref<string | null>(null);

/** Examples in brand voice, and deliberately **not** three variations of the same shape. */
const examples = computed(() => [
    t(
        'Tell me when a UK manufacturer changes hands or the owner sells a controlling stake.',
    ),
    t(
        'I want to know when a company near me wins a big construction contract.',
    ),
    t(
        'A software company has a public outage — that is when they look for an alternative.',
    ),
]);

function composeDraft(): void {
    // Guarded here rather than by `disabled` on the button, because a focused button that becomes
    // disabled drops focus to `<body>` — and the composer then opens a dialog whose return-focus
    // target is nothing (WCAG 2.4.3).
    if (composing.value || atCeiling.value || brief.value.trim() === '') {
        return;
    }

    composeError.value = null;

    router.post(
        '/signals/types/compose',
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

// --- What came back ---------------------------------------------------------------------------
// The draft and the preview are **flashed**, not props: they last until this page is left, which is
// precisely how long a draft should exist. `router.on('flash')` is the same channel the product's
// toasts arrive on.
const draftSummary = ref<string | null>(null);
const preview = ref<SignalPreviewResult | null>(null);
const previewing = ref(false);

const stopListening = router.on('flash', (event) => {
    const flash = (event as CustomEvent).detail?.flash as
        | {
              draft?: SignalDraft;
              brief?: string;
              preview?: SignalPreviewResult;
          }
        | undefined;

    if (!flash) {
        return;
    }

    if (typeof flash.brief === 'string') {
        brief.value = flash.brief;
    }

    if (flash.preview) {
        preview.value = flash.preview;
        revealPreview();
    }

    // Only the composer flashes a draft. The preview used to flash one too, which the listener
    // could not tell apart — so a preview of a saved type reopened the editor as a new one.
    if (flash.draft) {
        applyDraft(flash.draft);
    }
});

/**
 * Move focus to the result when it arrives.
 *
 * A screen-reader user pressed a button and heard nothing: the panel is a plain `v-if` with no live
 * region. Focus rather than `aria-live`, following §7h.10 — a polite region would read all six
 * cards, and this result is long enough that landing on it is more useful than hearing it.
 */
const previewPanel = ref<HTMLElement | null>(null);

function revealPreview(): void {
    requestAnimationFrame(() => {
        previewPanel.value?.focus();
        previewPanel.value?.scrollIntoView({ block: 'nearest' });
    });
}

onBeforeUnmount(() => stopListening());

/**
 * Open the review form on a draft — from the composer, or back from a preview.
 *
 * A refused draft opens nothing: the toast carries the reason, and an empty form underneath it
 * would read as though something had been drafted anyway.
 */
function applyDraft(draft: SignalDraft): void {
    if (!draft.viable || draft.definition === null) {
        return;
    }

    editing.value = null;
    draftSummary.value = draft.summary;
    form.clearErrors();
    // A draft arrives complete, including `source_keys` — which the composer always sets to news,
    // because it drafts what to search for and how to judge it and does not choose where a team
    // spends money (ADR 0020's rule, applied to ADR 0023's new field).
    form.defaults({ ...draft.definition });
    form.reset();
    syncTextareas();
    showEditor.value = true;
}

// --- Browsing the library by pack (S9) ---------------------------------------------------------
// Chips rather than a select, and the product's own `FilterToggle` rather than a local one, so the
// tick that carries the state without colour comes for free (WCAG 1.4.1).
/**
 * Take an example into the box, and put the cursor where the member now has to type.
 *
 * Filling a textarea from a button announces nothing and moves nothing, so a keyboard or
 * screen-reader user has no way to know the box changed.
 */
const briefField = ref<HTMLTextAreaElement | null>(null);

function useExample(example: string): void {
    brief.value = example;
    requestAnimationFrame(() => briefField.value?.focus());
}

const activePacks = ref<SignalPresetPack[]>([]);

function togglePack(pack: SignalPresetPack): void {
    activePacks.value = activePacks.value.includes(pack)
        ? activePacks.value.filter((value) => value !== pack)
        : [...activePacks.value, pack];
}

/** Only the packs that actually have something in them for this team's market. */
const availablePacks = computed(() =>
    props.options.packs.filter((pack) =>
        props.presets.some((preset) => preset.pack === pack.value),
    ),
);

const visiblePresets = computed(() =>
    activePacks.value.length === 0
        ? props.presets
        : props.presets.filter(
              (preset) =>
                  preset.pack !== null &&
                  activePacks.value.includes(preset.pack),
          ),
);

// --- The library ------------------------------------------------------------------------------
// Grouped by category, in the order the server declared them, so the sections read in the order
// they were designed in rather than alphabetically.
const presetGroups = computed(() =>
    props.options.categories
        .map((category) => ({
            category,
            presets: visiblePresets.value.filter(
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

/**
 * Add the starter set — not the library.
 *
 * The one-click path has to stay small. The server declares which presets are starters
 * (`SignalPreset::$isStarter`) and sends them, so the screen does not have to know what a sensible
 * first set is, and the set can grow or shrink without a frontend change.
 */
function addStarters(): void {
    router.post(
        '/signals/types/clone',
        { keys: props.options.starter_keys },
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
    // News, which is where every type starts and what every pre-S13 type runs on.
    source_keys: ['news'],
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
 * The list fields are edited one entry per line, which is how somebody thinks about them, and
 * parsed into the form as they type.
 *
 * **Refs with a one-way sync, not two-way computeds**, and that is a bug fix rather than a style
 * choice. A computed whose setter dropped blank lines and whose getter wrote the filtered text back
 * meant pressing Enter at the end of a field removed the line break it had just inserted: the only
 * way to add a second template was to split an existing line or paste one in. The field was not
 * keyboard-operable for the thing it exists to do (WCAG 2.1.1). The S8 brand review found this in
 * the watch form and said to check wherever else the pattern appeared — here, four times over.
 */
const templatesText = ref('');
const keywordsText = ref('');
const includesText = ref('');
const excludesText = ref('');

/** One entry per non-blank line. The server clamps, squishes and de-duplicates again. */
function parseLines(value: string): string[] {
    return value
        .split('\n')
        .map((line) => line.trim())
        .filter(Boolean);
}

onChange(templatesText, (value) => {
    form.query_templates = parseLines(value);
});
onChange(keywordsText, (value) => {
    form.match_keywords = parseLines(value);
});
onChange(includesText, (value) => {
    form.includes = parseLines(value);
});
onChange(excludesText, (value) => {
    form.excludes = parseLines(value);
});

/**
 * Load the four boxes from the form.
 *
 * Called whenever the form is (re)filled from somewhere else — a new type, an edit, a composer
 * draft, a return from preview — because the text owns the control and the form owns the data, and
 * only this direction is automatic.
 */
function syncTextareas(): void {
    templatesText.value = form.query_templates.join('\n');
    keywordsText.value = form.match_keywords.join('\n');
    includesText.value = form.includes.join('\n');
    excludesText.value = form.excludes.join('\n');
}

// --- What this type will cost (S9) -------------------------------------------------------------
// Every template is a paid search on every run of every watch using the type, multiplied by the
// places those watches cover (ADR 0019 §4). A standing instruction to spend money should say how
// much, where it is created — and the number is only interesting while somebody is adding
// templates, which is why the server sends the per-template facts and this multiplies.
/**
 * Tick or untick a source (S13).
 *
 * Unticking the last one leaves news, rather than leaving nothing. The server already stores news
 * for an empty choice (ADR 0023 §2), so refusing to empty the list here is not a second rule — it is
 * the same rule, made visible at the moment somebody would otherwise see an empty group and a
 * validation error for a state the product does not have.
 */
function toggleSource(value: SignalSourceKind, checked: boolean): void {
    const next = checked
        ? [...new Set([...form.source_keys, value])]
        : form.source_keys.filter((key) => key !== value);

    form.source_keys = next.length > 0 ? next : ['news'];
}

/**
 * The per-template facts for the sources that are actually ticked (S13).
 *
 * Falls back to the news entry when nothing is ticked, which is what the server stores for an empty
 * choice — so the figure on screen and the figure that will be charged agree even mid-edit.
 */
const chosenCosts = computed<SignalCostFacts[]>(() => {
    const chosen = form.source_keys
        .map((key) => props.cost_by_source[key])
        .filter((facts): facts is SignalCostFacts => facts !== undefined);

    return chosen.length > 0 ? chosen : [props.cost];
});

const monthlyCostCents = computed(() =>
    Math.round(
        (chosenCosts.value.reduce(
            (total, facts) => total + facts.cost_micros_per_month,
            0,
        ) *
            form.query_templates.length) /
            10_000,
    ),
);

const monthlyQueries = computed(
    () =>
        chosenCosts.value.reduce(
            (total, facts) => total + facts.queries_per_month,
            0,
        ) * form.query_templates.length,
);

/**
 * Whether the estimate is below the smallest coin we can print.
 *
 * "About $0.00" reads as free, which is a different claim from "too small to bill". A member
 * deciding whether to add a tenth template deserves the second one.
 */
const costIsNegligible = computed(
    () => form.query_templates.length > 0 && monthlyCostCents.value < 1,
);

/**
 * The preview's articles, **grouped by verdict** — kept first, then each problem once.
 *
 * Ungrouped, six cards carried up to six copies of one of five fixed advice sentences, which is
 * wallpaper rather than guidance. Grouped, the advice is said once per problem with the count
 * beside it, and the headlines underneath are the evidence for it.
 */
const previewGroups = computed(() => {
    const result = preview.value;

    if (!result) {
        return [];
    }

    const order: SignalPreviewVerdict[] = [
        'kept',
        'below_confidence',
        'below_relevance',
        'wrong_location',
        'not_an_event',
        'ignored_domain',
        'unclassified',
    ];

    return order
        .map((verdict) => ({
            verdict,
            items: result.items.filter((item) => item.verdict === verdict),
        }))
        .filter((group) => group.items.length > 0)
        .map((group) => ({
            ...group,
            label: group.items[0]!.verdict_label,
            advice: group.items[0]!.verdict_advice,
            // The scores explain these two verdicts and decorate the rest.
            showScores:
                group.verdict === 'below_confidence' ||
                group.verdict === 'below_relevance',
        }));
});

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
        source_keys: ['news'],
    });
    form.reset();
    syncTextareas();
    draftSummary.value = null;
    preview.value = null;
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
        // Never empty: the server guarantees at least one, and a clone of a preset carries news.
        source_keys:
            definition.source_keys.length > 0
                ? [...definition.source_keys]
                : ['news'],
    });
    form.reset();
    syncTextareas();
    draftSummary.value = null;
    preview.value = null;
    showEditor.value = true;
}

/**
 * Run the form's current draft against live news, without saving it (S9).
 *
 * Posts the whole definition rather than an id, deliberately: a preview is about the text in front
 * of the member, which is the point when it differs from the stored row by three words they have
 * just typed. The server flashes the result **and** the draft back, so the form survives the
 * redirect that carries it.
 */
function runPreview(): void {
    if (previewing.value || form.query_templates.length === 0) {
        return;
    }

    router.post(
        '/signals/types/preview',
        { ...form.data() },
        {
            preserveScroll: true,
            // **The form survives the round trip here**, rather than being flashed back and
            // rebuilt. Rebuilding it reset `editing` to null, so previewing a saved type and
            // pressing Save created a duplicate — and `form.reset()` cleared the dirty flag, so the
            // unsaved-changes guard stopped firing. Keeping the state is both simpler and correct.
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
                <!-- `hero`, not `lime`: since S9 the composer below is the page's primary
                     action and a view gets one (brand-tokens §2.3). Writing one by hand is the
                     third door, not the first. -->
                <Button
                    v-if="can.manage && definitions.length"
                    variant="hero"
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

        <!-- The composer (S9): the front door, for the member who knows the moment but not the
             format. The library is below it on purpose — the fastest path for most people is still
             "take one of ours", and a blank text box first would hide that. -->
        <section
            v-if="can.manage"
            class="space-y-3 rounded-2xl border border-border bg-card p-6"
            data-test="composer"
        >
            <div class="flex items-start gap-3">
                <span
                    class="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-lilac-40 text-ink"
                >
                    <Sparkles class="size-4" aria-hidden="true" />
                </span>
                <div class="min-w-0 space-y-1">
                    <h2
                        id="composer-heading"
                        class="text-lg font-semibold text-foreground rtl:font-bold"
                    >
                        {{ t('Describe the moment') }}
                    </h2>
                    <p class="max-w-prose text-base text-muted-foreground">
                        {{
                            t(
                                'Say what happens, in your own words. Alima drafts it; you change what you like and see what it finds before you keep it.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <template v-if="can.compose">
                <div class="space-y-2">
                    <!-- Labelled by the heading above it rather than by a duplicate `sr-only`
                         copy of the same words, and described by its error only: the examples
                         are three 80-character buttons, and reading all three as the field's
                         description every time it takes focus is noise. -->
                    <Textarea
                        id="composer-brief"
                        ref="briefField"
                        v-model="brief"
                        rows="3"
                        aria-labelledby="composer-heading"
                        :placeholder="
                            t(
                                'A hotel group announces a refurbishment of one of its properties.',
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
                        class="text-base text-destructive"
                        role="alert"
                    >
                        {{ composeError }}
                    </p>
                </div>

                <!-- Examples as buttons rather than prose: the point of an example here is to be
                     taken, and a member reading three sentences they have to retype is a member
                     who types nothing. -->
                <div class="space-y-2">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Or start from one of these') }}
                    </p>
                    <ul class="flex flex-col gap-1.5">
                        <!-- Bordered rows with a leading icon, because muted text with a
                             dotted underline is the convention for an abbreviation rather than
                             for something pressable. Choosing one moves focus into the box, so a
                             keyboard user knows it changed. -->
                        <li v-for="example in examples" :key="example">
                            <button
                                type="button"
                                class="flex w-full items-start gap-2 rounded-xl border border-border px-3 py-2 text-start text-base text-foreground transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                dir="auto"
                                @click="useExample(example)"
                            >
                                <CornerDownLeft
                                    class="mt-1 size-4 shrink-0 text-muted-foreground rtl:rotate-180"
                                    aria-hidden="true"
                                />
                                {{ example }}
                            </button>
                        </li>
                    </ul>
                </div>

                <Button
                    variant="lime"
                    :disabled="atCeiling || brief.trim() === ''"
                    :aria-disabled="composing ? true : undefined"
                    :aria-busy="composing ? true : undefined"
                    data-test="compose-draft"
                    @click="composeDraft"
                >
                    <Spinner v-if="composing" class="size-4" />
                    {{ composing ? t('Drafting…') : t('Draft a signal type') }}
                </Button>
            </template>

            <!-- Not on this plan. Said as what they *can* do, because they can: the library and
                 the hand-written form are open to every plan with Signals (plan decision #1), so
                 the refusal has a complete path to the same outcome beside it. -->
            <p
                v-else
                class="max-w-prose text-base text-muted-foreground"
                data-test="composer-unavailable"
            >
                {{
                    t(
                        'Drafting with AI is part of a higher plan. You can still add any type from the library below, or write your own from scratch.',
                    )
                }}
            </p>
        </section>

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
                        v-if="options.starter_keys.length"
                        data-test="add-starter-presets"
                        @click="addStarters"
                    >
                        {{ t('Add Alima’s starter types') }}
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
                <!-- No "add all of them" here since S9. With thirty presets it was a button
                     that could not succeed — a UK team's 27 is past their type ceiling — and
                     should not have: every type is a standing search on every watch. The starter
                     set is offered in the empty state, and everything else is added one at a
                     time, deliberately. -->
                <p
                    v-if="can.manage"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                    dir="ltr"
                >
                    <span class="sr-only"
                        >{{ t('Types in the library') }}:
                    </span>
                    {{ visiblePresets.length }}
                </p>
            </div>

            <p class="max-w-prose text-base text-muted-foreground">
                {{
                    t(
                        'Adding one makes your own copy of it, so tuning it is yours alone and nothing we change later moves under you.',
                    )
                }}
            </p>

            <!-- Browse by what you do (S9). The product's own chips, so the tick that carries the
                 pressed state without relying on colour comes with them (WCAG 1.4.1). No chip
                 pressed means everything, which is the honest default for a library somebody is
                 meeting for the first time. -->
            <FilterGroup
                v-if="availablePacks.length > 1"
                :legend="t('What do you do?')"
                :hint="t('Pick none to see everything.')"
            >
                <FilterToggle
                    v-for="pack in availablePacks"
                    :key="pack.value"
                    :pressed="activePacks.includes(pack.value)"
                    :label="t(pack.label)"
                    :data-test="`pack-${pack.value}`"
                    @toggle="togglePack(pack.value)"
                />
            </FilterGroup>

            <!-- The chosen packs say who they are for. One line each, and only for what is
                 selected — seven audience lines at once is a wall nobody reads. -->
            <p
                v-for="pack in availablePacks.filter((candidate) =>
                    activePacks.includes(candidate.value),
                )"
                :key="`audience-${pack.value}`"
                class="max-w-prose text-base text-foreground"
            >
                {{ t(pack.audience) }}
            </p>

            <p
                v-if="!presetGroups.length"
                class="text-base text-muted-foreground"
                data-test="no-presets-for-pack"
            >
                {{
                    t(
                        'Nothing in the library for that yet — try another, or write your own.',
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
                                    class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground rtl:font-bold"
                                >
                                    {{ label(preset) }}
                                    <!-- Honest shelf-labelling. Every template in the library was
                                         run against live news before it shipped, and a few came
                                         back thin rather than dead — a family office opening is a
                                         real event that is simply rare. Shipping those silently
                                         beside the strong ones would teach a team that Signals is
                                         unreliable. -->
                                    <!-- "Rare" read as a prize. This is a markdown, not a
                                         feature, and the badge should say so in the same
                                         direction as the sentence beside it. -->
                                    <Badge
                                        v-if="preset.is_thin"
                                        variant="outline"
                                        :data-test="`thin-${preset.key}`"
                                    >
                                        {{ t('Finds less') }}
                                    </Badge>
                                </p>
                                <p class="text-base text-muted-foreground">
                                    {{ explainer(preset) }}
                                </p>
                                <p
                                    v-if="preset.is_thin"
                                    class="text-base text-muted-foreground"
                                >
                                    {{
                                        t(
                                            'This one finds less than the others — the event itself is uncommon in the news.',
                                        )
                                    }}
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

                    <!-- What the composer took the brief to mean. Shown because a draft a member
                         did not expect is a draft they should correct rather than trust, and the
                         fastest way to see that is the one sentence it thought it was answering. -->
                    <p
                        v-if="draftSummary"
                        class="rounded-2xl border border-border p-4 text-base text-foreground"
                        dir="auto"
                        data-test="draft-summary"
                    >
                        {{ t('Alima understood') }}:
                        <bdi>{{ draftSummary }}</bdi>
                    </p>

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

                        <!-- Where to look (S13, ADR 0023). Checkboxes rather than a select,
                             because the sources are additive and a member may run both. News is
                             pre-ticked and cannot be the only thing missing: the server stores news
                             for an empty choice, so there is no state in which a saved type searches
                             nowhere. Each one carries its trade-off line from the enum, because the
                             honest answer to "should I add web search?" is "it finds more, and more
                             of it is noise" — and a member who learns that from their feed instead
                             of from here will switch it off and not come back. -->
                        <FormField
                            id="type-sources"
                            :label="t('Where to look')"
                            required
                            :error="form.errors.source_keys"
                            :hint="
                                t(
                                    'Each source you add is a separate paid search per template, on every run.',
                                )
                            "
                        >
                            <template #default="{ describedBy, invalid }">
                                <div
                                    class="space-y-3"
                                    role="group"
                                    :aria-describedby="describedBy"
                                    :aria-invalid="invalid"
                                >
                                    <div
                                        v-for="source in options.sources"
                                        :key="source.value"
                                        class="flex items-start gap-3"
                                    >
                                        <Checkbox
                                            :id="`type-source-${source.value}`"
                                            class="mt-0.5"
                                            :model-value="
                                                form.source_keys.includes(
                                                    source.value,
                                                )
                                            "
                                            :data-test="`source-${source.value}`"
                                            @update:model-value="
                                                toggleSource(
                                                    source.value,
                                                    $event === true,
                                                )
                                            "
                                        />
                                        <div class="min-w-0 flex-1 space-y-1">
                                            <Label
                                                :for="`type-source-${source.value}`"
                                                class="font-medium"
                                            >
                                                {{ t(source.label) }}
                                            </Label>
                                            <p
                                                v-if="source.description"
                                                class="text-sm text-muted-foreground"
                                                dir="auto"
                                            >
                                                {{ t(source.description) }}
                                            </p>
                                        </div>
                                    </div>
                                </div>
                            </template>
                        </FormField>

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

                    <!-- What this will cost, and what it will find: the two questions a member
                         cannot answer by reading their own templates. Both sit immediately above
                         Save, because that is the moment they matter. -->
                    <section
                        class="space-y-4 rounded-2xl border border-border p-4"
                        aria-labelledby="before-you-save"
                        data-test="before-you-save"
                    >
                        <h3
                            id="before-you-save"
                            class="text-base font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('Before you save') }}
                        </h3>

                        <!-- A standing instruction to spend money says how much, where it is
                             created. The figure changes as templates are added, which is the only
                             time anybody is deciding about it (ADR 0019 §4). -->
                        <p
                            class="text-base text-muted-foreground"
                            data-test="cost-line"
                        >
                            <template v-if="form.query_templates.length === 0">
                                {{
                                    t(
                                        'Add a search template and Alima will tell you what this type costs to run.',
                                    )
                                }}
                            </template>
                            <template v-else-if="costIsNegligible">
                                {{
                                    t(
                                        'Searches a month: :queries — less than a cent.',
                                        { queries: monthlyQueries },
                                    )
                                }}
                            </template>
                            <template v-else-if="cost.hypothetical">
                                {{
                                    t(
                                        'About :queries searches a month on one daily watch, costing roughly :cost. You have no watches yet, so this is what a first one would cost.',
                                        {
                                            queries: monthlyQueries,
                                            cost: isolate(
                                                currency(
                                                    monthlyCostCents,
                                                    'USD',
                                                ),
                                            ),
                                        },
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    t(
                                        'About :queries searches a month if you add this to all :watches of your watches, costing roughly :cost in search fees.',
                                        {
                                            queries: monthlyQueries,
                                            watches: cost.watch_count,
                                            cost: isolate(
                                                currency(
                                                    monthlyCostCents,
                                                    'USD',
                                                ),
                                            ),
                                        },
                                    )
                                }}
                            </template>
                        </p>

                        <!-- `aria-disabled` while busy rather than `disabled`: a focused button
                             that becomes disabled drops focus to `<body>`, inside a dialog (WCAG
                             2.4.3). The handler guards instead. `disabled` stays for the
                             no-templates case, where nothing is focused yet. -->
                        <Button
                            type="button"
                            variant="outline"
                            :disabled="form.query_templates.length === 0"
                            :aria-disabled="previewing ? true : undefined"
                            :aria-busy="previewing ? true : undefined"
                            data-test="run-preview"
                            @click="runPreview"
                        >
                            <Spinner v-if="previewing" class="size-4" />
                            <Eye v-else class="size-4" aria-hidden="true" />
                            {{
                                previewing
                                    ? t('Looking…')
                                    : t('Show me what this finds')
                            }}
                        </Button>

                        <!-- The result, **grouped by verdict**. One card per article carried its
                             own copy of one of five fixed advice sentences, which is wallpaper
                             rather than guidance: six cards could say "tighten the templates"
                             six times. Grouped, each problem is stated once with its count, and
                             the headlines under it are the evidence. Kept comes first. -->
                        <div
                            v-if="preview"
                            ref="previewPanel"
                            tabindex="-1"
                            class="space-y-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            aria-labelledby="preview-diagnosis"
                            data-test="preview-result"
                        >
                            <div class="space-y-1">
                                <p
                                    id="preview-diagnosis"
                                    class="text-base font-semibold text-foreground rtl:font-bold"
                                    data-test="preview-diagnosis"
                                >
                                    {{ t(preview.diagnosis) }}
                                </p>
                                <!-- Two numbers, in the sentence rather than in a stat row: the
                                     third and fourth ("events", "searches") were restating the
                                     diagnosis and the member's own template count. -->
                                <p class="text-base text-muted-foreground">
                                    {{
                                        t(
                                            'Looked at :found, would keep :kept. This is a sample from one place — a real watch searches every place and type you give it.',
                                            {
                                                found: preview.result_count,
                                                kept: preview.kept_count,
                                            },
                                        )
                                    }}
                                </p>
                            </div>

                            <div
                                v-for="group in previewGroups"
                                :key="group.verdict"
                                class="space-y-2 rounded-xl border border-border p-3"
                                :data-test="`preview-${group.verdict}`"
                            >
                                <p
                                    class="flex flex-wrap items-center gap-2 text-base font-semibold text-foreground rtl:font-bold"
                                >
                                    <Badge
                                        :variant="
                                            group.verdict === 'kept'
                                                ? 'brand'
                                                : 'outline'
                                        "
                                    >
                                        <Check
                                            v-if="group.verdict === 'kept'"
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        {{ t(group.label) }}
                                    </Badge>
                                    <span
                                        class="font-mono text-base tabular-nums"
                                        dir="ltr"
                                        >{{ group.items.length }}</span
                                    >
                                </p>

                                <!-- Once per group. `kept` needs none: the badge is the advice. -->
                                <p
                                    v-if="group.verdict !== 'kept'"
                                    class="text-base text-muted-foreground"
                                >
                                    {{ t(group.advice) }}
                                </p>

                                <ul class="space-y-1.5">
                                    <li
                                        v-for="item in group.items"
                                        :key="item.url"
                                        class="text-base text-foreground"
                                        dir="auto"
                                    >
                                        {{ item.headline }}
                                        <span
                                            v-if="group.showScores"
                                            class="font-mono text-base text-muted-foreground tabular-nums"
                                            dir="ltr"
                                        >
                                            <bdi
                                                >{{ t('Sure') }}
                                                {{ item.confidence }}</bdi
                                            >
                                            <bdi
                                                >{{ t('Fit') }}
                                                {{ item.relevance }}</bdi
                                            >
                                        </span>
                                    </li>
                                </ul>

                                <!-- The queries behind a disclosure: useful when somebody is
                                     hunting the template that pulled a result in, and small print
                                     the rest of the time. -->
                                <details
                                    class="text-base text-muted-foreground"
                                >
                                    <summary
                                        class="cursor-pointer rounded focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {{ t('Which search found these') }}
                                    </summary>
                                    <ul class="mt-1 space-y-0.5">
                                        <li
                                            v-for="item in group.items"
                                            :key="`q-${item.url}`"
                                            class="font-mono text-base"
                                            dir="auto"
                                        >
                                            <bdi>{{ item.query }}</bdi>
                                            <bdi v-if="item.source">{{
                                                item.source
                                            }}</bdi>
                                        </li>
                                    </ul>
                                </details>
                            </div>
                        </div>
                    </section>

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
