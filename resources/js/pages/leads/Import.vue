<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, FileUp, Upload } from '@lucide/vue';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Leads', href: '/leads' },
            { title: 'Import', href: '/leads/import' },
        ],
    },
});

const props = defineProps<{
    /** The fields an import may write, from `ImportLeadsAction::FIELDS`. */
    fields: string[];
    maxRows: number;
    maxUploadKb: number;
}>();

const { t } = useI18n();
const { number } = useFormat();

/**
 * The header row is read **here**, in the browser, rather than in a first request to the server.
 * A two-step server flow would mean storing the upload between the two steps, and a file full of
 * someone's leads is the last thing to leave lying around.
 */
const headers = ref<string[]>([]);
const sampleRow = ref<string[]>([]);
const fileName = ref<string | null>(null);
const readError = ref<string | null>(null);

const form = useForm<{
    file: File | null;
    mapping: Record<string, string>;
}>({
    file: null,
    mapping: {},
});

/** The labels for the importable fields. Company name is the only one that is required. */
const fieldLabels: Record<string, string> = {
    company_name: 'Company name',
    company_number: 'Company number',
    website: 'Website',
    industry: 'Industry',
    employee_range: 'Team size',
    locality: 'Town or city',
    region: 'Region',
    postal_code: 'Postcode',
    country: 'Country code',
    contact_name: 'Contact name',
    contact_title: 'Contact job title',
    contact_email: 'Contact email',
    contact_phone: 'Contact phone',
    tags: 'Tags',
};

/** Split a CSV line on the delimiter that appears most, respecting double quotes. */
function splitLine(line: string, delimiter: string): string[] {
    const cells: string[] = [];
    let cell = '';
    let quoted = false;

    for (let i = 0; i < line.length; i++) {
        const char = line[i];

        if (char === '"') {
            if (quoted && line[i + 1] === '"') {
                cell += '"';
                i++;
            } else {
                quoted = !quoted;
            }
        } else if (char === delimiter && !quoted) {
            cells.push(cell.trim());
            cell = '';
        } else {
            cell += char;
        }
    }

    cells.push(cell.trim());

    return cells;
}

function sniff(line: string): string {
    return [',', ';', '\t']
        .map((d) => ({ d, count: line.split(d).length }))
        .sort((a, b) => b.count - a.count)[0].d;
}

async function onFile(event: Event): Promise<void> {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0] ?? null;

    headers.value = [];
    sampleRow.value = [];
    readError.value = null;
    form.file = file;
    form.mapping = {};
    fileName.value = file?.name ?? null;

    if (!file) return;

    try {
        // Only the first slice is read: enough for the header and one example row, so a 5 MB file
        // doesn't have to be held in memory to be mapped.
        const text = await file.slice(0, 64_000).text();
        const lines = text
            .replace(/^﻿/, '')
            .split(/\r?\n/)
            .filter((line) => line.trim() !== '');

        if (lines.length === 0) {
            readError.value = t(
                'That file looks empty — it needs a header row and at least one lead.',
            );

            return;
        }

        const delimiter = sniff(lines[0]);

        headers.value = splitLine(lines[0], delimiter).map((h, i) =>
            h === '' ? t('Column :number', { number: i + 1 }) : h,
        );
        sampleRow.value = lines[1] ? splitLine(lines[1], delimiter) : [];

        autoMap();
    } catch {
        readError.value = t('We couldn’t read that file as a CSV.');
    }
}

/**
 * Pre-fill the mapping where a heading obviously matches a field, so a file exported from Alima
 * imports back without anyone touching the form.
 */
function autoMap(): void {
    const normalise = (value: string) =>
        value.toLowerCase().replace(/[^a-z0-9]/g, '');

    const byNormalised = new Map(
        headers.value.map((header) => [normalise(header), header]),
    );

    for (const field of props.fields) {
        const match =
            byNormalised.get(normalise(field)) ??
            byNormalised.get(normalise(fieldLabels[field] ?? ''));

        if (match) form.mapping[field] = match;
    }
}

function submit(): void {
    form.post('/leads/import', { forceFormData: true });
}

const hasFile = computed(() => headers.value.length > 0);
const canSubmit = computed(
    () => hasFile.value && (form.mapping.company_name ?? '') !== '',
);

function example(header: string): string {
    const index = headers.value.indexOf(header);

    return index >= 0 ? (sampleRow.value[index] ?? '') : '';
}
</script>

