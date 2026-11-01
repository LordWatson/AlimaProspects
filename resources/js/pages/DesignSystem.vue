<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Inbox, Rocket, Sparkles } from '@lucide/vue';
import { ref } from 'vue';
import { toast } from 'vue-sonner';
import {
    BarListChart,
    FunnelChart,
    GradeMixBar,
    ReportTableView,
    StatTile,
    TrendChart,
} from '@/components/charts';
import {
    FormActions,
    FormErrorSummary,
    FormField,
    FormSection,
} from '@/components/forms';
import {
    FilterGroup,
    FilterToggle,
    ListCell,
    ListHead,
    ListRow,
    ListRowLink,
    ListSearch,
    ListTable,
    ListToolbar,
} from '@/components/list';
import MeterBar from '@/components/MeterBar.vue';
import PageHero from '@/components/PageHero.vue';
import ScoreDial from '@/components/scoring/ScoreDial.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Design system', href: '/design' }],
    },
});

// Swatches reference brand token utilities (never raw hex in styles); the hex is
// shown only as a label. Full literal class strings so Tailwind generates them.
/*
 * Sample data for the chart showcase. Invented numbers on purpose — this page documents the marks,
 * not a team's results, and it is the one screen where made-up figures are honest.
 */
const demoTrend = [
    { label: '11 Aug', starts_on: '2026-08-11', sourced: 12, qualified: 3 },
    { label: '18 Aug', starts_on: '2026-08-18', sourced: 18, qualified: 5 },
    { label: '25 Aug', starts_on: '2026-08-25', sourced: 9, qualified: 4 },
    { label: '1 Sep', starts_on: '2026-09-01', sourced: 26, qualified: 9 },
    { label: '8 Sep', starts_on: '2026-09-08', sourced: 31, qualified: 14 },
    { label: '15 Sep', starts_on: '2026-09-15', sourced: 22, qualified: 11 },
    { label: '22 Sep', starts_on: '2026-09-22', sourced: 35, qualified: 18 },
    { label: '29 Sep', starts_on: '2026-09-29', sourced: 41, qualified: 19 },
];

/*
 * The list and form furniture is driven by state the page owns, so the catalogue is a working
 * example rather than a screenshot: the chips really remove, the columns really sort, the density
 * toggle really switches (and is remembered, because it is the member's own setting).
 */
const demoSort = ref('company');
const demoDirection = ref<'asc' | 'desc'>('asc');
const demoFitFilter = ref<string[]>(['A']);
const demoSearch = ref('');
const demoChips = ref([
    { id: 'grades:A', group: 'Fit', label: 'A' },
    { id: 'stage:2', group: 'Stage', label: 'In conversation' },
]);
const demoErrors = ref<Record<string, string>>({});

const demoRows = [
    { id: 1, company: 'Bright Bean Coffee', town: 'Manchester', score: 86 },
    { id: 2, company: 'Northwind Trading', town: 'Leeds', score: 64 },
    { id: 3, company: 'Harbour & Co', town: 'Dubai', score: 41 },
];

function demoToggleGrade(grade: string): void {
    demoFitFilter.value = demoFitFilter.value.includes(grade)
        ? demoFitFilter.value.filter((value) => value !== grade)
        : [...demoFitFilter.value, grade];
}

function demoSortBy(key: string): void {
    if (demoSort.value === key) {
        demoDirection.value = demoDirection.value === 'asc' ? 'desc' : 'asc';

        return;
    }

    demoSort.value = key;
    demoDirection.value = 'asc';
}

const demoFunnel = [
    {
        key: 'sourced',
        label: 'Prospects sourced',
        caption: 'From Companies House, the web and Google Places',
        value: 194,
        available: true,
    },
    {
        key: 'qualified',
        label: 'Strong fits',
        caption: 'Graded A or B against your active ideal customer',
        value: 83,
        available: true,
    },
    {
        key: 'contacted',
        label: 'Contacted',
        caption: 'Prospects somebody has approached at least once',
        value: 41,
        available: true,
    },
    {
        key: 'replied',
        label: 'Replies',
        caption: 'Prospects who came back to us, however they answered',
        value: 12,
        available: true,
    },
    // Kept in the sample so the unavailable treatment stays documented: a step with no source of
    // truth draws an em dash and a padlock, never a zero. Nothing on the dashboard is in this
    // state today — a future step that arrives before its data will be.
    {
        key: 'meetings',
        label: 'Meetings',
        caption: 'Arrives with Integrations',
        value: null,
        available: false,
    },
];

