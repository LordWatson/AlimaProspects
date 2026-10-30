<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    AlertTriangle,
    ArrowLeft,
    Lock,
    Search,
    ShieldCheck,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
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
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { SubjectDataReport, SubjectRecord } from '@/types';

/*
 * Data-subject requests (Phase 15, docs/compliance.md §3.5) — locate, then erase.
 *
 * A **plain header, not a `PageHero`**: this is a sub-page reached from the compliance centre, and
 * brand-tokens.md §7a reads the hero as a sidebar destination's opening card, with sub-pages
 * following `outreach/Suppressions.vue` instead.
 *
 * The screen's job is to stop a well-meant erasure being a mistake, so it says four things a
 * plainer table would not:
 *
 *  - **What was matched on.** An address match is exact; a name match is a guess, and the banner
 *    says so in those words. "J. Smith" is not reliably "John Smith".
 *  - **Which rows will go.** On a name match every row starts **unticked** and the member chooses.
 *    The server erases only what was ticked, so a search for "John" cannot quietly take four other
 *    Johns with it. On an exact address there is nothing to choose: every match is that person.
 *  - **What cannot be erased, and why, beside the row.** A do-not-contact entry stays — it holds a
 *    hash, not an address, and removing it would let the next search reach the person again. A
 *    locked row with no explanation reads as a bug.
 *  - **Whether the search was cut short.** A capped result is not a complete answer, and a legal
 *    deadline is the wrong place to find that out later.
 *
 * Brand: cream page, one table, status tokens only — Signal Orange is decorative and never a
 * warning (brand-tokens.md §2.1). The table scrolls rather than clipping on a phone, because the
 * column it would cut off is the one explaining why a row is locked.
 */
defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Compliance', href: '/compliance' },
            { title: 'Data requests', href: '/compliance/requests' },
        ],
    },
});

const props = defineProps<{
    filters: { query: string };
    minimum: number;
    report: SubjectDataReport | null;
    options: { categories: { value: string; label: string }[] };
}>();

const { t } = useI18n();
const { date } = useFormat();

const search = ref(props.filters.query);
const confirming = ref(false);

/** A name match is a guess, so nothing is pre-selected; an exact identity needs no selection. */
const isNameMatch = computed(() => props.report?.matched_on === 'name');

const selected = ref<number[]>([]);

watch(
    () => props.report,
    () => {
        selected.value = [];
    },
);

const erasable = computed(() =>
    (props.report?.records ?? []).filter((record) => record.erasable),
);

const allSelected = computed(
    () =>
        erasable.value.length > 0 &&
        selected.value.length === erasable.value.length,
);

/** How many rows the erasure will actually take — what the button and the dialog must say. */
const willErase = computed(() =>
    isNameMatch.value ? selected.value.length : erasable.value.length,
);

function toggle(record: SubjectRecord, checked: boolean): void {
    selected.value = checked
        ? [...new Set([...selected.value, record.id])]
        : selected.value.filter((id) => id !== record.id);
}

function toggleAll(checked: boolean): void {
    selected.value = checked ? erasable.value.map((record) => record.id) : [];
}

const eraseForm = useForm<{
    query: string;
    confirm: boolean;
    records: number[];
}>({
    query: props.filters.query,
    confirm: true,
    records: [],
});

function runSearch(): void {
    router.get(
        '/compliance/requests',
        { query: search.value },
        { preserveState: true, preserveScroll: true },
    );
}

function erase(): void {
    eraseForm.query = props.filters.query;
    eraseForm.records = isNameMatch.value
        ? selected.value
        : erasable.value.map((record) => record.id);

    eraseForm.delete('/compliance/requests', {
        onFinish: () => (confirming.value = false),
    });
}

/** The categories actually present, in the order the server listed them. */
const presentCategories = computed(() =>
    props.report === null
        ? []
        : props.options.categories.filter(
              (category) =>
                  (props.report?.counts[category.value as never] ?? 0) > 0,
          ),
);

/**
 * The identifying line: translated labels (a channel, an outcome, a reason) then the data itself (a
 * masked address, a headline). Joined here rather than on the server, so an Arabic workspace reads
 * Arabic.
 */
function labelsFor(record: SubjectRecord): string {
    return record.labels.map((label) => t(label)).join(' · ');
}
</script>

