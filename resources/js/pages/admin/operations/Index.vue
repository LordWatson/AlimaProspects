<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { useEventListener } from '@vueuse/core';
import { CircleCheck, Info, RotateCcw, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
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
    AdminFailedJobs,
    AdminPulseStatus,
    AdminQueueDepth,
    AdminScheduledTask,
} from '@/types';

/*
 * Failed jobs, queues and schedule health (Phase 19c) — diagnose and clear a stuck queue without a
 * shell.
 *
 * Three things this screen says that a queue dashboard does not:
 *
 *  - **Whose job it was.** The tenant id is dug out of the serialised payload, and it is the column
 *    an operator actually needs. An em dash where the payload does not say, never a guess.
 *  - **Why a figure is missing.** Queue depth is only readable on the database driver; on Redis the
 *    row says so and points at Horizon. A queue depth of zero is the most reassuring number here
 *    and must never be a default.
 *  - **Last *seen*, not last run.** Laravel records nothing about past scheduled runs, so the panel
 *    records them itself, in the cache. A flushed cache reports never-seen rather than the truth,
 *    and the column heading is honest about it.
 *
 * Dropping a job is irreversible — the payload is the only copy of that work — so it asks twice and
 * the confirmation says exactly that. Retrying is not confirmed: it is the recoverable one.
 */
const props = defineProps<{
    failed: AdminFailedJobs;
    queues: AdminQueueDepth;
    schedule: AdminScheduledTask[];
    pulse: AdminPulseStatus;
}>();

const { number, utcDateTime } = useFormat();

/** The uuid currently awaiting a second press on Drop. One at a time, deliberately. */
const confirming = ref<string | null>(null);

const retryingAll = ref(false);

/*
 * Escape disarms. A two-step confirmation with no way back leaves the destructive label sitting
 * under the operator's cursor indefinitely, so the next absent-minded click is the one that drops
 * the job — which is the opposite of what asking twice is for.
 */
useEventListener(document, 'keydown', (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        confirming.value = null;
        retryingAll.value = false;
    }
});

const staleTasks = computed(() =>
    props.schedule.filter(
        (task) => task.last_outcome === 'failed' || task.last_seen_at === null,
    ),
);

function retry(uuid: string): void {
    router.post(`/admin/operations/failed-jobs/${uuid}/retry`, undefined, {
        preserveScroll: true,
    });
}

function drop(uuid: string): void {
    if (confirming.value !== uuid) {
        confirming.value = uuid;

        return;
    }

    router.delete(`/admin/operations/failed-jobs/${uuid}`, {
        preserveScroll: true,
        onFinish: () => {
            confirming.value = null;
        },
    });
}

function retryAll(): void {
    if (!retryingAll.value) {
        retryingAll.value = true;

        return;
    }

    router.post('/admin/operations/failed-jobs/retry-all', undefined, {
        preserveScroll: true,
        onFinish: () => {
            retryingAll.value = false;
        },
    });
}

/** Seconds as the coarse age an operator reads, because "4,812s" is not an age. */
function age(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }

    if (seconds < 60) {
        return `${seconds}s`;
    }

    if (seconds < 3600) {
        return `${Math.floor(seconds / 60)}m`;
    }

    return `${Math.floor(seconds / 3600)}h`;
}

function outcomeVariant(
    outcome: AdminScheduledTask['last_outcome'],
): 'success' | 'warning' | 'error' | 'outline' {
    switch (outcome) {
        case 'ok':
            return 'success';
        case 'skipped':
            return 'warning';
        case 'failed':
            return 'error';
        default:
            return 'outline';
    }
}
</script>