const demoGrades = [
    { key: 'A', label: 'A — strong fit', value: 34, hint: null },
    { key: 'B', label: 'B — good fit', value: 49, hint: null },
    { key: 'C', label: 'C — partial fit', value: 61, hint: null },
    { key: 'D', label: 'D — weak fit', value: 18, hint: null },
];

const demoSources = [
    { key: 'companies_house', label: 'Companies House', value: 96, hint: null },
    { key: 'google_places', label: 'Google Places', value: 58, hint: null },
    { key: 'web_search', label: 'Web search', value: 31, hint: null },
    { key: 'signal', label: 'Signal', value: 9, hint: null },
];

const demoKpi = {
    key: 'leads',
    label: 'Leads in your list',
    value: 194,
    caption: 'Every company Alima has found for you',
    format: 'count' as const,
    currency: null,
    delta: 27,
    delta_label: 'vs the 30 days before',
    spark: demoTrend.map((point) => point.sourced),
};

/*
 * A sample report table — the shape every report crosses the wire in, so this one entry documents
 * all eleven of them. Note the nulls: an em dash is "we did not ask anyone", which is not 0%.
 */
const demoTable = {
    key: 'outreach' as const,
    title: 'Outreach performance',
    caption: 'How the approaches made in this period went, by channel.',
    empty_message: 'Nobody has been approached in this period.',
    columns: [
        {
            key: 'channel',
            label: 'Channel',
            format: 'text' as const,
            translated: true,
            currency: null,
        },
        {
            key: 'attempts',
            label: 'Approaches',
            format: 'count' as const,
            translated: false,
            currency: null,
        },
        {
            key: 'reply_rate',
            label: 'Reply rate',
            format: 'percent' as const,
            translated: false,
            currency: null,
        },
        {
            key: 'cost',
            label: 'Cost',
            format: 'money' as const,
            translated: false,
            currency: 'GBP',
        },
        {
            key: 'last_active',
            label: 'Last approach',
            format: 'date' as const,
            translated: false,
            currency: null,
        },
    ],
    rows: [
        {
            key: 'email',
            cells: {
                channel: 'Email',
                attempts: 86,
                reply_rate: 0.1395,
                cost: 412,
                last_active: '2026-10-28T09:12:00+00:00',
            },
        },
        {
            key: 'linkedin',
            cells: {
                channel: 'LinkedIn',
                attempts: 24,
                reply_rate: 0.25,
                cost: 0,
                last_active: '2026-10-27T15:40:00+00:00',
            },
        },
        {
            key: 'call',
            cells: {
                channel: 'Call',
                attempts: 0,
                reply_rate: null,
                cost: 0,
                last_active: null,
            },
        },
    ],
};

const swatches = [
    { name: 'Deep Teal', hex: '#205557', bg: 'bg-teal', fg: 'text-cream' },
    { name: 'Midnight Ink', hex: '#181723', bg: 'bg-ink', fg: 'text-cream' },
    { name: 'Cloud Cream', hex: '#F5F2E8', bg: 'bg-cream', fg: 'text-ink' },
    {
        name: 'Signal Orange',
        hex: '#F03D2A',
        bg: 'bg-orange',
        fg: 'text-cream',
    },
    { name: 'Electric Blue', hex: '#4E91D4', bg: 'bg-blue', fg: 'text-ink' },
    { name: 'Soft Lilac', hex: '#C9A4D8', bg: 'bg-lilac', fg: 'text-ink' },
    { name: 'Acid Lime', hex: '#D2E842', bg: 'bg-lime', fg: 'text-ink' },
];

const grades = [
    {
        grade: 'A',
        company: 'Northwind Trading',
        market: 'UK',
        score: 92,
        variant: 'success' as const,
    },
    {
        grade: 'B',
        company: 'Gulf Logistics LLC',
        market: 'UAE',
        score: 78,
        variant: 'info' as const,
    },
    {
        grade: 'C',
        company: 'Harbour & Co.',
        market: 'UK',
        score: 61,
        variant: 'warning' as const,
    },
];
</script>

