<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { ShieldAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminPager from '@/components/admin/AdminPager.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { useFormat } from '@/composables/useFormat';
import type { AdminPagination } from '@/types';

/*
 * The public data-subject request queue (PL5).
 *
 * **It is a queue, not a console.** Nothing here suppresses or purges: both happen the moment the
 * person confirms their own request, because the parts that follow mechanically from somebody's
 * instruction must not wait for an operator to be at a desk — and an operator who could choose not
 * to suppress would be a compliance hole with a permission on it. What is left is the half that
 * needs a human: answering an access request, correcting what was wrong, refusing one with a
 * reason.
 *
 * **Unverified requests never appear.** A list of addresses anybody can type into a public form is
 * the attack the verification step prevents, and showing it to an operator would move that attack
 * one screen along rather than closing it.
 *
 * The deadline leads the sort and the colour, because that is the question an operator opens this
 * screen with: what is about to be late. `error`/`warning` semantic tokens only — Signal Orange is
 * decorative and never a state (brand-tokens.md 2.1).
 */
type SubjectRequestRow = {
    id: number;
    type: string;
    type_label: string;
    status: string;
    status_label: string;
    email: string;
    name: string | null;
    company: string | null;
    details: string | null;
    verified_at: string | null;
    due_at: string | null;
    days_remaining: number | null;
    overdue: boolean;
    purged_at: string | null;
    purge_summary: Record<string, number> | null;
    handled_by: string | null;
    resolution_note: string | null;
    completed_at: string | null;
};

const props = defineProps<{
    requests: SubjectRequestRow[];
    pagination: AdminPagination;
    filters: { status: string | null };
    statuses: { value: string; label: string }[];
}>();

const { number, utcDateTime } = useFormat();

const status = ref(props.filters.status ?? '');

/** Which row has its resolve form open. One at a time: closing a request is not a bulk action. */
const resolving = ref<number | null>(null);

const form = useForm<{ status: string; note: string }>({
    status: 'completed',
    note: '',
});

const overdue = computed(() => props.requests.filter((row) => row.overdue));

function filter(): void {
    router.get(
        '/admin/subject-requests',
        { status: status.value === '' ? undefined : status.value },
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

function open(row: SubjectRequestRow): void {
    resolving.value = resolving.value === row.id ? null : row.id;
    form.reset();
    form.clearErrors();
}

function resolve(row: SubjectRequestRow): void {
    form.patch(`/admin/subject-requests/${row.id}`, {
        preserveScroll: true,
        onSuccess: () => (resolving.value = null),
    });
}

/**
 * How the deadline reads. Overdue is an error; inside a week is a warning; everything else is
 * neutral, because colouring a request that is three weeks out teaches an operator to ignore the
 * colour.
 */
function deadline(row: SubjectRequestRow): {
    label: string;
    variant: 'error' | 'warning' | 'outline';
} {
    if (row.status !== 'open') {
        return { label: 'closed', variant: 'outline' as const };
    }

    if (row.overdue) {
        // `error`, not `destructive`: the badge component's status variants are
        // success/warning/error/info, and a name it does not know silently falls back to the
        // neutral Ink pill — which would leave the most urgent state on the screen uncoloured.
        return { label: 'overdue', variant: 'error' as const };
    }

    const days = row.days_remaining;

    if (days !== null && days <= 7) {
        return {
            label: `${number(days)} days left`,
            variant: 'warning' as const,
        };
    }

    return {
        label: days === null ? 'no deadline' : `${number(days)} days left`,
        variant: 'outline' as const,
    };
}
</script>

<template>
    <Head title="Privacy requests" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · privacy"
            title="People who wrote|to us directly."
            lede="Requests from people with no account, made to Alima rather than to a customer. Suppression and erasure already happened on verification — what is left here needs a person."
        />

        <section class="flex flex-col gap-3" aria-label="Privacy requests">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div class="flex flex-col gap-1.5">
                    <Label for="request-status">Status</Label>
                    <NativeSelect
                        id="request-status"
                        v-model="status"
                        class="w-56"
                        @change="filter"
                    >
                        <option value="">All verified requests</option>
                        <option
                            v-for="option in statuses"
                            :key="option.value"
                            :value="option.value"
                        >
                            {{ option.label }}
                        </option>
                    </NativeSelect>
                </div>

                <p v-if="overdue.length > 0" class="text-sm text-destructive">
                    {{ overdue.length }} past the one-month deadline.
                </p>
            </div>

            <EmptyState
                v-if="requests.length === 0"
                :icon="ShieldAlert"
                title="Nothing waiting"
                description="No verified request matches this filter. Unverified submissions are deliberately not listed."
            />

            <Table v-else aria-label="Verified privacy requests">
                <TableHeader>
                    <TableRow>
                        <TableHead>Request</TableHead>
                        <TableHead>From</TableHead>
                        <TableHead>Deadline</TableHead>
                        <TableHead>Erasure</TableHead>
                        <TableHead class="text-end">Outcome</TableHead>
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <template v-for="row in requests" :key="row.id">
                        <TableRow>
                            <TableCell>
                                <span class="font-medium text-foreground">{{
                                    row.type_label
                                }}</span>
                                <span
                                    class="block text-xs text-muted-foreground"
                                >
                                    #{{ row.id }} · verified
                                    {{ utcDateTime(row.verified_at ?? '') }}
                                </span>
                            </TableCell>
                            <TableCell>
                                <span class="text-foreground" dir="ltr">{{
                                    row.email
                                }}</span>
                                <span
                                    v-if="row.name || row.company"
                                    class="block text-xs text-muted-foreground"
                                    dir="auto"
                                >
                                    {{
                                        [row.name, row.company]
                                            .filter(Boolean)
                                            .join(' · ')
                                    }}
                                </span>
                            </TableCell>
                            <TableCell>
                                <Badge :variant="deadline(row).variant">{{
                                    deadline(row).label
                                }}</Badge>
                            </TableCell>
                            <TableCell>
                                <!-- Counts only. A record of what we erased for privacy reasons
                                     must not be a copy of it. -->
                                <span
                                    v-if="row.purged_at"
                                    class="text-xs text-muted-foreground"
                                >
                                    swept
                                    {{ row.purge_summary?.teams_touched ?? 0 }}
                                    team(s)
                                    <span
                                        v-if="row.purge_summary?.teams_failed"
                                        class="text-destructive"
                                        >·
                                        {{ row.purge_summary.teams_failed }}
                                        failed</span
                                    >
                                </span>
                                <span
                                    v-else
                                    class="text-xs text-muted-foreground"
                                    >—</span
                                >
                            </TableCell>
                            <TableCell class="text-end">
                                <template v-if="row.status === 'open'">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        :data-test="`resolve-${row.id}`"
                                        @click="open(row)"
                                    >
                                        Close
                                    </Button>
                                </template>
                                <template v-else>
                                    <span class="text-xs text-muted-foreground">
                                        {{ row.status_label
                                        }}<template v-if="row.handled_by">
                                            · {{ row.handled_by }}</template
                                        >
                                    </span>
                                </template>
                            </TableCell>
                        </TableRow>

                        <TableRow v-if="resolving === row.id">
                            <TableCell colspan="5">
                                <form
                                    class="flex flex-col gap-3 py-2"
                                    @submit.prevent="resolve(row)"
                                >
                                    <p
                                        v-if="row.details"
                                        class="text-sm text-muted-foreground"
                                        dir="auto"
                                    >
                                        “{{ row.details }}”
                                    </p>

                                    <div class="flex flex-col gap-1.5">
                                        <Label :for="`outcome-${row.id}`"
                                            >Outcome</Label
                                        >
                                        <NativeSelect
                                            :id="`outcome-${row.id}`"
                                            v-model="form.status"
                                            class="w-56"
                                        >
                                            <option value="completed">
                                                Completed
                                            </option>
                                            <option value="rejected">
                                                Rejected
                                            </option>
                                        </NativeSelect>
                                    </div>

                                    <div class="flex flex-col gap-1.5">
                                        <Label :for="`note-${row.id}`">
                                            Note{{
                                                form.status === 'rejected'
                                                    ? ' (required for a refusal)'
                                                    : ' (optional)'
                                            }}
                                        </Label>
                                        <Textarea
                                            :id="`note-${row.id}`"
                                            v-model="form.note"
                                            rows="2"
                                            dir="auto"
                                        />
                                        <p
                                            v-if="form.errors.note"
                                            class="text-sm text-destructive"
                                        >
                                            {{ form.errors.note }}
                                        </p>
                                    </div>

                                    <div class="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            :disabled="form.processing"
                                        >
                                            Record it
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="resolving = null"
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            </TableCell>
                        </TableRow>
                    </template>
                </TableBody>
            </Table>

            <AdminPager :pagination="pagination" label="requests" />
        </section>
    </div>
</template>