<template>
    <Head title="Operations · queues" />

    <div class="flex flex-col gap-8">
        <header class="max-w-2xl">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Queues and jobs
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Failed work, queue depth and when each scheduled command was
                last seen. Retries and drops are audited.
            </p>
        </header>

        <section class="flex flex-col gap-3" aria-label="Failed jobs">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h2 class="text-lg font-semibold text-foreground">
                    Failed jobs
                    <span class="text-muted-foreground tabular-nums">
                        ({{ number(failed.total) }})
                    </span>
                </h2>

                <div v-if="failed.total > 0" class="flex items-center gap-2">
                    <p
                        v-if="retryingAll"
                        role="status"
                        class="text-sm text-warning"
                    >
                        Retry all {{ number(failed.total) }}?
                    </p>
                    <Button variant="outline" size="sm" @click="retryAll">
                        <RotateCcw class="size-4" />
                        {{ retryingAll ? 'Yes, retry all' : 'Retry all' }}
                    </Button>
                </div>
            </div>

            <EmptyState
                v-if="failed.total === 0"
                :icon="CircleCheck"
                title="Nothing has failed"
                description="A job that fails lands here with the tenant it belonged to, so it can be retried or dropped without a shell."
            />

            <div
                v-else
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Failed jobs"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Job</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Queue</TableHead>
                            <TableHead>Failed</TableHead>
                            <TableHead>Exception</TableHead>
                            <TableHead class="text-end">Actions</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="job in failed.jobs" :key="job.uuid">
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-top font-medium"
                            >
                                {{ job.job }}
                            </th>
                            <TableCell class="align-top">
                                <Link
                                    v-if="job.team_id"
                                    :href="`/admin/teams/${job.team_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    #{{ job.team_id }}
                                </Link>
                                <span v-else class="text-muted-foreground">
                                    —
                                </span>
                            </TableCell>
                            <TableCell class="align-top">
                                {{ job.queue }}
                            </TableCell>
                            <TableCell class="align-top tabular-nums">
                                {{
                                    job.failed_at
                                        ? utcDateTime(job.failed_at)
                                        : '—'
                                }}
                            </TableCell>
                            <TableCell
                                class="max-w-md align-top text-sm text-muted-foreground"
                            >
                                {{ job.exception }}
                            </TableCell>
                            <TableCell class="text-end align-top">
                                <div
                                    class="flex flex-wrap items-center justify-end gap-2"
                                >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        @click="retry(job.uuid)"
                                    >
                                        <RotateCcw class="size-4" />
                                        Retry
                                    </Button>
                                    <Button
                                        :variant="
                                            confirming === job.uuid
                                                ? 'destructive'
                                                : 'ghost'
                                        "
                                        size="sm"
                                        @click="drop(job.uuid)"
                                    >
                                        <Trash2 class="size-4" />
                                        {{
                                            confirming === job.uuid
                                                ? 'Drop for good'
                                                : 'Drop'
                                        }}
                                    </Button>
                                </div>
                                <p
                                    v-if="confirming === job.uuid"
                                    role="status"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    The payload is the only copy of this work.
                                </p>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="flex flex-col gap-3" aria-label="Queue depth">
            <h2 class="text-lg font-semibold text-foreground">Queue depth</h2>

            <Card v-if="!queues.available" class="shadow-none">
                <CardContent class="flex items-start gap-3 px-5 py-4">
                    <Info class="mt-0.5 size-5 shrink-0 text-info" />
                    <p class="text-sm text-muted-foreground">
                        {{ queues.note }}
                    </p>
                </CardContent>
            </Card>

            <EmptyState
                v-else-if="queues.queues.length === 0"
                :icon="CircleCheck"
                title="Every queue is empty"
                :description="`Read from the ${queues.driver} driver. Nothing is waiting for a worker.`"
            />

            <div
                v-else
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Queue depth"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Queue</TableHead>
                            <TableHead class="text-end">Pending</TableHead>
                            <TableHead class="text-end">
                                Oldest waiting
                            </TableHead>
                            <TableHead class="text-end">
                                Most attempts seen
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="queue in queues.queues"
                            :key="queue.queue"
                        >
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-middle font-medium"
                            >
                                {{ queue.queue }}
                            </th>
                            <TableCell class="text-end tabular-nums">
                                {{ number(queue.pending) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ age(queue.oldest_age_seconds) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(queue.max_attempts_seen) }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <p class="text-xs text-muted-foreground">
                The oldest waiting job's age is the figure that separates a busy
                worker from a dead one.
            </p>
        </section>

        <section class="flex flex-col gap-3" aria-label="Scheduled commands">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-lg font-semibold text-foreground">
                    Scheduled commands
                </h2>
                <p v-if="staleTasks.length > 0" class="text-sm text-warning">
                    {{ staleTasks.length }} not seen running, or last failed.
                </p>
            </div>

            <div
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Scheduled commands"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Command</TableHead>
                            <TableHead>Schedule</TableHead>
                            <TableHead>Next due</TableHead>
                            <TableHead>Last seen</TableHead>
                            <TableHead>Outcome</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="task in schedule" :key="task.command">
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-top font-medium"
                            >
                                <code class="text-sm">{{ task.command }}</code>
                                <p
                                    v-if="task.last_error"
                                    class="mt-1 text-xs text-destructive"
                                >
                                    {{ task.last_error }}
                                </p>
                            </th>
                            <TableCell
                                class="align-top font-mono text-xs text-muted-foreground"
                            >
                                {{ task.expression }}
                            </TableCell>
                            <TableCell class="align-top tabular-nums">
                                {{
                                    task.next_due
                                        ? utcDateTime(task.next_due)
                                        : '—'
                                }}
                            </TableCell>
                            <TableCell class="align-top tabular-nums">
                                {{
                                    task.last_seen_at
                                        ? utcDateTime(task.last_seen_at)
                                        : '—'
                                }}
                            </TableCell>
                            <TableCell class="align-top">
                                <Badge
                                    :variant="outcomeVariant(task.last_outcome)"
                                >
                                    {{ task.last_outcome ?? 'not seen' }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <p class="text-xs text-muted-foreground">
                Last <em>seen</em>, not last run: Laravel stores nothing about
                past scheduled runs, so the panel records them in the cache. A
                cleared cache reads as "not seen" rather than as a missed run.
                <span v-if="pulse.enabled">
                    Pulse is live at <code>/{{ pulse.path }}</code> for
                    per-second detail.
                </span>
                <span v-else>
                    Pulse is not switched on in this environment.
                </span>
            </p>
        </section>
    </div>
</template>
