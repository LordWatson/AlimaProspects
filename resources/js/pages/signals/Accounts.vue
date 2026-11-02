<script setup lang="ts">
/**
 * The accounts screen (S10, ADR 0021).
 *
 * This is where Signals stops being a way to meet strangers and becomes a way to keep up with the
 * companies a team is already working. A list either **mirrors their pipeline** — Alima keeps it in
 * step overnight — or holds **names they typed**, for the accounts they want before they have a
 * record for them.
 *
 * Two things the screen has to say out loud, because neither is guessable:
 *
 * 1. **A synced list's names are an output.** They cannot be edited by hand, or the next sync would
 *    argue with the member. So the screen offers stages and a "sync now" instead of an add box.
 * 2. **A run looks at a window of the list, not all of it.** Forty accounts a day, continuing where
 *    it left off — so a 250-account list comes round about every six days. A member watching a long
 *    list needs to know that, or a quiet morning reads as a broken feature.
 */
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Building2, RefreshCw, Trash2, Users } from '@lucide/vue';
import { computed, ref } from 'vue';
import { FormErrorSummary, FormField } from '@/components/forms';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import { Textarea } from '@/components/ui/textarea';
import { useI18n } from '@/composables/useI18n';
import type {
    SignalWatchlist,
    SignalWatchlistForm,
    WatchlistSourceKind,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Signals', href: '/signals' },
            { title: 'Accounts', href: '/signals/accounts' },
        ],
    },
});

const props = defineProps<{
    watchlists: SignalWatchlist[];
    options: {
        source_kinds: {
            value: WatchlistSourceKind;
            label: string;
            description: string;
        }[];
        stages: { id: number; name: string }[];
        max_entries: number;
        max_lists: number;
        /** How many accounts one check looks at, so the rotation can be explained rather than felt. */
        window: number;
    };
    can: { manage: boolean };
}>();

const { t } = useI18n();

const atCeiling = computed(
    () => props.watchlists.length >= props.options.max_lists,
);

// --- The list editor --------------------------------------------------------------------------
const editing = ref<SignalWatchlist | null>(null);
const showEditor = ref(false);

const form = useForm<SignalWatchlistForm>({
    name: '',
    source_kind: 'pipeline',
    is_active: true,
    stages: [],
    owners: [],
});

const fieldLabels = computed<Record<string, string>>(() => ({
    name: t('Name'),
    source_kind: t('Where the names come from'),
    stages: t('Which stages'),
}));

/** The field ids the summary's links move focus to. */
const fieldAnchors = {
    name: 'watchlist-name',
    source_kind: 'watchlist-source-kind',
    stages: 'watchlist-stages',
} as const;

// Bumped on every submit so a second rejection for the same reason still announces itself.
const attempt = ref(0);
const namesAttempt = ref(0);

function openNew(): void {
    editing.value = null;
    form.clearErrors();
    form.defaults({
        name: '',
        // Pre-chosen, because it is the answer for almost everybody: the accounts worth watching
        // are the ones already in the pipeline, and a member who wants to type names will say so.
        source_kind: 'pipeline',
        is_active: true,
        stages: [],
        owners: [],
    });
    form.reset();
    showEditor.value = true;
}

function openEdit(watchlist: SignalWatchlist): void {
    editing.value = watchlist;
    form.clearErrors();
    form.defaults({
        name: watchlist.name,
        source_kind: watchlist.source_kind,
        is_active: watchlist.is_active,
        stages: [...watchlist.stages],
        owners: [...watchlist.owners],
    });
    form.reset();
    showEditor.value = true;
}

function toggleStage(id: number): void {
    form.stages = form.stages.includes(id)
        ? form.stages.filter((stage) => stage !== id)
        : [...form.stages, id];
}

function submit(): void {
    attempt.value += 1;

    const options = {
        preserveScroll: true,
        onSuccess: () => {
            showEditor.value = false;
        },
    };

    if (editing.value) {
        form.patch(`/signals/accounts/${editing.value.id}`, options);
    } else {
        form.post('/signals/accounts', options);
    }
}

