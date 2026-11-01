<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Radar } from '@lucide/vue';
import { computed, ref } from 'vue';
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
        <header class="max-w-2xl">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Signal runs
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Cost, failures and durations per run across every tenant.
                <strong>Read-only</strong> — a run spends a tenant's allowance,
                so there is no route here to start one.
            </p>
        </header>

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
