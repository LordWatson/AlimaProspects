<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { MailWarning, ShieldBan } from '@lucide/vue';
import { useEventListener } from '@vueuse/core';
import { ref } from 'vue';
import AdminPageHeader from '@/components/admin/AdminPageHeader.vue';
import AdminPager from '@/components/admin/AdminPager.vue';
import AdminWindowFilter from '@/components/admin/AdminWindowFilter.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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
    AdminDeliverability,
    AdminPagination,
    AdminSuppression,
    AdminSuppressionSummary,
    AdminWindowFilters,
} from '@/types';

/*
 * Outreach oversight and the global do-not-contact list (Phase 19c).
 *
 * **Why the platform cares at all**: a shared sending domain means one tenant's behaviour is every
 * tenant's deliverability. A tenant working a bought list raises the complaint rate on a domain
 * every other customer sends from, and the first anybody usually knows is mail stopping.
 *
 * **The global list is the same table with a null tenant**, not a second one. `outreach_suppressions`
 * is *the* do-not-contact list, `OutreachGuard` is the only thing that answers whether a prospect may
 * be approached, and a platform list kept anywhere else would be a list the guard does not read. So
 * adding here refuses the next send in every tenant with no new mechanism.
 *
 * **There is no remove button, and that is the decision.** Removing a global suppression would
 * overrule a person's stated wish with a button. `removable` is rendered on every row — always
 * false — so the absence reads as a rule rather than as an oversight.
 *
 * Only masked previews are shown, because only a hash is stored. A register of people who want
 * nothing to do with us is not a contact book, and an operations screen is not a reason to make it
 * one.
 */
const props = defineProps<{
    deliverability: AdminDeliverability;
    suppressions: AdminSuppression[];
    pagination: AdminPagination;
    summary: AdminSuppressionSummary;
    form: {
        types: { value: string; label: string }[];
        reasons: { value: string; label: string }[];
    };
    filters: AdminWindowFilters;
}>();

const { number, percent, utcDate, utcDateTime } = useFormat();

const suppress = useForm({
    type: props.form.types[0]?.value ?? 'email',
    value: '',
    reason: props.form.reasons[0]?.value ?? 'opt_out',
    note: '',
});

/*
 * Asked twice, like every other irreversible act in the panel — and this is the most consequential
 * of them. A dropped job can be re-queued by hand and a breaker closes itself again; a global
 * suppression has **no** route to lift it anywhere in the product, and it reaches every tenant.
 * Both of those get a second press, so a single click here would be the odd one out.
 */
const confirming = ref(false);

useEventListener(document, 'keydown', (event: KeyboardEvent): void => {
    if (event.key === 'Escape') {
        confirming.value = false;
    }
});

function submit(): void {
    if (!confirming.value) {
        confirming.value = true;

        return;
    }

    suppress.post('/admin/outreach/suppressions', {
        preserveScroll: true,
        onSuccess: () => suppress.reset('value', 'note'),
        onFinish: () => {
            confirming.value = false;
        },
    });
}
</script>

