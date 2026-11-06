<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { BookOpen, Inbox, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
import { Textarea } from '@/components/ui/textarea';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/composables/useFormat';
import type { AdminSignalPreset, AdminSignalSubmission } from '@/types';

/*
 * Curating the shared signal library (S15 task 4, plan decision #3).
 *
 * **English only**, like every admin screen: an internal tool carries no EN/AR obligation
 * (`docs/modules/admin.md`).
 *
 * Two halves, and the asymmetry between them is the decision this screen encodes:
 *
 * - **The library** is ours to edit. A change bumps the preset's version, which is what lets every
 *   team holding a clone be *offered* it — never given it. The two numbers that matter on each row
 *   are how many teams run a copy and how many of those are behind, because a change nobody takes
 *   is a change that was not wanted.
 * - **The queue** is somebody else's work. A team offered their own type; it keeps running whatever
 *   we decide. The rows deliberately **do not say whose they are** — an operator choosing between
 *   two submissions should be choosing on the evidence, and the evidence is the type's own funnel.
 *
 * Nothing here writes a preset itself: every button posts to a route that calls a **Signals**
 * Action, because what a preset is belongs to ADR 0018 §2. Withdrawing one is a soft delete and
 * clones are untouched — a team paid for a type, not for a subscription to our opinion of it.
 */
const props = defineProps<{
    presets: AdminSignalPreset[];
    queue: AdminSignalSubmission[];
    options: {
        categories: { value: string; label: string }[];
        icons: { value: string; label: string }[];
        sources: { value: string; label: string }[];
    };
}>();

const { number } = useFormat();

const behind = computed(() =>
    props.presets.reduce((total, preset) => total + preset.clones_behind, 0),
);

// --- The preset editor ------------------------------------------------------------------------
const editing = ref<AdminSignalPreset | null>(null);
const showEditor = ref(false);

type PresetForm = {
    key: string;
    name: string;
    description: string;
    category: string;
    icon: string;
    is_active: boolean;
    query_templates: string;
    markets: string;
    min_confidence: number | null;
    min_relevance: number | null;
    source_keys: string[];
};

const form = useForm<PresetForm>({
    key: '',
    name: '',
    description: '',
    category: props.options.categories[0]?.value ?? '',
    icon: props.options.icons[0]?.value ?? '',
    is_active: true,
    query_templates: '',
    markets: '',
    min_confidence: null,
    min_relevance: null,
    source_keys: [],
});

function openNew(): void {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        key: '',
        name: '',
        description: '',
        category: props.options.categories[0]?.value ?? '',
        icon: props.options.icons[0]?.value ?? '',
        is_active: true,
        query_templates: '',
        markets: '',
        min_confidence: null,
        min_relevance: null,
        source_keys: [],
    });
    form.reset();
    showEditor.value = true;
}

function openEdit(preset: AdminSignalPreset): void {
    editing.value = preset;
    form.clearErrors();
    form.defaults({
        // Shown and disabled on an edit: a preset's key is the identity every clone, every signal
        // and every seed migration refers to, so it never changes.
        key: preset.key,
        name: preset.name,
        description: preset.description ?? '',
        category: preset.category,
        icon: preset.icon,
        is_active: preset.is_active,
        // One template per line, which is how the tenant form takes them too — an operator's
        // source for these is a list, not a widget. **Filled from the preset**, which the first
        // draft did not do: the row carried only a count, so editing a preset's name opened a form
        // whose Searches box was empty and offered to save it that way (review).
        query_templates: (preset.query_templates ?? []).join('\n'),
        markets: (preset.markets ?? []).join(', '),
        min_confidence: preset.min_confidence ?? null,
        min_relevance: preset.min_relevance ?? null,
        source_keys: [...(preset.source_keys ?? [])],
    });
    form.reset();
    showEditor.value = true;
}

/**
 * The payload the save routes take.
 *
 * Templates and markets are typed as text and split here, because that is the shape an operator's
 * own notes are in. The server validates the result against Signals' own rules — the same ones the
 * tenant form obeys, so nothing can be put in the library that no team's editor would accept.
 */