<template>
    <Head title="Design system" />

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-10 py-2">
        <PageHero
            index="09"
            eyebrow="Alima brand"
            title="Marketing brighter|together."
            description="The base component library, built on brand tokens. Lead with Cloud Cream, one hero accent per layout. Signal Orange is decorative only — never a status colour."
            :meta="null"
        />

        <!-- Colour -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Colour
            </h2>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 lg:grid-cols-7">
                <div
                    v-for="s in swatches"
                    :key="s.name"
                    :class="[
                        'flex aspect-square flex-col justify-end rounded-lg border border-border p-3',
                        s.bg,
                    ]"
                >
                    <span :class="['text-xs font-medium', s.fg]">{{
                        s.name
                    }}</span>
                    <span :class="['font-mono text-xs opacity-70', s.fg]">{{
                        s.hex
                    }}</span>
                </div>
            </div>
        </section>

        <!-- Typography -->
        <section class="flex flex-col gap-3">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Typography — Poppins
            </h2>
            <Card>
                <CardContent class="flex flex-col gap-3 pt-6">
                    <p class="text-5xl font-bold tracking-tight">Bold ideas.</p>
                    <p class="text-3xl font-semibold tracking-tight">
                        Real impact.
                    </p>
                    <p class="text-lg font-medium">
                        Lead paragraph — bright, bold, clear, together.
                    </p>
                    <p class="text-base text-muted-foreground">
                        Body copy in Poppins Regular. Plain words, short
                        sentences, zero jargon.
                    </p>
                    <p
                        class="font-mono text-xs tracking-[0.25em] text-brand uppercase"
                    >
                        DM Mono eyebrow
                    </p>
                </CardContent>
            </Card>
        </section>

        <!-- Buttons -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Buttons
            </h2>
            <div class="flex flex-wrap items-center gap-3">
                <Button>Let's grow</Button>
                <Button variant="brand">Start a project</Button>
                <Button variant="secondary">Secondary</Button>
                <Button variant="outline">Outline</Button>
                <Button variant="ghost">Ghost</Button>
                <Button variant="link">See the work</Button>
                <Button variant="destructive">Delete</Button>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <Button size="sm">Small</Button>
                <Button size="default">Default</Button>
                <Button size="lg">Large</Button>
            </div>
        </section>

        <!-- Badges -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Badges
            </h2>
            <div class="flex flex-wrap items-center gap-2">
                <Badge variant="success">Grade A</Badge>
                <Badge variant="info">In review</Badge>
                <Badge variant="warning">Needs data</Badge>
                <Badge variant="error">Suppressed</Badge>
                <Badge variant="brand">Teal</Badge>
                <Badge variant="secondary">Draft</Badge>
                <Badge variant="lilac">Technology</Badge>
                <Badge variant="lime">New</Badge>
            </div>
        </section>

        <!-- Form controls -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Form controls
            </h2>
            <Card>
                <CardContent class="grid gap-4 pt-6 sm:grid-cols-2">
                    <div class="flex flex-col gap-1.5">
                        <Label for="company">Company</Label>
                        <Input id="company" placeholder="Northwind Trading" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <Label for="website">Website</Label>
                        <Input
                            id="website"
                            type="url"
                            placeholder="https://example.com"
                        />
                    </div>
                </CardContent>
            </Card>
        </section>

        <!-- Table -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Table
            </h2>
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead>Grade</TableHead>
                        <TableHead>Company</TableHead>
                        <TableHead>Market</TableHead>
                        <TableHead class="text-end">Score</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="row in grades" :key="row.company">
                        <TableCell
                            ><Badge :variant="row.variant">{{
                                row.grade
                            }}</Badge></TableCell
                        >
                        <TableCell class="font-medium">{{
                            row.company
                        }}</TableCell>
                        <TableCell>{{ row.market }}</TableCell>
                        <TableCell class="text-end font-mono">{{
                            row.score
                        }}</TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </section>

        <!-- Card grid + feature -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Cards
            </h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <Card>
                    <CardHeader>
                        <div
                            class="flex size-10 items-center justify-center rounded-full bg-brand/10 text-brand"
                        >
                            <Sparkles class="size-5" />
                        </div>
                        <CardTitle>AI background check</CardTitle>
                        <CardDescription>
                            A versioned read of the business before we source a
                            single lead.
                        </CardDescription>
                    </CardHeader>
                </Card>
                <Card>
                    <CardHeader>
                        <div
                            class="flex size-10 items-center justify-center rounded-full bg-brand/10 text-brand"
                        >
                            <Rocket class="size-5" />
                        </div>
                        <CardTitle>Lead discovery</CardTitle>
                        <CardDescription>
                            Companies House, Google Places and web search,
                            scored A–D with reasons.
                        </CardDescription>
                    </CardHeader>
                </Card>
            </div>
        </section>

        <!-- Overlays & feedback -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Overlays & feedback
            </h2>
            <div class="flex flex-wrap items-center gap-3">
                <Dialog>
                    <DialogTrigger as-child>
                        <Button variant="outline">Open modal</Button>
                    </DialogTrigger>
                    <DialogContent>
                        <DialogHeader>
                            <DialogTitle>Start a discovery run</DialogTitle>
                            <DialogDescription>
                                We'll find and qualify prospects that match your
                                ICP. This uses lead credits.
                            </DialogDescription>
                        </DialogHeader>
                        <DialogFooter>
                            <DialogClose as-child>
                                <Button variant="ghost">Cancel</Button>
                            </DialogClose>
                            <DialogClose as-child>
                                <Button>Let's grow</Button>
                            </DialogClose>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>

                <Button
                    variant="secondary"
                    @click="toast.success('Saved. Nicely done.')"
                >
                    Success toast
                </Button>
                <Button
                    variant="secondary"
                    @click="
                        toast.warning('Add more data to improve this score.')
                    "
                >
                    Warning toast
                </Button>
            </div>
        </section>

        <!-- Empty state -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Empty state
            </h2>
            <EmptyState
                :icon="Inbox"
                title="No leads yet"
                description="Run your first discovery and watch qualified prospects land here."
            >
                <Button>Let's grow</Button>
                <Button variant="outline">See the work</Button>
            </EmptyState>
        </section>

        <!-- List furniture -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Lists
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                Every tenant list is this shell:
                <code>ListToolbar</code> over <code>ListTable</code>. The
                filters fold away and what they are doing is said above the
                table as removable chips — a list that hides its own filters is
                a list that lies about what it holds. Columns carry
                <code>aria-sort</code> and order server-side, the header stays
                as the rows scroll, a row is clickable across its whole width
                while still holding one link, and density is the member's
                choice, remembered across every list.
            </p>

            <ListToolbar
                summary="3 leads match"
                :chips="demoChips"
                @remove-filter="
                    (chip) =>
                        (demoChips = demoChips.filter(
                            (candidate) => candidate.id !== chip.id,
                        ))
                "
                @clear-filters="demoChips = []"
            >
                <template #search>
                    <ListSearch
                        id="demo-search"
                        :value="demoSearch"
                        label="Search"
                        placeholder="Company, number, website or contact"
                        @search="demoSearch = $event"
                    />
                </template>
                <template #filters>
                    <FilterGroup legend="Fit">
                        <FilterToggle
                            v-for="grade in ['A', 'B', 'C', 'D']"
                            :key="grade"
                            mono
                            :pressed="demoFitFilter.includes(grade)"
                            :label="grade"
                            :sr-label="`Grade ${grade}`"
                            @toggle="demoToggleGrade(grade)"
                        />
                    </FilterGroup>
                </template>
            </ListToolbar>

            <ListTable caption="Three invented companies, for the catalogue">
                <thead>
                    <tr>
                        <ListHead
                            label="Company"
                            sort="company"
                            :active="demoSort"
                            :direction="demoDirection"
                            @sort="demoSortBy"
                        />
                        <ListHead label="Town" hide="sm" />
                        <ListHead
                            label="Fit"
                            align="end"
                            sort="fit"
                            :active="demoSort"
                            :direction="demoDirection"
                            @sort="demoSortBy"
                        />
                    </tr>
                </thead>
                <tbody>
                    <ListRow v-for="row in demoRows" :key="row.id">
                        <ListCell>
                            <ListRowLink href="/design">{{
                                row.company
                            }}</ListRowLink>
                        </ListCell>
                        <ListCell hide="sm" muted>{{ row.town }}</ListCell>
                        <ListCell align="end">
                            <span class="inline-flex items-center gap-3">
                                <MeterBar
                                    :value="row.score"
                                    width="sm"
                                    :label="`${row.score} out of 100`"
                                />
                                <span
                                    class="w-6 text-end font-mono text-xs text-muted-foreground tabular-nums"
                                    aria-hidden="true"
                                    >{{ row.score }}</span
                                >
                            </span>
                        </ListCell>
                    </ListRow>
                </tbody>
            </ListTable>
        </section>

        <!-- Form furniture -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Forms
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                A long form is a few short ones:
                <code>FormSection</code> per part, <code>FormField</code> per
                field — which is where the <code>for</code>/<code>id</code>,
                <code>aria-describedby</code> and
                <code>aria-invalid</code> wiring comes from —
                <code>FormErrorSummary</code> at the top, and
                <code>FormActions</code> keeping the save within reach.
                "Required" is said in words: a colour is not a label, and Signal
                Orange is decorative only.
            </p>

            <FormErrorSummary
                :errors="demoErrors"
                :labels="{ demoName: 'Company name' }"
                :anchors="{ demoName: 'demo-name' }"
            />

            <FormSection
                step="01"
                title="The company"
                description="Only the name is needed; everything else is for when you happen to know it."
            >
                <FormField
                    id="demo-name"
                    label="Company name"
                    required
                    hint="Already have this company? Alima adds to that lead instead of making a second one."
                    :error="demoErrors.demoName"
                >
                    <template #default="{ id, describedBy, invalid }">
                        <Input
                            :id="id"
                            :aria-describedby="describedBy"
                            :aria-invalid="invalid"
                            placeholder="e.g. Bright Bean Coffee"
                        />
                    </template>
                </FormField>

                <FormField id="demo-website" label="Website" optional>
                    <template #default="{ id, describedBy }">
                        <Input
                            :id="id"
                            :aria-describedby="describedBy"
                            placeholder="brightbean.co.uk"
                            dir="ltr"
                        />
                    </template>
                </FormField>
            </FormSection>

            <div class="flex flex-wrap gap-3">
                <Button
                    variant="outline"
                    @click="
                        demoErrors = {
                            demoName: 'Please give the company a name.',
                        }
                    "
                >
                    Show a rejected form
                </Button>
                <Button variant="ghost" @click="demoErrors = {}">
                    Clear it
                </Button>
            </div>
        </section>

        <!-- Score dial -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Score dial
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                A fit score against its scale. Colour follows the
                <em>grade</em>, with the same semantic tokens
                <code>GradeBadge</code> uses, so a grade is one colour
                everywhere — and a weak fit is a neutral Ink 60, never
                <code>error</code>: a lead that does not match your profile is
                nobody's fault.
            </p>
            <div class="flex flex-wrap items-center gap-6">
                <ScoreDial :score="86" grade="A" grade-label="Strong fit" />
                <ScoreDial :score="64" grade="B" grade-label="Good fit" />
                <ScoreDial :score="41" grade="C" grade-label="Possible fit" />
                <ScoreDial :score="18" grade="D" grade-label="Weak fit" />
            </div>
        </section>

        <!-- Charts -->
        <section class="flex flex-col gap-4">
            <h2
                class="font-mono text-sm tracking-wide text-muted-foreground uppercase"
            >
                Charts
            </h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                Hand-built from the validated <code>--chart-*</code> tokens — no
                charting dependency. One y-axis, thin marks, a recessive grid, a
                legend whenever there are two series, and a text equivalent for
                every chart. The base Acid Lime and Soft Lilac are re-stepped
                for data marks (they are 1.3:1 and near-grey as fills on cream);
                Signal Orange never encodes a value. See
                <code>docs/brand/brand-tokens.md</code> §7.7.
            </p>

            <div
                class="grid gap-px overflow-hidden rounded-3xl border border-border bg-border sm:grid-cols-2"
            >
                <StatTile :kpi="demoKpi" />
                <StatTile
                    :kpi="{
                        ...demoKpi,
                        key: 'spend',
                        label: 'AI & data this month',
                        value: 4215,
                        format: 'money',
                        currency: 'GBP',
                        caption: 'What Alima spent finding and grading for you',
                        delta: null,
                        delta_label: null,
                        spark: [],
                    }"
                />
            </div>

            <FunnelChart :steps="demoFunnel" />

            <Card>
                <CardContent class="pt-6">
                    <TrendChart :points="demoTrend" granularity="week" />
                </CardContent>
            </Card>

            <div class="grid gap-6 lg:grid-cols-2">
                <Card>
                    <CardContent class="pt-6">
                        <GradeMixBar :slices="demoGrades" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent class="flex flex-col gap-6 pt-6">
                        <div>
                            <p
                                class="font-mono text-xs tracking-eyebrow text-brand uppercase"
                            >
                                Sources
                            </p>
                            <h3
                                class="mt-2 text-xl font-semibold tracking-tight"
                            >
                                Where leads came from
                            </h3>
                        </div>
                        <BarListChart
                            :slices="demoSources"
                            tone="bg-chart-1"
                            show-share
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardContent class="pt-6">
                    <ReportTableView :table="demoTable" />
                </CardContent>
            </Card>
        </section>
    </div>
</template>