/** Pausing a list stops its watches searching it; it does not forget the names. */
function toggleActive(watchlist: SignalWatchlist): void {
    router.patch(
        `/signals/accounts/${watchlist.id}`,
        {
            name: watchlist.name,
            source_kind: watchlist.source_kind,
            is_active: !watchlist.is_active,
            stages: watchlist.stages,
            owners: watchlist.owners,
        },
        { preserveScroll: true },
    );
}

function sync(watchlist: SignalWatchlist): void {
    router.post(
        `/signals/accounts/${watchlist.id}/sync`,
        {},
        { preserveScroll: true },
    );
}

// Confirmed, because a list is somebody's typing and the watches pointed at it stop finding
// anything. The signals it already produced stay: they are events that happened.
const removing = ref<SignalWatchlist | null>(null);

function confirmRemove(): void {
    const target = removing.value;

    if (!target) {
        return;
    }

    router.delete(`/signals/accounts/${target.id}`, {
        preserveScroll: true,
        onFinish: () => {
            removing.value = null;
        },
    });
}

/**
 * Stop watching one account.
 *
 * The chip that was focused is about to be unmounted, so focus is moved to the list's own heading
 * rather than left to fall on `<body>` — where the next Tab starts again from the top of the page.
 */
function removeEntry(watchlist: SignalWatchlist, entryId: number): void {
    router.delete(`/signals/accounts/${watchlist.id}/entries/${entryId}`, {
        preserveScroll: true,
        onFinish: () => {
            document.getElementById(`watchlist-${watchlist.id}-name`)?.focus();
        },
    });
}

// --- Adding names ----------------------------------------------------------------------------
// A textarea, because the member's source for this is a column out of a spreadsheet or a list in
// an email. The server splits it, so "one per line" has one definition.
const addingTo = ref<SignalWatchlist | null>(null);
const namesForm = useForm<{ names: string }>({ names: '' });

function openAdd(watchlist: SignalWatchlist): void {
    addingTo.value = watchlist;
    namesForm.clearErrors();
    namesForm.defaults({ names: '' });
    namesForm.reset();
}

function submitNames(): void {
    namesAttempt.value += 1;

    const target = addingTo.value;

    if (!target) {
        return;
    }

    namesForm.post(`/signals/accounts/${target.id}/entries`, {
        preserveScroll: true,
        onSuccess: () => {
            addingTo.value = null;
        },
    });
}

/** How long a list takes to come round, in days, given the per-check window. */
function rotationDays(watchlist: SignalWatchlist): number {
    return Math.max(
        1,
        Math.ceil(watchlist.entries_count / Math.max(props.options.window, 1)),
    );
}
</script>