function submit(): void {
    const payload = {
        ...form.data(),
        query_templates: form.query_templates
            .split('\n')
            .map((line) => line.trim())
            .filter((line) => line !== ''),
        markets: form.markets
            .split(',')
            .map((market) => market.trim().toLowerCase())
            .filter((market) => market !== ''),
    };

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        router.patch(
            `/admin/signal-library/presets/${editing.value.id}`,
            payload,
            options,
        );

        return;
    }

    router.post('/admin/signal-library/presets', payload, options);
}

function toggleSource(key: string): void {
    form.source_keys = form.source_keys.includes(key)
        ? form.source_keys.filter((source) => source !== key)
        : [...form.source_keys, key];
}

/** Stop offering a preset, or start again. Deliberately not part of the editor: no version bump. */
function toggleOffered(preset: AdminSignalPreset): void {
    router.post(
        `/admin/signal-library/presets/${preset.id}/offered`,
        {},
        { preserveScroll: true },
    );
}

const withdrawing = ref<AdminSignalPreset | null>(null);

function confirmWithdraw(): void {
    const target = withdrawing.value;

    if (!target) return;

    router.delete(`/admin/signal-library/presets/${target.id}`, {
        preserveScroll: true,
        onFinish: () => {
            withdrawing.value = null;
        },
    });
}

// --- Answering a submission -------------------------------------------------------------------
const answering = ref<AdminSignalSubmission | null>(null);
const decision = ref<'promote' | 'reject'>('promote');

const answer = useForm<{ note: string; key: string }>({ note: '', key: '' });

function openAnswer(
    submission: AdminSignalSubmission,
    choice: 'promote' | 'reject',
): void {
    answering.value = submission;
    decision.value = choice;
    answer.clearErrors();
    answer.defaults({ note: '', key: '' });
    answer.reset();
}

function sendAnswer(): void {
    const target = answering.value;

    if (!target) return;

    answer.post(
        `/admin/signal-library/submissions/${target.id}/${decision.value}`,
        {
            preserveScroll: true,
            onSuccess: () => {
                answering.value = null;
            },
        },
    );
}

/** A kept/converted share, or an em dash where nothing was found — a rate over nothing is not zero. */
function share(part: number, whole: number): string {
    return whole < 1 ? '—' : `${Math.round((part / whole) * 100)}%`;
}
</script>