<template>
    <Head :title="t('Import leads')" />

    <div class="flex max-w-3xl flex-col gap-8 py-2">
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Import leads') }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                >
                    {{ t('Bring your own list')
                    }}<span class="text-orange">.</span>
                </h1>
                <p class="text-base text-muted-foreground">
                    {{
                        t(
                            'Upload a CSV and tell us which column is which. Companies you already have get filled in rather than duplicated.',
                        )
                    }}
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/leads">
                    <!-- Mirrored under RTL: the arrow means "back", which follows the
                         way the language reads. -->
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('All leads') }}
                </Link>
            </Button>
        </header>

        <form class="space-y-6" @submit.prevent="submit">
            <!-- The file -->
            <section
                class="space-y-3 rounded-2xl border border-border bg-card p-6"
            >
                <h2
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Your file') }}
                </h2>

                <div class="grid gap-2">
                    <Label for="csv">{{ t('CSV file') }}</Label>
                    <input
                        id="csv"
                        type="file"
                        accept=".csv,text/csv,text/plain"
                        class="h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm file:me-3 file:rounded-full file:border-0 file:bg-accent file:px-3 file:py-1 file:text-sm file:font-semibold file:text-accent-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        data-test="csv-file"
                        @change="onFile"
                    />
                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Up to :rows leads and :size MB per file. A header row is required.',
                                {
                                    rows: number(props.maxRows),
                                    size: number(
                                        Math.round(props.maxUploadKb / 1024),
                                    ),
                                },
                            )
                        }}
                    </p>
                    <InputError :message="readError ?? form.errors.file" />
                </div>
            </section>

            <!-- The mapping -->
            <section
                v-if="hasFile"
                class="space-y-4 rounded-2xl border border-border bg-card p-6"
                data-test="mapping"
            >
                <div class="space-y-1">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Match your columns') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'We’ve matched what we recognised. Leave anything you don’t want imported blank.',
                            )
                        }}
                    </p>
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div
                        v-for="field in props.fields"
                        :key="field"
                        class="grid gap-1.5"
                    >
                        <Label :for="`map-${field}`">
                            {{ t(fieldLabels[field] ?? field) }}
                            <!-- Signal Orange is decorative only (brand-tokens §2.1), and
                                 "required" is functional — so it is said in words. -->
                            <span
                                v-if="field === 'company_name'"
                                class="text-xs font-normal text-muted-foreground"
                                >{{ t('(required)') }}</span
                            >
                        </Label>
                        <select
                            :id="`map-${field}`"
                            v-model="form.mapping[field]"
                            class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :data-test="`map-${field}`"
                        >
                            <option value="">{{ t('Don’t import') }}</option>
                            <option
                                v-for="header in headers"
                                :key="header"
                                :value="header"
                            >
                                {{ header }}
                            </option>
                        </select>
                        <p
                            v-if="
                                form.mapping[field] &&
                                example(form.mapping[field])
                            "
                            class="truncate font-mono text-xs text-muted-foreground"
                            dir="auto"
                        >
                            {{ t('e.g.') }} {{ example(form.mapping[field]) }}
                        </p>
                    </div>
                </div>

                <InputError :message="form.errors.mapping" />
                <InputError :message="form.errors['mapping.company_name']" />
            </section>

            <div v-if="hasFile" class="flex items-center justify-end gap-3">
                <p
                    v-if="!canSubmit"
                    class="text-xs text-muted-foreground"
                    id="import-hint"
                >
                    {{
                        t('Pick the column that holds the company name first.')
                    }}
                </p>
                <Button
                    type="submit"
                    variant="brand"
                    :disabled="!canSubmit || form.processing"
                    :aria-describedby="canSubmit ? undefined : 'import-hint'"
                    data-test="start-import"
                >
                    <Upload class="size-4" />
                    {{ t('Import these leads') }}
                </Button>
            </div>
        </form>

        <p
            v-if="!hasFile"
            class="flex items-start gap-3 rounded-2xl border border-dashed border-border bg-card/50 p-5 text-sm text-muted-foreground"
        >
            <FileUp class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            {{
                t(
                    'Exporting from Alima first gives you a file in exactly the right shape — edit it in a spreadsheet and bring it back.',
                )
            }}
        </p>
    </div>
</template>