<template>
    <Head :title="t('Accounts')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="08"
            :eyebrow="t('Accounts')"
            :title="t('Keep up with|the ones you know.')"
            :description="
                t(
                    'A watch can follow companies instead of places. Mirror your pipeline, or name the accounts you care about, and Alima tells you when something happens to them.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button
                    v-if="can.manage && watchlists.length"
                    variant="lime"
                    :disabled="atCeiling"
                    :aria-describedby="
                        atCeiling ? 'watchlist-ceiling' : undefined
                    "
                    data-test="new-watchlist"
                    @click="openNew"
                >
                    {{ t('New list') }}
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

        <!-- A disabled button with no reason beside it is not "clear" (brand voice), and the
             ceiling is a number only the server knows. -->
        <p
            v-if="atCeiling && can.manage"
            id="watchlist-ceiling"
            class="text-base text-muted-foreground"
            data-test="watchlist-ceiling"
        >
            {{
                t(
                    'That is as many lists as a team can keep (:max). Remove one, or add these accounts to a list you already have.',
                    { max: options.max_lists },
                )
            }}
        </p>

        <EmptyState
            v-if="!watchlists.length"
            :icon="Building2"
            :title="t('Watch the accounts you already have')"
            :description="
                t(
                    'Alima can mirror your pipeline and tell you when something happens to a company you are working — a sale, a funding round, a new finance chief. Nothing to type: it follows the leads you already have.',
                )
            "
            data-test="no-watchlists"
        >
            <template v-if="can.manage">
                <Button data-test="first-watchlist" @click="openNew">
                    {{ t('Watch my pipeline') }}
                </Button>
            </template>
            <p v-else class="text-base text-muted-foreground">
                {{ t('Ask an admin on your team to set these up.') }}
            </p>
        </EmptyState>

        <section
            v-for="watchlist in watchlists"
            :key="watchlist.id"
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
            :data-test="`watchlist-${watchlist.id}`"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="min-w-0 space-y-1">
                    <h2
                        :id="`watchlist-${watchlist.id}-name`"
                        class="flex flex-wrap items-center gap-2 text-lg font-semibold text-foreground rtl:font-bold"
                        dir="auto"
                    >
                        {{ watchlist.name }}
                        <Badge v-if="!watchlist.is_active" variant="outline">
                            {{ t('Paused') }}
                        </Badge>
                        <Badge v-if="watchlist.is_synced" variant="outline">
                            {{ t(watchlist.source_kind_label) }}
                        </Badge>
                    </h2>
                    <p class="max-w-prose text-base text-muted-foreground">
                        {{ t(watchlist.source_kind_description) }}
                    </p>
                </div>

                <div
                    v-if="can.manage"
                    class="flex flex-wrap items-center gap-2"
                >
                    <Button
                        v-if="watchlist.is_synced"
                        variant="outline"
                        size="sm"
                        :data-test="`sync-${watchlist.id}`"
                        :aria-describedby="`watchlist-${watchlist.id}-name`"
                        @click="sync(watchlist)"
                    >
                        <RefreshCw class="size-4" aria-hidden="true" />
                        {{ t('Sync now') }}
                    </Button>
                    <Button
                        v-if="watchlist.can_edit_entries"
                        variant="outline"
                        size="sm"
                        :data-test="`add-${watchlist.id}`"
                        :aria-describedby="`watchlist-${watchlist.id}-name`"
                        @click="openAdd(watchlist)"
                    >
                        {{ t('Add accounts') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`toggle-${watchlist.id}`"
                        :aria-describedby="`watchlist-${watchlist.id}-name`"
                        @click="toggleActive(watchlist)"
                    >
                        {{ watchlist.is_active ? t('Pause') : t('Resume') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`edit-${watchlist.id}`"
                        :aria-describedby="`watchlist-${watchlist.id}-name`"
                        @click="openEdit(watchlist)"
                    >
                        {{ t('Edit') }}
                    </Button>
                    <Button
                        variant="outline"
                        size="sm"
                        :data-test="`remove-${watchlist.id}`"
                        @click="removing = watchlist"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                        <span class="sr-only">
                            {{ t('Remove :name', { name: watchlist.name }) }}
                        </span>
                    </Button>
                </div>
            </div>

            <!-- What it covers and how often it comes round. The rotation is the thing a member
                 cannot see for themselves, and a long list that seems quiet is the complaint it
                 prevents. -->
            <dl
                class="flex flex-wrap gap-x-6 gap-y-1 font-mono text-xs text-muted-foreground tabular-nums"
            >
                <div class="flex gap-1.5">
                    <dt>{{ t('Accounts') }}:</dt>
                    <dd dir="ltr">
                        {{ watchlist.entries_count }} /
                        {{ options.max_entries }}
                    </dd>
                </div>
                <!-- The number alone, with the unit in the label: ":count days" cannot be
                     pluralised in Arabic, which has a dual and two plural forms (brand review). -->
                <div v-if="watchlist.entries_count > 0" class="flex gap-1.5">
                    <dt>{{ t('Days between checks') }}:</dt>
                    <dd dir="ltr">{{ rotationDays(watchlist) }}</dd>
                </div>
                <div v-if="watchlist.watches_count" class="flex gap-1.5">
                    <dt>{{ t('Watches using it') }}:</dt>
                    <dd dir="ltr">{{ watchlist.watches_count }}</dd>
                </div>
            </dl>

            <p
                v-if="watchlist.entries_count === 0"
                class="text-base text-muted-foreground"
            >
                {{
                    watchlist.is_synced
                        ? t(
                              'Nothing here yet. Press “Sync now”, or check which stages this list follows.',
                          )
                        : t(
                              'No accounts yet. Add the companies you want watched.',
                          )
                }}
            </p>

            <!-- The names themselves. A member needs to be able to see what Alima thinks it is
                 watching, and which of those are leads they already hold. -->
            <ul
                v-if="watchlist.entries?.length"
                class="flex flex-wrap gap-2"
                :data-test="`entries-${watchlist.id}`"
            >
                <li
                    v-for="entry in watchlist.entries"
                    :key="entry.id"
                    class="flex max-w-full items-center gap-2 rounded-full border border-border px-3 py-1"
                >
                    <component
                        :is="entry.entity_type === 'person' ? Users : Building2"
                        class="size-3.5 shrink-0 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <!-- Said in a word, because the icon is the only other place it appears and an
                         icon is not a label (WCAG 1.4.1). -->
                    <span class="sr-only">{{
                        t(entry.entity_type_label)
                    }}</span>
                    <Link
                        v-if="entry.lead_id"
                        :href="`/leads/${entry.lead_id}`"
                        class="rounded text-base break-words text-foreground underline decoration-dotted underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        dir="auto"
                    >
                        {{ entry.name }}
                    </Link>
                    <span
                        v-else
                        class="text-base break-words text-foreground"
                        dir="auto"
                    >
                        {{ entry.name }}
                    </span>
                    <!-- `size-6`, not a bare 14px icon: these sit side by side in dense chips,
                         and 24px is the minimum target (WCAG 2.5.8). -->
                    <button
                        v-if="can.manage && watchlist.can_edit_entries"
                        type="button"
                        class="flex size-6 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:text-destructive focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`remove-entry-${entry.id}`"
                        @click="removeEntry(watchlist, entry.id)"
                    >
                        <Trash2 class="size-3.5" aria-hidden="true" />
                        <span class="sr-only">
                            {{ t('Stop watching :name', { name: entry.name }) }}
                        </span>
                    </button>
                </li>
            </ul>
        </section>

        <!-- The list editor. -->
        <Dialog
            :open="showEditor"
            @update:open="(open: boolean) => !open && (showEditor = false)"
        >
            <DialogScrollContent
                class="sm:max-w-xl"
                data-test="watchlist-editor"
            >
                <form class="space-y-6" @submit.prevent="submit">
                    <DialogHeader>
                        <DialogTitle>
                            {{ editing ? t('Edit list') : t('New list') }}
                        </DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'A watch pointed at this list asks what happened to these companies, instead of what happened in a place.',
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
                        id="watchlist-name"
                        :label="t('Name')"
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
                                :placeholder="t('e.g. Open opportunities')"
                                autocomplete="off"
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <!-- The kind cannot change after the list exists: a synced list's names are an
                         output and a manual list's are somebody's work, so turning one into the
                         other would either delete their typing or adopt it as a sync result. -->
                    <fieldset
                        v-if="!editing"
                        id="watchlist-source-kind"
                        tabindex="-1"
                        class="space-y-3 focus:outline-none"
                    >
                        <legend
                            class="text-base font-medium text-foreground rtl:font-bold"
                        >
                            {{ t('Where the names come from') }}
                        </legend>
                        <div class="space-y-2">
                            <Label
                                v-for="kind in options.source_kinds"
                                :key="kind.value"
                                class="flex cursor-pointer items-start gap-3 rounded-2xl border border-border p-3 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                            >
                                <input
                                    v-model="form.source_kind"
                                    type="radio"
                                    name="source_kind"
                                    class="mt-0.5 size-4 accent-brand"
                                    :value="kind.value"
                                />
                                <span class="space-y-0.5">
                                    <span
                                        class="block text-base font-semibold text-foreground rtl:font-bold"
                                        >{{ t(kind.label) }}</span
                                    >
                                    <span
                                        class="block text-base text-muted-foreground"
                                        >{{ t(kind.description) }}</span
                                    >
                                </span>
                            </Label>
                        </div>
                    </fieldset>

                    <!-- Stages, for a mirrored list. Narrowing them is how a member keeps the cost
                         down: every account on the list is a paid search. -->
                    <fieldset
                        v-if="
                            form.source_kind === 'pipeline' &&
                            options.stages.length
                        "
                        id="watchlist-stages"
                        tabindex="-1"
                        class="space-y-3 focus:outline-none"
                    >
                        <legend
                            class="text-base font-medium text-foreground rtl:font-bold"
                        >
                            {{ t('Which stages') }}
                        </legend>
                        <p class="max-w-prose text-base text-muted-foreground">
                            {{
                                t(
                                    'Leave all of them unticked to follow every stage. Narrowing this is how you keep a long pipeline affordable — every account is a search.',
                                )
                            }}
                        </p>
                        <div class="flex flex-wrap gap-2">
                            <Label
                                v-for="stage in options.stages"
                                :key="stage.id"
                                class="flex cursor-pointer items-center gap-2 rounded-full border border-border px-3 py-1.5 transition-colors has-[:checked]:border-brand has-[:checked]:bg-brand/5 has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring"
                            >
                                <input
                                    type="checkbox"
                                    class="size-4 accent-brand"
                                    :value="stage.id"
                                    :checked="form.stages.includes(stage.id)"
                                    @change="toggleStage(stage.id)"
                                />
                                <span
                                    class="text-base text-foreground"
                                    dir="auto"
                                    >{{ stage.name }}</span
                                >
                            </Label>
                        </div>
                    </fieldset>

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
                            data-test="save-watchlist"
                        >
                            {{ editing ? t('Save changes') : t('Create list') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>

        <!-- Removing a list. -->
        <Dialog
            :open="removing !== null"
            @update:open="(open: boolean) => !open && (removing = null)"
        >
            <DialogContent
                class="sm:max-w-md"
                data-test="remove-watchlist-modal"
            >
                <DialogHeader>
                    <DialogTitle>{{ t('Remove this list?') }}</DialogTitle>
                    <DialogDescription>
                        {{
                            t(
                                'Any watch pointed at it stops looking for these accounts. Events it already found stay in your feed — those happened.',
                            )
                        }}
                    </DialogDescription>
                </DialogHeader>

                <!-- Which list, unconditionally: the title cannot carry a user-typed name and the
                     watches line only appears when something uses it. -->
                <p v-if="removing" class="text-base text-foreground" dir="auto">
                    {{ t('List') }}: {{ removing.name }}
                </p>

                <p
                    v-if="removing?.watches_count"
                    class="text-base text-foreground"
                >
                    {{
                        t('Watches that use “:name”: :count', {
                            name: removing.name,
                            count: removing.watches_count,
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
                        data-test="confirm-remove-watchlist"
                        @click="confirmRemove"
                    >
                        {{ t('Remove the list') }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <!-- Adding names. -->
        <Dialog
            :open="addingTo !== null"
            @update:open="(open: boolean) => !open && (addingTo = null)"
        >
            <DialogScrollContent class="sm:max-w-xl" data-test="add-accounts">
                <form class="space-y-6" @submit.prevent="submitNames">
                    <DialogHeader>
                        <DialogTitle>{{ t('Add accounts') }}</DialogTitle>
                        <DialogDescription>
                            {{
                                t(
                                    'One company per line. Paste a column straight out of a spreadsheet — Alima skips anything already on the list.',
                                )
                            }}
                        </DialogDescription>
                    </DialogHeader>

                    <FormErrorSummary
                        :errors="namesForm.errors"
                        :labels="{ names: t('Companies') }"
                        :anchors="{ names: 'account-names' }"
                        :attempt="namesAttempt"
                    />

                    <FormField
                        id="account-names"
                        :label="t('Companies')"
                        required
                        :error="namesForm.errors.names"
                        :hint="
                            t('Up to :max on one list.', {
                                max: options.max_entries,
                            })
                        "
                    >
                        <template #default="{ id, describedBy, invalid }">
                            <Textarea
                                :id="id"
                                v-model="namesForm.names"
                                rows="8"
                                :aria-describedby="describedBy"
                                :aria-invalid="invalid"
                                required
                                dir="auto"
                            />
                        </template>
                    </FormField>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="addingTo = null"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            type="submit"
                            :disabled="namesForm.processing"
                            data-test="save-accounts"
                        >
                            {{ t('Add them') }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogScrollContent>
        </Dialog>
    </div>
</template>
