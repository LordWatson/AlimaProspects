<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Radar } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminPager from '@/components/admin/AdminPager.vue';
import AdminWindowFilter from '@/components/admin/AdminWindowFilter.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/composables/useFormat';
import type {
    AdminPagination,
    AdminSignalRun,
    AdminSignalRunSource,
    AdminWindowFilters,
} from '@/types';

/*
 * Cross-tenant `signal_runs` (Phase 19c) — the observability view Signals deferred at S6.
 *
 * **Read-only, and the absence of a "re-run" button is the decision.** A signal run spends real
 * money at Serper and on the AI; the tenant's own "run now" is throttled for that reason, and an
 * operator re-running somebody's watch would spend their allowance for them. A tenant re-runs their
 * own watch from their own screen.
 *
 * `duration_ms` comes from `started_at`/`finished_at` rather than from `created_at`, because a
 * queued run can wait minutes before it begins and the number an operator wants is how long the
 * *work* took. A null duration is itself the finding on a stuck run, which is why it renders as an
 * em dash and never as zero.
 *
 * Cost is in **micro-units** and labelled as such, not dressed up as money: micro-units are not
 * minor units, and converting here would mean asserting a currency the table does not record.
 */
const props = defineProps<{
    runs: AdminSignalRun[];
    pagination: AdminPagination;
    summary: {
        runs: number;
        failed: number;
        failure_rate: number | null;
        cost_micros: number;
        signals_created: number;
        /** Cost and volume by source over the window, each with its breaker state (S13). */
        sources: AdminSignalRunSource[];
        statuses: { value: string; label: string }[];
    };
    filters: AdminWindowFilters & {
        status: string | null;
        team_id: number | null;
    };
}>();

const { number, percent, utcDateTime } = useFormat();

const status = ref(props.filters.status ?? '');

const extra = computed(() => ({ status: status.value }));

