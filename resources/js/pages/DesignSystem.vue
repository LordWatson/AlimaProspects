<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { Inbox, Rocket, Sparkles } from '@lucide/vue';
import { toast } from 'vue-sonner';
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

    <div class="mx-auto flex w-full max-w-5xl flex-col gap-10 p-6">
        <header class="flex flex-col gap-2">
            <span
                class="font-mono text-xs tracking-[0.25em] text-brand uppercase"
            >
                Alima brand
            </span>
            <h1 class="text-3xl font-semibold tracking-tight">
                Marketing brighter together<span class="text-orange">.</span>
            </h1>
            <p class="max-w-prose text-muted-foreground">
                The base component library, built on brand tokens. Lead with
                Cloud Cream, one hero accent per layout. Signal Orange is
                decorative only — never a status colour.
            </p>
        </header>

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
    </div>
</template>
