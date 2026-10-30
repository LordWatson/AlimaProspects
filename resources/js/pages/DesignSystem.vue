<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Inbox, Rocket, Sparkles } from '@lucide/vue';
import { toast } from 'vue-sonner';
import {
    BarListChart,
    FunnelChart,
    GradeMixBar,
    ReportTableView,
    StatTile,
    TrendChart,
} from '@/components/charts';
import PageHero from '@/components/PageHero.vue';
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
                    <span :class="['font-mono text-[10px] opacity-70', s.fg]">{{
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
