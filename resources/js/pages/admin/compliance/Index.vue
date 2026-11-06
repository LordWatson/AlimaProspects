<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminPager from '@/components/admin/AdminPager.vue';
import AdminWindowFilter from '@/components/admin/AdminWindowFilter.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
    AdminAssessment,
    AdminComplianceEvent,
    AdminPagination,
    AdminRetentionPolicy,
    AdminWindowFilters,
} from '@/types';

/*
 * Cross-tenant compliance activity (Phase 19c) — read-only, and one of the three views earlier
 * phases deferred to this one.
 *
 * **Nothing on this page writes anything, and no route exists to.** An erasure, an export and a
 * retention policy are the *tenant's* decisions as data controller — which is why those routes are
 * on the impersonation blocklist too — so an operator honouring somebody else's subject request
 * from here would be signing another company's paperwork.
 *
 * The assessments table leads, because it answers a specific support ticket: without a
 * legitimate-interest assessment in force for its market a tenant cannot source at all, so
 * "discovery stopped working" is very often a lapsed row here.
 *
 * Brand (§2a): `warning`/`error` semantic tokens for a lapsed assessment, never Signal Orange.
 */
const props = defineProps<{
    events: AdminComplianceEvent[];
    pagination: AdminPagination;
    assessments: AdminAssessment[];
    policies: AdminRetentionPolicy[];
    filters: AdminWindowFilters & {
        action: string | null;
        team_id: number | null;
    };
    actions: string[];
}>();

const { number, utcDateTime } = useFormat();

const action = ref(props.filters.action ?? '');

const extra = computed(() => ({ action: action.value }));

const lapsed = computed(() =>
    props.assessments.filter((record) => !record.in_force),
);

