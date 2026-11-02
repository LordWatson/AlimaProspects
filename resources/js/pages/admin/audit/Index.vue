<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ScrollText } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminPager from '@/components/admin/AdminPager.vue';
import AdminWindowFilter from '@/components/admin/AdminWindowFilter.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
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
    AdminAuditEntry,
    AdminPagination,
    AdminWindowFilters,
} from '@/types';

/*
 * The cross-tenant audit viewer (Phase 19c).
 *
 * **Append-only, and the screen is the shape of that.** There is no edit, no delete and no bulk
 * action — not because they are hidden, but because no route exists for them. An audit trail an
 * operator can change is not an audit trail.
 *
 * The **category** filter is the reason this screen exists rather than the registry's `audit-logs`
 * index. `audit_logs` holds every tenant's ordinary activity — notes added, stages moved, tags
 * changed — and the entries that matter when reviewing operator behaviour are four rows in ten
 * thousand. "Impersonation" and "Operator reads" are first-class choices here; in a free-text
 * action filter you would only find them if you already knew what to type.
 *
 * Properties are rendered as stored: ids, counts and masked previews, because the Actions that
 * wrote them were built so that an entry about personal data is never a copy of it. Nothing on
 * this page resolves what was deliberately left out.
 */
const props = defineProps<{
    entries: AdminAuditEntry[];
    pagination: AdminPagination;
    filters: AdminWindowFilters & {
        action: string | null;
        category: string | null;
        user_id: number | null;
        team_id: number | null;
        subject_type: string | null;
        subject_id: number | null;
    };
    options: {
        actions: string[];
        categories: { value: string; label: string }[];
        teams: { id: number; name: string }[];
    };
}>();

const { utcDateTime } = useFormat();

const category = ref(props.filters.category ?? '');
const action = ref(props.filters.action ?? '');
const teamId = ref(props.filters.team_id?.toString() ?? '');
const subjectType = ref(props.filters.subject_type ?? '');

/** Carried through a window change so changing the dates does not drop the filters. */
const extra = computed(() => ({
    category: category.value,
    action: action.value,
    team_id: teamId.value,
    subject_type: subjectType.value,
}));

function apply(): void {
    router.get(
        window.location.pathname,
        {
            range: props.filters.range,
            ...(props.filters.from && props.filters.to
                ? { from: props.filters.from, to: props.filters.to }
                : {}),
            ...Object.fromEntries(
                Object.entries(extra.value).filter(
                    ([, value]) => value !== '' && value !== null,
                ),
            ),
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}

function categoryVariant(value: string): 'brand' | 'warning' | 'outline' {
    if (value === 'impersonation') {
        return 'warning';
    }

    return value === 'admin_read' || value === 'admin_write'
        ? 'brand'
        : 'outline';
}
</script>

<template>
    <Head title="Audit trail" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · audit"
            title="Who did what,|and to whose data."
            lede="Every audited act by an operator, newest first — including the reads. This screen is itself audited."
        />

        <AdminWindowFilter
            :filters="filters"
            :ranges="[
                { value: 'last_7_days', label: 'Last 7 days' },
                { value: 'last_30_days', label: 'Last 30 days' },
                { value: 'last_90_days', label: 'Last 90 days' },
                { value: 'this_month', label: 'This month' },
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
                    for="audit-category"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Category
                </Label>
                <NativeSelect
                    id="audit-category"
                    v-model="category"
                    class="w-52"
                >
                    <option value="">Everything</option>
                    <option
                        v-for="option in options.categories"
                        :key="option.value"
                        :value="option.value"
                    >
                        {{ option.label }}
                    </option>
                </NativeSelect>
            </div>

            <div class="flex flex-col gap-1">
                <Label
                    for="audit-action"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Action
                </Label>
                <NativeSelect id="audit-action" v-model="action" class="w-60">
                    <option value="">Any action</option>
                    <option
                        v-for="option in options.actions"
                        :key="option"
                        :value="option"
                    >
                        {{ option }}
                    </option>
                </NativeSelect>
            </div>

            <div class="flex flex-col gap-1">
                <Label
                    for="audit-team"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Tenant
                </Label>
                <NativeSelect id="audit-team" v-model="teamId" class="w-52">
                    <option value="">Every tenant</option>
                    <option
                        v-for="team in options.teams"
                        :key="team.id"
                        :value="team.id.toString()"
                    >
                        {{ team.name }}
                    </option>
                </NativeSelect>
            </div>

            <div class="flex flex-col gap-1">
                <Label
                    for="audit-subject"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Subject
                </Label>
                <Input
                    id="audit-subject"
                    v-model="subjectType"
                    class="w-40"
                    placeholder="Lead"
                />
            </div>

            <Button type="submit" variant="outline" size="sm">Filter</Button>
        </form>

        <EmptyState
            v-if="entries.length === 0"
            :icon="ScrollText"
            title="No entries match"
            description="Widen the window or clear a filter. The audit trail holds every tenant's activity, so an empty result here usually means the filters, not the absence of events."
        />

        <template v-else>
            <div
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Audit entries"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>When (UTC)</TableHead>
                            <TableHead>Action</TableHead>
                            <TableHead>Actor</TableHead>
                            <TableHead>Tenant</TableHead>
                            <TableHead>Subject</TableHead>
                            <TableHead>Details</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="entry in entries" :key="entry.id">
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-top font-medium tabular-nums"
                            >
                                {{ entry.at ? utcDateTime(entry.at) : '—' }}
                            </th>
                            <TableCell class="align-top">
                                <code class="text-sm">{{ entry.action }}</code>
                                <Badge
                                    :variant="categoryVariant(entry.category)"
                                    class="ms-2"
                                >
                                    {{ entry.category_label }}
                                </Badge>
                            </TableCell>
                            <TableCell class="align-top">
                                <Link
                                    v-if="entry.actor_id"
                                    :href="`/admin/users/${entry.actor_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ entry.actor ?? `#${entry.actor_id}` }}
                                </Link>
                                <span v-else class="text-muted-foreground">
                                    system
                                </span>
                            </TableCell>
                            <TableCell class="align-top">
                                <Link
                                    v-if="entry.team_id"
                                    :href="`/admin/teams/${entry.team_id}`"
                                    class="rounded-sm underline underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ entry.team ?? `#${entry.team_id}` }}
                                </Link>
                                <span v-else class="text-muted-foreground">
                                    platform
                                </span>
                            </TableCell>
                            <TableCell
                                class="align-top text-sm text-muted-foreground"
                            >
                                <template v-if="entry.subject_type">
                                    {{ entry.subject_type }}
                                    <span v-if="entry.subject_id">
                                        #{{ entry.subject_id }}
                                    </span>
                                </template>
                                <template v-else>—</template>
                            </TableCell>
                            <TableCell
                                class="max-w-md align-top font-mono text-xs break-words text-muted-foreground"
                            >
                                {{
                                    entry.properties
                                        ? JSON.stringify(entry.properties)
                                        : '—'
                                }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <AdminPager :pagination="pagination" label="entries" />
        </template>

        <footer class="text-xs text-muted-foreground">
            <p>
                Details are ids, counts and masked previews — the Actions that
                wrote them never store a copy of the record. A log of who looked
                at personal data must not itself be a copy of it.
            </p>
        </footer>
    </div>
</template>