<template>
    <Head title="Outreach oversight" />

    <div class="flex flex-col gap-8">
        <AdminPageHeader
            eyebrow="Platform · outreach"
            title="What we sent,|and what came back."
            lede="Send volume, bounces and suppressions across every tenant. Counts and domains only — never a message."
        />

        <AdminWindowFilter
            :filters="filters"
            :ranges="[
                { value: 'last_7_days', label: 'Last 7 days' },
                { value: 'last_30_days', label: 'Last 30 days' },
                { value: 'last_90_days', label: 'Last 90 days' },
                { value: 'all_time', label: 'All time' },
            ]"
        />

        <section class="flex flex-col gap-3" aria-label="Deliverability">
            <h2 class="text-lg font-semibold text-foreground">
                Deliverability by channel
            </h2>

            <div
                class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                tabindex="0"
                role="region"
                aria-label="Deliverability by channel"
            >
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Channel</TableHead>
                            <TableHead class="text-end">
                                Sent by Alima
                            </TableHead>
                            <TableHead class="text-end">
                                Approaches logged
                            </TableHead>
                            <TableHead class="text-end">Bounces</TableHead>
                            <TableHead class="text-end">Opt-outs</TableHead>
                            <TableHead class="text-end">Bounce rate</TableHead>
                            <TableHead class="text-end">
                                Opt-out rate
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="row in deliverability.channels"
                            :key="row.channel"
                        >
                            <th
                                scope="row"
                                class="px-4 py-3 text-start align-middle font-medium"
                            >
                                {{ row.label }}
                            </th>
                            <TableCell class="text-end tabular-nums">
                                {{ number(row.sent) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(row.attempts) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(row.bounced) }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{ number(row.opted_out) }}
                            </TableCell>
                            <!--
                              An em dash, not 0%: a channel nobody used has no bounce rate, and a
                              zero there would read as perfect deliverability.
                            -->
                            <TableCell class="text-end tabular-nums">
                                {{
                                    row.bounce_rate === null
                                        ? '—'
                                        : percent(row.bounce_rate, 1)
                                }}
                            </TableCell>
                            <TableCell class="text-end tabular-nums">
                                {{
                                    row.opt_out_rate === null
                                        ? '—'
                                        : percent(row.opt_out_rate, 1)
                                }}
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <p class="text-xs text-muted-foreground">
                From the nightly rollup, as at
                {{
                    deliverability.last_rolled_up
                        ? utcDate(deliverability.last_rolled_up)
                        : 'never — it has not run'
                }}
                (UTC). "Sent by Alima" counts messages we transmitted;
                "approaches logged" also includes the ones a member made
                themselves and recorded.
            </p>
        </section>

        <section class="grid gap-6 lg:grid-cols-[2fr_3fr]">
            <Card class="shadow-none">
                <CardContent class="flex flex-col gap-4 px-5 py-5">
                    <div>
                        <h2 class="text-lg font-semibold text-foreground">
                            Suppress for every tenant
                        </h2>
                        <p class="mt-1 text-sm text-muted-foreground">
                            An address, domain or number nobody on the platform
                            may contact. It takes effect on the next send in
                            every tenant, and
                            <strong>it cannot be lifted</strong> — there is no
                            route anywhere in the product to remove a global
                            entry.
                        </p>
                    </div>

                    <form class="flex flex-col gap-4" @submit.prevent="submit">
                        <div class="flex flex-col gap-1.5">
                            <Label for="suppress-type">What is it</Label>
                            <NativeSelect
                                id="suppress-type"
                                v-model="suppress.type"
                            >
                                <option
                                    v-for="option in form.types"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </NativeSelect>
                            <InputError :message="suppress.errors.type" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="suppress-value">Value</Label>
                            <Input
                                id="suppress-value"
                                v-model="suppress.value"
                                autocomplete="off"
                                placeholder="someone@example.com"
                            />
                            <p class="text-xs text-muted-foreground">
                                Stored as a hash and a mask — never in the
                                clear. A pasted URL works as a domain.
                            </p>
                            <InputError :message="suppress.errors.value" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="suppress-reason">Why</Label>
                            <NativeSelect
                                id="suppress-reason"
                                v-model="suppress.reason"
                            >
                                <option
                                    v-for="option in form.reasons"
                                    :key="option.value"
                                    :value="option.value"
                                >
                                    {{ option.label }}
                                </option>
                            </NativeSelect>
                            <p class="text-xs text-muted-foreground">
                                A bounce and an erasure are deliberately not
                                offered: a bounce is one tenant's technical
                                fact, and an erasure belongs to the tenant that
                                received the request.
                            </p>
                            <InputError :message="suppress.errors.reason" />
                        </div>

                        <div class="flex flex-col gap-1.5">
                            <Label for="suppress-note">Note</Label>
                            <Input
                                id="suppress-note"
                                v-model="suppress.note"
                                required
                                aria-describedby="suppress-note-help"
                                placeholder="Complained to us directly, ticket 4812"
                            />
                            <p
                                id="suppress-note-help"
                                class="text-xs text-muted-foreground"
                            >
                                Required. An entry with no note is one the next
                                operator cannot explain.
                            </p>
                            <InputError :message="suppress.errors.note" />
                        </div>

                        <div class="flex flex-col gap-2">
                            <Button
                                type="submit"
                                :variant="
                                    confirming ? 'destructive' : 'default'
                                "
                                :disabled="suppress.processing"
                                class="self-start"
                            >
                                <ShieldBan class="size-4" />
                                {{
                                    confirming
                                        ? 'Yes, suppress for every tenant'
                                        : 'Suppress everywhere'
                                }}
                            </Button>
                            <p
                                v-if="confirming"
                                role="status"
                                class="text-xs text-destructive"
                            >
                                This cannot be undone and there is no route to
                                lift it. Press Escape to cancel.
                            </p>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <div class="flex flex-col gap-4">
                <dl class="grid grid-cols-2 gap-3">
                    <Card class="shadow-none">
                        <CardContent class="px-4 py-4">
                            <dt
                                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                            >
                                Global entries
                            </dt>
                            <dd
                                class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                            >
                                {{ number(summary.global) }}
                            </dd>
                        </CardContent>
                    </Card>
                    <Card class="shadow-none">
                        <CardContent class="px-4 py-4">
                            <dt
                                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                                title="Each tenant's own housekeeping — bounces, companies they handle offline. Not a platform block list."
                            >
                                Tenants' own entries
                            </dt>
                            <dd
                                class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                            >
                                {{ number(summary.team) }}
                            </dd>
                        </CardContent>
                    </Card>
                </dl>

                <EmptyState
                    v-if="suppressions.length === 0"
                    :icon="MailWarning"
                    title="The global list is empty"
                    description="Nothing is suppressed platform-wide yet. Each tenant still has its own list, and an opt-out a recipient makes is written globally automatically."
                />

                <template v-else>
                    <div
                        class="overflow-x-auto rounded-2xl border border-border bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        tabindex="0"
                        role="region"
                        aria-label="Global do-not-contact list"
                    >
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Identity</TableHead>
                                    <TableHead>Type</TableHead>
                                    <TableHead>Why</TableHead>
                                    <TableHead>Added</TableHead>
                                    <TableHead>By</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                <TableRow
                                    v-for="entry in suppressions"
                                    :key="entry.id"
                                >
                                    <th
                                        scope="row"
                                        class="px-4 py-3 text-start align-top font-medium"
                                    >
                                        {{ entry.preview }}
                                        <p
                                            v-if="entry.note"
                                            class="mt-0.5 text-xs font-normal text-muted-foreground"
                                        >
                                            {{ entry.note }}
                                        </p>
                                    </th>
                                    <TableCell class="align-top">
                                        {{ entry.type_label }}
                                    </TableCell>
                                    <TableCell class="align-top">
                                        <Badge variant="outline">
                                            {{ entry.reason_label }}
                                        </Badge>
                                    </TableCell>
                                    <TableCell class="align-top tabular-nums">
                                        {{ utcDateTime(entry.suppressed_at) }}
                                    </TableCell>
                                    <TableCell class="align-top">
                                        {{ entry.added_by ?? 'system' }}
                                    </TableCell>
                                </TableRow>
                            </TableBody>
                        </Table>
                    </div>

                    <AdminPager
                        :pagination="pagination"
                        label="global entries"
                    />
                </template>
            </div>
        </section>
    </div>
</template>