function apply(): void {
    router.get(
        window.location.pathname,
        {
            range: props.filters.range,
            ...(props.filters.from && props.filters.to
                ? { from: props.filters.from, to: props.filters.to }
                : {}),
            ...(action.value === '' ? {} : { action: action.value }),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function assessmentState(record: AdminAssessment): {
    label: string;
    variant: 'success' | 'warning' | 'error' | 'outline';
} {
    if (record.withdrawn_at !== null) {
        return { label: 'withdrawn', variant: 'error' };
    }

    if (record.expired) {
        return { label: 'review lapsed', variant: 'error' };
    }

    if (!record.in_force) {
        return { label: record.status, variant: 'outline' };
    }

    if (record.review_due_in_days !== null && record.review_due_in_days <= 14) {
        return {
            label: `review in ${record.review_due_in_days}d`,
            variant: 'warning',
        };
    }

    return { label: 'in force', variant: 'success' };
}
</script>

<template>
    <Head title="Compliance activity" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · compliance"
            title="What the law|has us doing."
            lede="Retention sweeps, erasures and subject requests across every tenant. Figures, never the records themselves."
        />

        <section class="flex flex-col gap-3" aria-label="Assessments">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-lg font-semibold text-foreground">
                    Legitimate-interest assessments
                </h2>
                <p v-if="lapsed.length > 0" class="text-sm text-destructive">
                    {{ lapsed.length }} not in force — those tenants cannot
                    source.
                </p>
            </div>

            <EmptyState
                v-if="assessments.length === 0"
                :icon="ShieldCheck"
                title="No assessments recorded"
                description="A tenant without one in force for its market cannot run discovery at all — the gate refuses before a provider is called."
            />

            <div
                v-else
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Legitimate-interest assessments"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Market</TableHead>
                            <TableHead>Version</TableHead>
                            <TableHead>Completed</TableHead>
                            <TableHead>Review due</TableHead>
                            <TableHead>State</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="record in assessments"
                            :key="record.id"
                        >
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-middle font-medium"
                            >
                                <Link
                                    v-if="record.team_id"
                                    :href="`/admin/teams/${record.team_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ record.team ?? `#${record.team_id}` }}
                                </Link>
                                <span v-else>—</span>
                            </th>
                            <TableCell class="uppercase">
                                {{ record.market }}
                            </TableCell>
                            <TableCell class="tabular-nums">
                                v{{ record.version }}
                            </TableCell>
                            <TableCell class="tabular-nums">
                                {{
                                    record.completed_at
                                        ? utcDateTime(record.completed_at)
                                        : '—'
                                }}
                            </TableCell>
                            <TableCell class="tabular-nums">
                                {{
                                    record.review_due_at
                                        ? utcDateTime(record.review_due_at)
                                        : '—'
                                }}
                            </TableCell>
                            <TableCell>
                                <Badge
                                    :variant="assessmentState(record).variant"
                                >
                                    {{ assessmentState(record).label }}
                                </Badge>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <section class="flex flex-col gap-3" aria-label="Retention policies">
            <h2 class="text-lg font-semibold text-foreground">
                Retention policies
            </h2>

            <div
                v-if="policies.length > 0"
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Retention policies"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Sweeping</TableHead>
                            <TableHead class="text-end">
                                Uncontacted (days)
                            </TableHead>
                            <TableHead class="text-end">
                                Contacted (days)
                            </TableHead>
                            <TableHead>Last swept</TableHead>
                            <TableHead class="text-end">
                                Last deleted / anonymised
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="policy in policies" :key="policy.id">
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-middle font-medium"
                            >
                                <Link
                                    v-if="policy.team_id"
                                    :href="`/admin/teams/${policy.team_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ policy.team ?? `#${policy.team_id}` }}
                                </Link>
                                <span v-else>—</span>
                            </th>
                            <TableCell>
                                <Badge
                                    :variant="
                                        policy.is_enabled
                                            ? 'success'
                                            : 'outline'
                                    "
                                >
                                    {{ policy.is_enabled ? 'on' : 'off' }}
                                </Badge>
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(policy.uncontacted_lead_days) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(policy.contacted_lead_days) }}
                            </TableCell>
                            <TableCell class="tabular-nums">
                                {{
                                    policy.last_swept_at
                                        ? utcDateTime(policy.last_swept_at)
                                        : 'never'
                                }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                <template v-if="policy.last_swept_at">
                                    {{ number(policy.last_deleted_count) }} /
                                    {{ number(policy.last_anonymised_count) }}
                                </template>
                                <template v-else>—</template>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <p v-else class="text-sm text-muted-foreground">
                No tenant has set a retention policy.
            </p>
        </section>

        <section class="flex flex-col gap-4" aria-label="Compliance events">
            <h2 class="text-lg font-semibold text-foreground">Events</h2>

            <AdminWindowFilter
                :filters="filters"
                :ranges="[
                    { value: 'last_30_days', label: 'Last 30 days' },
                    { value: 'last_90_days', label: 'Last 90 days' },
                    { value: 'this_year', label: 'This year' },
                    { value: 'all_time', label: 'All time' },
                ]"
                :extra="extra"
            />

            <form
                class="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card px-4 py-3"
                @submit.prevent="apply"
            >
                <div class="flex flex-col gap-1">
                    <Label
                        for="compliance-action"
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                    >
                        Event
                    </Label>
                    <NativeSelect
                        id="compliance-action"
                        v-model="action"
                        class="w-72"
                    >
                        <option value="">Every compliance event</option>
                        <option
                            v-for="option in actions"
                            :key="option"
                            :value="option"
                        >
                            {{ option }}
                        </option>
                    </NativeSelect>
                </div>

                <Button type="submit" variant="outline" size="sm">
                    Filter
                </Button>
            </form>

            <EmptyState
                v-if="events.length === 0"
                :icon="ShieldCheck"
                title="No compliance events in this window"
                description="Sweeps, exports and erasures all write one audit entry each — with counts and ids, and deliberately no copy of what was swept or exported."
            />

            <template v-else>
                <div
                    class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    tabindex="0"
                    role="region"
                    aria-label="Compliance events"
                >
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>When (UTC)</TableHead>
                                <TableHead>Event</TableHead>
                                <TableHead>Tenant</TableHead>
                                <TableHead>Who</TableHead>
                                <TableHead>Details</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow v-for="event in events" :key="event.id">
                                <th
                                    scope="row"
                                    class="px-4 py-3 text-start align-top font-medium tabular-nums"
                                >
                                    {{ event.at ? utcDateTime(event.at) : '—' }}
                                </th>
                                <TableCell class="align-top">
                                    <code class="text-sm">
                                        {{ event.action }}
                                    </code>
                                </TableCell>
                                <TableCell class="align-top">
                                    <Link
                                        v-if="event.team_id"
                                        :href="`/admin/teams/${event.team_id}`"
                                        class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                    >
                                        {{ event.team ?? `#${event.team_id}` }}
                                    </Link>
                                    <span v-else class="text-muted-foreground">
                                        platform
                                    </span>
                                </TableCell>
                                <TableCell class="align-top">
                                    <span v-if="event.actor">
                                        {{ event.actor }}
                                    </span>
                                    <!--
                                      The sweep and the scheduled notices run with nobody behind
                                      them. "System" rather than blank, because "who did this"
                                      having no answer is itself the answer.
                                    -->
                                    <span v-else class="text-muted-foreground">
                                        system
                                    </span>
                                </TableCell>
                                <TableCell
                                    class="max-w-md align-top font-mono text-xs break-words text-muted-foreground"
                                >
                                    {{
                                        event.properties
                                            ? JSON.stringify(event.properties)
                                            : '—'
                                    }}
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>

                <AdminPager :pagination="pagination" label="events" />
            </template>
        </section>
    </div>
</template>