<template>
    <Head title="Signal library" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · signals"
            title="The shared library,|curated."
            lede="What every tenant is offered to run, and what they have offered back. A change bumps the preset's version; teams holding a copy are offered it and never given it."
        />

        <!-- The two figures that say whether the library is working: how much of it is in use, and
             how much of the last change nobody took. -->
        <div class="grid gap-4 sm:grid-cols-3">
            <Card>
                <CardContent class="space-y-1 pt-6">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        Presets
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ number(presets.length) }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="space-y-1 pt-6">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        Clones behind
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ number(behind) }}
                    </p>
                    <p class="text-sm text-muted-foreground">
                        Teams who have not taken the latest change.
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardContent class="space-y-1 pt-6">
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        Waiting for review
                    </p>
                    <p class="text-2xl font-semibold tabular-nums">
                        {{ number(queue.length) }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <!-- The queue first. A submission nobody has answered is somebody waiting. -->
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">Offered by tenants</h2>
            </div>

            <EmptyState
                v-if="!queue.length"
                :icon="Inbox"
                title="Nothing waiting"
                description="When a team offers one of their signal types to the library, it appears here with its own performance — and without saying whose it is."
                data-test="no-submissions"
            />

            <Card v-for="submission in queue" :key="submission.id">
                <CardContent class="space-y-4 pt-6">
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                    >
                        <div class="min-w-0 space-y-1">
                            <h3
                                class="flex flex-wrap items-center gap-2 font-semibold"
                            >
                                {{ submission.name ?? submission.key }}
                                <Badge
                                    v-if="!submission.is_available"
                                    variant="outline"
                                >
                                    Type since removed
                                </Badge>
                            </h3>
                            <p
                                class="max-w-prose text-sm text-muted-foreground"
                            >
                                {{ submission.description }}
                            </p>
                        </div>
                        <div class="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                :disabled="!submission.is_available"
                                :data-test="`promote-${submission.id}`"
                                @click="openAnswer(submission, 'promote')"
                            >
                                Promote
                            </Button>
                            <Button
                                variant="outline"
                                size="sm"
                                :data-test="`reject-${submission.id}`"
                                @click="openAnswer(submission, 'reject')"
                            >
                                Turn down
                            </Button>
                        </div>
                    </div>

                    <!-- The evidence, and the only basis for the decision. No team name anywhere. -->
                    <dl
                        class="flex flex-wrap gap-x-6 gap-y-1 text-sm text-muted-foreground"
                    >
                        <div class="flex gap-1.5">
                            <dt>Found:</dt>
                            <dd class="font-mono tabular-nums">
                                {{ number(submission.performance.found) }}
                            </dd>
                        </div>
                        <div class="flex gap-1.5">
                            <dt>Kept:</dt>
                            <dd class="font-mono tabular-nums">
                                {{
                                    share(
                                        submission.performance.kept,
                                        submission.performance.found,
                                    )
                                }}
                            </dd>
                        </div>
                        <div class="flex gap-1.5">
                            <dt>Converted:</dt>
                            <dd class="font-mono tabular-nums">
                                {{ number(submission.performance.converted) }}
                            </dd>
                        </div>
                        <div class="flex gap-1.5">
                            <dt>Searches:</dt>
                            <dd class="font-mono tabular-nums">
                                {{ number(submission.templates.length) }}
                            </dd>
                        </div>
                    </dl>

                    <!-- The author's own covering note. Prose, because there is nothing to
                         translate about somebody's own words. -->
                    <p
                        v-if="submission.note"
                        class="max-w-prose border-s-2 border-border ps-3 text-sm italic"
                        dir="auto"
                    >
                        {{ submission.note }}
                    </p>

                    <ul class="space-y-1">
                        <li
                            v-for="(template, index) in submission.templates"
                            :key="index"
                            class="font-mono text-xs text-muted-foreground"
                            dir="ltr"
                        >
                            {{ template }}
                        </li>
                    </ul>
                </CardContent>
            </Card>
        </section>

        <!-- The library itself. -->
        <section class="space-y-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold">The library</h2>
                <Button size="sm" data-test="new-preset" @click="openNew">
                    New preset
                </Button>
            </div>

            <EmptyState
                v-if="!presets.length"
                :icon="BookOpen"
                title="The library is empty"
                description="Nothing is being offered to tenants. The seeded packs normally live here — if this is a fresh database, run the preset seeder."
                data-test="no-presets"
            />

            <Table v-else>
                <TableHeader>
                    <TableRow>
                        <TableHead>Preset</TableHead>
                        <TableHead>Key</TableHead>
                        <TableHead class="text-end">Version</TableHead>
                        <TableHead class="text-end">Searches</TableHead>
                        <TableHead class="text-end">In use</TableHead>
                        <TableHead class="text-end">Behind</TableHead>
                        <TableHead
                            ><span class="sr-only">Actions</span></TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow
                        v-for="preset in presets"
                        :key="preset.id"
                        :data-test="`preset-${preset.id}`"
                    >
                        <TableCell class="font-medium">
                            <span class="flex flex-wrap items-center gap-2">
                                {{ preset.name }}
                                <Badge
                                    v-if="!preset.is_active"
                                    variant="outline"
                                >
                                    Not offered
                                </Badge>
                            </span>
                        </TableCell>
                        <TableCell class="font-mono text-xs" dir="ltr">
                            {{ preset.key }}
                        </TableCell>
                        <TableCell class="text-end font-mono tabular-nums">
                            {{ preset.version }}
                        </TableCell>
                        <TableCell class="text-end font-mono tabular-nums">
                            {{ preset.templates }}
                        </TableCell>
                        <TableCell class="text-end font-mono tabular-nums">
                            {{ number(preset.clones) }}
                        </TableCell>
                        <TableCell class="text-end font-mono tabular-nums">
                            {{ number(preset.clones_behind) }}
                        </TableCell>
                        <TableCell>
                            <div class="flex flex-wrap justify-end gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :data-test="`edit-preset-${preset.id}`"
                                    @click="openEdit(preset)"
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :data-test="`offer-preset-${preset.id}`"
                                    @click="toggleOffered(preset)"
                                >
                                    {{
                                        preset.is_active
                                            ? 'Stop offering'
                                            : 'Offer again'
                                    }}
                                </Button>
                                <Button
                                    variant="outline"
                                    size="sm"
                                    :data-test="`withdraw-preset-${preset.id}`"
                                    @click="withdrawing = preset"
                                >
                                    <Trash2 class="size-4" aria-hidden="true" />
                                    <span class="sr-only">
                                        Withdraw {{ preset.name }}
                                    </span>
                                </Button>
                            </div>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <!-- The preset editor. -->
        <Dialog
            :open="showEditor"
            @update:open="(value: boolean) => !value && (showEditor = false)"
        >
            <DialogScrollContent class="sm:max-w-2xl" data-test="preset-editor">
                <form class="space-y-6" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>
                            {{ editing ? 'Edit preset' : 'New preset' }}
                        </DialogTitle>
                        <DialogDescription>
                            Saving an existing preset bumps its version. Teams
                            holding a copy are offered the change, per field —
                            nothing is applied for them.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-2">
                        <Label for="preset-key">Key</Label>
                        <Input
                            id="preset-key"
                            v-model="form.key"
                            :disabled="editing !== null"
                            placeholder="uk_acquisition"
                            autocomplete="off"
                            dir="ltr"
                        />
                        <p class="text-sm text-muted-foreground">
                            Lower case and underscores. It never changes: clones
                            point at it, signals record it, and the seed
                            migrations name it.
                        </p>
                        <p
                            v-if="form.errors.key"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.key }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="preset-name">Name</Label>
                        <Input
                            id="preset-name"
                            v-model="form.name"
                            required
                            autocomplete="off"
                        />
                        <p
                            v-if="form.errors.name"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.name }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="preset-description">What it finds</Label>
                        <Textarea
                            id="preset-description"
                            v-model="form.description"
                            rows="2"
                        />
                        <p
                            v-if="form.errors.description"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.description }}
                        </p>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div class="space-y-2">
                            <Label for="preset-category">Category</Label>
                            <NativeSelect
                                id="preset-category"
                                v-model="form.category"
                            >
                                <option
                                    v-for="category in options.categories"
                                    :key="category.value"
                                    :value="category.value"
                                >
                                    {{ category.label }}
                                </option>
                            </NativeSelect>
                        </div>
                        <div class="space-y-2">
                            <Label for="preset-icon">Icon</Label>
                            <NativeSelect id="preset-icon" v-model="form.icon">
                                <option
                                    v-for="icon in options.icons"
                                    :key="icon.value"
                                    :value="icon.value"
                                >
                                    {{ icon.label }}
                                </option>
                            </NativeSelect>
                        </div>
                    </div>

                    <div class="space-y-2">
                        <Label for="preset-templates">Searches</Label>
                        <Textarea
                            id="preset-templates"
                            v-model="form.query_templates"
                            rows="6"
                            placeholder="acquisition {location}"
                            dir="ltr"
                        />
                        <p class="text-sm text-muted-foreground">
                            One per line. Every template in this library has
                            been run against the live provider before shipping —
                            a preset nobody ran is a guess with a label on it.
                        </p>
                        <p
                            v-if="form.errors.query_templates"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.query_templates }}
                        </p>
                    </div>

                    <div class="space-y-2">
                        <Label for="preset-markets">Markets</Label>
                        <Input
                            id="preset-markets"
                            v-model="form.markets"
                            placeholder="uk, uae"
                            autocomplete="off"
                            dir="ltr"
                        />
                        <p class="text-sm text-muted-foreground">
                            Comma separated. Leave empty for a type that works
                            anywhere.
                        </p>
                    </div>

                    <fieldset class="space-y-2">
                        <legend class="text-sm font-medium">Sources</legend>
                        <div class="flex flex-wrap gap-2">
                            <Label
                                v-for="source in options.sources"
                                :key="source.value"
                                class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 has-[:checked]:border-brand has-[:checked]:bg-brand/5"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4 accent-brand"
                                    :checked="
                                        form.source_keys.includes(source.value)
                                    "
                                    @change="toggleSource(source.value)"
                                />
                                <span class="text-sm">{{ source.label }}</span>
                            </Label>
                        </div>
                    </fieldset>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="showEditor = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" data-test="save-preset">
                            {{
                                editing
                                    ? 'Save and bump version'
                                    : 'Add to the library'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>

        <!-- Answering a submission. The note is required either way: a rejection with no reason is
             the platform taking somebody's contribution and saying nothing. -->
        <Dialog
            :open="answering !== null"
            @update:open="(value: boolean) => !value && (answering = null)"
        >
            <DialogScrollContent
                class="sm:max-w-lg"
                data-test="answer-submission"
            >
                <form class="space-y-6" @submit.prevent="sendAnswer">
                    <DialogHeader>
                        <DialogTitle>
                            {{
                                decision === 'promote'
                                    ? 'Promote to the library'
                                    : 'Turn this down'
                            }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                decision === 'promote'
                                    ? 'A new preset is created from their type. Theirs is untouched and keeps running.'
                                    : 'They keep their own type, untouched. Your note is what they read.'
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <div class="space-y-2">
                        <Label for="answer-note">Note to the team</Label>
                        <Textarea
                            id="answer-note"
                            v-model="answer.note"
                            rows="3"
                            required
                        />
                        <p
                            v-if="answer.errors.note"
                            class="text-sm text-destructive"
                        >
                            {{ answer.errors.note }}
                        </p>
                    </div>

                    <div v-if="decision === 'promote'" class="space-y-2">
                        <Label for="answer-key"
                            >Fold into an existing preset</Label
                        >
                        <Input
                            id="answer-key"
                            v-model="answer.key"
                            placeholder="uk_acquisition"
                            autocomplete="off"
                            dir="ltr"
                        />
                        <p class="text-sm text-muted-foreground">
                            Optional. Name a preset's key to update it and bump
                            its version; leave it empty to add a new one.
                        </p>
                        <p
                            v-if="answer.errors.key"
                            class="text-sm text-destructive"
                        >
                            {{ answer.errors.key }}
                        </p>
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="answering = null"
                        >
                            Cancel
                        </Button>
                        <!-- Not `destructive` for a rejection: it destroys nothing. The team
                             keeps their own type, still running, and the only thing that changes
                             is our answer (brand-tokens.md §7h.11 — discarding is not
                             destructive). -->
                        <Button
                            type="submit"
                            :variant="
                                decision === 'promote' ? 'default' : 'outline'
                            "
                            data-test="send-answer"
                        >
                            {{
                                decision === 'promote' ? 'Promote' : 'Turn down'
                            }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>

        <!-- Withdrawing keeps every clone running, which is not what somebody would assume. -->
        <Dialog
            :open="withdrawing !== null"
            @update:open="(value: boolean) => !value && (withdrawing = null)"
        >
            <DialogScrollContent
                class="sm:max-w-lg"
                data-test="withdraw-preset-dialog"
            >
                <DialogHeader>
                    <DialogTitle>Withdraw this preset?</DialogTitle>
                    <DialogDescription>
                        It stops being offered to new teams. Every team already
                        running a copy keeps theirs, unchanged — a clone is a
                        copy, not a reference.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="withdrawing = null"
                        >Keep it</Button
                    >
                    <Button
                        variant="destructive"
                        data-test="confirm-withdraw-preset"
                        @click="confirmWithdraw"
                    >
                        Withdraw it
                    </Button>
                </DialogFooter>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