function apply(): void {
    router.get(
        window.location.pathname,
        {
            range: props.filters.range,
            ...(props.filters.from && props.filters.to
                ? { from: props.filters.from, to: props.filters.to }
                : {}),
            ...(status.value === '' ? {} : { status: status.value }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function statusVariant(
    value: string,
): 'success' | 'warning' | 'error' | 'outline' {
    switch (value) {
        case 'completed':
            return 'success';
        case 'running':
        case 'queued':
            return 'warning';
        case 'failed':
            return 'error';
        default:
            return 'outline';
    }
}

/** Milliseconds as the coarse duration an operator reads. */
function duration(ms: number | null): string {
    if (ms === null) {
        return '—';
    }

    return ms < 1000 ? `${ms}ms` : `${(ms / 1000).toFixed(1)}s`;
}
</script>

<template>
    <Head title="Signal runs" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · signals"
            title="What the watches cost,|and what they found."
            lede="Cost, failures and durations per run across every tenant. No re-run button: a run spends the tenant's own allowance."
        />

        <AdminWindowFilter
            :filters="filters"
            :ranges="[
                { value: 'last_7_days', label: 'Last 7 days' },
                { value: 'last_30_days', label: 'Last 30 days' },
                { value: 'last_90_days', label: 'Last 90 days' },
                { value: 'all_time', label: 'All time' },
            ]"
            :extra="extra"
        />

        <section aria-label="Run totals">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Runs
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ number(summary.runs) }}
                        </dd>
                    </CardContent>
                </Card>
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Failed
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ number(summary.failed) }}
                            <!-- An em dash, not 0%, over no runs: unused is not healthy. -->
                            <span
                                class="text-sm font-normal text-muted-foreground"
                            >
                                {{
                                    summary.failure_rate === null
                                        ? '—'
                                        : percent(summary.failure_rate, 1)
                                }}
                            </span>
                        </dd>
                    </CardContent>
                </Card>
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                            title="Provider cost in micro-units (1,000,000 to the major unit)"
                        >
                            Cost · micros
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ number(summary.cost_micros) }}
                        </dd>
                    </CardContent>
                </Card>
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Signals found
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ number(summary.signals_created) }}
                        </dd>
                    </CardContent>
                </Card>
            </dl>

            <!-- Where the window's spend went, per source, with each source's breaker beside it
                 (S13, task 8). On this screen rather than only on /admin/providers because this is
                 the moment the fact matters: an operator looking at a column of failing runs should
                 not have to remember that a second screen would have told them the breaker is open.
                 /admin/providers remains the only place one can be reset. -->
            <!-- `tabindex`/`role`/`aria-label` and the focus ring, like the runs table below:
                 a horizontally scrolling region that cannot be reached or scrolled by keyboard is
                 WCAG 2.1.1, and this screen already got that right once (brand review). -->
            <div
                v-if="summary.sources.length > 0"
                class="mt-4 overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Cost and volume by signal source"
                data-test="run-sources"
            >
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        Cost and volume by signal source over this window
                    </caption>
                    <thead
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        <tr class="border-b border-border">
                            <th scope="col" class="px-4 py-2 text-start">
                                Source
                            </th>
                            <th scope="col" class="px-4 py-2 text-end">
                                Queries
                            </th>
                            <th scope="col" class="px-4 py-2 text-end">
                                Results
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-2 text-end"
                                title="Queries the provider refused as a pattern. These never ran, so they are not billed as queries."
                            >
                                Rejected
                            </th>
                            <th scope="col" class="px-4 py-2 text-end">
                                Cost · micros
                            </th>
                            <th scope="col" class="px-4 py-2 text-start">
                                Breaker
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="source in summary.sources"
                            :key="source.source"
                            class="border-b border-border last:border-0"
                            :data-test="`run-source-${source.source}`"
                        >
                            <th
                                scope="row"
                                class="px-4 py-2 text-start font-medium text-foreground"
                            >
                                {{ source.label }}
                            </th>
                            <td class="px-4 py-2 text-end tabular-nums">
                                {{ number(source.queries) }}
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">
                                {{ number(source.results) }}
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">
                                {{ number(source.rejected) }}
                            </td>
                            <td class="px-4 py-2 text-end tabular-nums">
                                {{ number(source.cost_micros) }}
                            </td>
                            <td class="px-4 py-2">
                                <!-- Three states, not two. Null means this build watches no
                                     breaker for the source, which is a fact about the code. -->
                                <Badge
                                    v-if="source.breaker_open === true"
                                    variant="error"
                                >
                                    Open ·
                                    {{ number(source.breaker_failures ?? 0) }}
                                </Badge>
                                <span
                                    v-else-if="source.breaker_open === false"
                                    class="text-muted-foreground"
                                >
                                    Closed
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground"
                                    title="This build has no circuit breaker for this source"
                                >
                                    Not watched
                                </span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <form
            class="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card px-4 py-3"
            @submit.prevent="apply"
        >
            <div class="flex flex-col gap-1">
                <Label
                    for="run-status"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Status
                </Label>
                <NativeSelect id="run-status" v-model="status" class="w-48">
                    <option value="">Any status</option>
                    <option
                        v-for="option in summary.statuses"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </div>

            <Button type="submit" variant="outline" size="sm">Filter</Button>
        </form>

        <EmptyState
            v-if="runs.length === 0"
            :icon="Radar"
            title="No runs in this window"
            description="Watches run hourly when they are due. A tenant with no watches, or none due, produces no runs at all."
        />

        <template v-else>
            <div
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Signal runs"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When (UTC)</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Watch</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="text-end">Queries</TableHead>
                            <TableHead class="text-end">Results</TableHead>
                            <TableHead class="text-end">Signals</TableHead>
                            <TableHead class="text-end"
                                >Cost · micros</TableHead
                            >
                            <TableHead class="text-end">Duration</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="run in runs" :key="run.id">
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-top font-medium tabular-nums"
                            >
                                {{ run.at ? utcDateTime(run.at) : '—' }}
                            </th>
                            <TableCell class="align-top">
                                <Link
                                    v-if="run.team_id"
                                    :href="`/admin/teams/${run.team_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ run.team ?? `#${run.team_id}` }}
                                </Link>
                                <span v-else>—</span>
                            </TableCell>
                            <TableCell class="align-top">
                                {{ run.watch ?? '—' }}
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge :variant="statusVariant(run.status)">
                                    {{ run.status }}
                                </Badge>
                                <p
                                    v-if="run.error"
                                    class="mt-1 max-w-xs text-xs text-destructive"
                                >
                                    {{ run.error }}
                                </p>
                            </TableCell>
                            <TableCell class="text-end align-top tabular-nums">
                                {{ number(run.queries) }}
                            </TableCell>
                            <TableCell class="text-end align-top tabular-nums">
                                {{ number(run.results) }}
                            </TableCell>
                            <TableCell class="text-end align-top tabular-nums">
                                {{ number(run.created) }}
                            </TableCell>
                            <TableCell class="text-end align-top tabular-nums">
                                {{ number(run.cost_micros) }}
                            </TableCell>
                            <TableCell class="text-end align-top tabular-nums">
                                {{ duration(run.duration_ms) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <AdminPager :pagination="pagination" label="runs" />
        </template>

        <footer class="text-xs text-muted-foreground">
            <p>
                A missing duration means the run never started or never finished
                — which is the finding on a stuck run, not a zero. Totals are
                counted live from <code>signal_runs</code> rather than from the
                nightly rollup, so today is included.
            </p>
        </footer>
    </div>
</template>