<template>
    <Head :title="t('Data requests')" />

    <div class="flex flex-col gap-6 py-2">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Compliance') }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ t('Data requests') }}
                </h1>
                <p class="mt-2 text-sm text-muted-foreground">
                    {{
                        t(
                            'Search for an address, a phone number or a name to see everything your team holds about one person. Searching and erasing are both recorded.',
                        )
                    }}
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/compliance">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('Compliance') }}
                </Link>
            </Button>
        </header>

        <!-- Search ------------------------------------------------------------>
        <section class="rounded-2xl border border-border bg-card p-6">
            <form
                class="flex flex-wrap items-end gap-4"
                @submit.prevent="runSearch"
            >
                <div class="min-w-64 flex-1 space-y-2">
                    <Label for="subject">{{
                        t('Address, phone number or name')
                    }}</Label>
                    <Input
                        id="subject"
                        v-model="search"
                        dir="auto"
                        aria-describedby="subject-hint"
                        :minlength="minimum"
                        placeholder="dana@acme.com"
                        data-test="dsar-query"
                    />
                    <p id="subject-hint" class="text-xs text-muted-foreground">
                        {{
                            t(
                                'An address or number finds exactly that person. A name finds candidates for you to check.',
                            )
                        }}
                    </p>
                </div>

                <Button type="submit" data-test="dsar-search">
                    <Search class="size-4" />
                    {{ t('Search') }}
                </Button>
            </form>
        </section>

        <!-- Results ----------------------------------------------------------->
        <template v-if="report">
            <!-- The banner: what was matched, and how far to trust it. -->
            <div
                class="flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border border-border bg-muted/40 p-5"
                data-test="dsar-summary"
            >
                <p class="text-sm">
                    <span class="font-mono font-semibold">{{
                        report.total
                    }}</span>
                    <span class="ms-2 text-muted-foreground">{{
                        t('records found')
                    }}</span>
                </p>

                <Badge v-if="report.suppressed" variant="success">
                    <ShieldCheck class="size-3" aria-hidden="true" />
                    {{ t('Already on your do-not-contact list') }}
                </Badge>

                <Badge
                    v-for="category in presentCategories"
                    :key="category.value"
                    variant="outline"
                >
                    {{ t(category.label) }} ·
                    {{ report.counts[category.value as never] }}
                </Badge>
            </div>

            <p
                v-if="isNameMatch"
                class="flex items-start gap-2 text-sm text-warning"
                data-test="dsar-name-warning"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{
                        t(
                            'You searched by name, so these are candidates rather than a certain match. Tick only the records that belong to the person who asked.',
                        )
                    }}
                </span>
            </p>

            <p
                v-if="report.truncated"
                class="flex items-start gap-2 text-sm text-warning"
            >
                <AlertTriangle
                    class="mt-0.5 size-4 shrink-0"
                    aria-hidden="true"
                />
                <span>
                    {{
                        t(
                            'There were more matches than we will show at once. Narrow the search — this list is not the whole answer.',
                        )
                    }}
                </span>
            </p>

            <!-- `overflow-x-auto`, not `overflow-hidden`: on a phone the clipped column would be
                 the one saying why a row cannot be erased (WCAG 1.4.10 reflow). -->
            <section
                v-if="report.records.length"
                class="overflow-x-auto rounded-2xl border border-border"
            >
                <table class="w-full min-w-3xl text-sm">
                    <caption class="sr-only">
                        {{
                            t(
                                'Records your team holds about this person, and whether each one can be erased.',
                            )
                        }}
                    </caption>
                    <thead class="bg-muted/50">
                        <tr
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            <th
                                v-if="isNameMatch"
                                scope="col"
                                class="p-3 text-start font-medium"
                            >
                                <span class="flex items-center gap-2">
                                    <Checkbox
                                        :model-value="allSelected"
                                        :aria-label="t('Select every record')"
                                        data-test="dsar-select-all"
                                        @update:model-value="
                                            toggleAll(Boolean($event))
                                        "
                                    />
                                    <span class="sr-only">{{
                                        t('Erase this record')
                                    }}</span>
                                </span>
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Record') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Company') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Detail') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Recorded') }}
                            </th>
                            <th scope="col" class="p-3 text-start font-medium">
                                {{ t('Erasure') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody data-test="dsar-records">
                        <tr
                            v-for="record in report.records"
                            :key="`${record.category}-${record.id}`"
                            class="border-t border-border"
                        >
                            <td v-if="isNameMatch" class="p-3">
                                <Checkbox
                                    v-if="record.erasable"
                                    :model-value="selected.includes(record.id)"
                                    :aria-label="
                                        t('Erase :record', {
                                            record: t(record.category_label),
                                        })
                                    "
                                    :data-test="`dsar-pick-${record.category}-${record.id}`"
                                    @update:model-value="
                                        toggle(record, Boolean($event))
                                    "
                                />
                            </td>
                            <th
                                scope="row"
                                class="p-3 text-start font-medium text-foreground"
                            >
                                {{ t(record.category_label) }}
                            </th>
                            <td class="p-3" dir="auto">
                                <Link
                                    v-if="record.lead_id"
                                    :href="`/leads/${record.lead_id}`"
                                    class="text-teal underline-offset-2 hover:underline"
                                >
                                    {{ record.company_name ?? t('View lead') }}
                                </Link>
                                <span v-else class="text-muted-foreground"
                                    >—</span
                                >
                            </td>
                            <td class="p-3" dir="auto">
                                <span class="font-mono text-xs">
                                    {{ labelsFor(record) }}
                                    <!-- `<bdi>` so a masked address or a number keeps its own
                                         direction inside an Arabic row. -->
                                    <bdi v-if="record.detail">{{
                                        record.labels.length
                                            ? ` · ${record.detail}`
                                            : record.detail
                                    }}</bdi>
                                </span>
                            </td>
                            <td
                                class="p-3 font-mono text-xs text-muted-foreground"
                            >
                                {{
                                    record.recorded_at
                                        ? date(record.recorded_at)
                                        : '—'
                                }}
                            </td>
                            <td class="p-3">
                                <span
                                    v-if="record.erasable"
                                    class="text-muted-foreground"
                                >
                                    {{
                                        isNameMatch
                                            ? t('If you tick it')
                                            : t('Will be erased')
                                    }}
                                </span>
                                <!-- Said, not just locked: a row a member cannot act on needs a
                                     reason beside it or it reads as a fault. -->
                                <span
                                    v-else
                                    class="inline-flex items-start gap-1.5 text-xs text-muted-foreground"
                                >
                                    <Lock
                                        class="mt-0.5 size-3.5 shrink-0"
                                        aria-hidden="true"
                                    />
                                    {{
                                        t(
                                            record.retained_reason ??
                                                'Kept on purpose.',
                                        )
                                    }}
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </section>

            <EmptyState
                v-else
                :icon="Search"
                :title="t('Nothing found')"
                :description="
                    t(
                        'Your team holds no records matching that. If the request came by email, reply saying so — that is itself a complete answer.',
                    )
                "
                data-test="dsar-empty"
            />

            <div v-if="erasable.length > 0" class="flex justify-end">
                <Button
                    variant="destructive"
                    :disabled="willErase === 0"
                    data-test="dsar-erase"
                    @click="confirming = true"
                >
                    {{ t('Erase :count records', { count: willErase }) }}
                </Button>
            </div>
        </template>

        <EmptyState
            v-else
            :icon="Search"
            :title="t('Start with an identity')"
            :description="
                t(
                    'Search for the address, number or name the person gave you. Nothing is changed until you ask for it.',
                )
            "
        />
    </div>

    <!-- Erasure is irreversible, so it is confirmed and the confirmation says what stays. -->
    <Dialog v-model:open="confirming">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    t('Erase these records for good?')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        t(
                            'This cannot be undone. Contacts are deleted, message text and notes are removed, and the identity goes on your do-not-contact list so a future search cannot reach them again.',
                        )
                    }}
                    <template v-if="isNameMatch">
                        {{
                            t(
                                'You searched by name, so only the :count records you ticked will go.',
                                { count: willErase },
                            )
                        }}
                    </template>
                </DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="confirming = false">
                    {{ t('Cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    :disabled="eraseForm.processing || willErase === 0"
                    data-test="dsar-erase-confirm"
                    @click="erase"
                >
                    {{ t('Erase them') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
