<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ShieldOff, UserMinus } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
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

/*
 * One tenant's operations page (Phase 19a).
 *
 * The bespoke screen the registry deliberately is not. It has to *decide* things — who the owners
 * are, whether a suspension can be lifted, what the account is actually using — and a declarative
 * descriptor that could decide any of that would have stopped being declarative.
 *
 * Two things the copy carries deliberately, because an operator should not have to learn them from
 * a support incident:
 *
 *  - **Suspension is not a lockout.** The panel says so where the button is, so nobody reaches for
 *    it expecting the account to go dark, and nobody avoids it fearing that it will.
 *  - **The reason is required.** The field says why: a suspension nobody explained is one the next
 *    operator cannot safely lift, so the customer stays switched off over a missing sentence.
 *
 * Brand (§2a): `status.warning` for suspension, `status.error` for the destructive member removal
 * (behind a confirmation, like every other destructive action in the panel). Signal Orange appears
 * nowhere — every state on this screen is a status.
 */
const props = defineProps<{
    team: {
        id: number;
        name: string;
        market: string;
        timezone: string;
        locale: string;
        stripe_id: string | null;
        trial_ends_at: string | null;
        suspended_at: string | null;
        suspension_reason: string | null;
        created_at: string | null;
    };
    members: {
        id: number;
        name: string;
        email: string;
        role: string | null;
        is_operator: boolean;
        two_factor: boolean;
        verified: boolean;
    }[];
    roles: { value: string; label: string }[];
    usage: { leads: number; discovery_runs: number; messages_sent: number };
}>();

const { number, dateTime } = useFormat();

/** The member whose removal is awaiting a second click. */
const confirmingRemoval = ref<number | null>(null);

const suspendForm = useForm({ reason: '' });
const restoreForm = useForm({ note: '' });

function suspend(): void {
    suspendForm.post(`/admin/teams/${props.team.id}/suspend`, {
        preserveScroll: true,
        onSuccess: () => suspendForm.reset(),
    });
}

function restore(): void {
    restoreForm.post(`/admin/teams/${props.team.id}/restore`, {
        preserveScroll: true,
        onSuccess: () => restoreForm.reset(),
    });
}

function changeRole(memberId: number, role: string): void {
    router.patch(
        `/admin/teams/${props.team.id}/members/${memberId}`,
        { role },
        { preserveScroll: true },
    );
}

function removeMember(memberId: number): void {
    confirmingRemoval.value = null;

    router.delete(`/admin/teams/${props.team.id}/members/${memberId}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="team.name" />

    <div class="flex flex-col gap-8">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    Tenant · {{ team.id }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ team.name }}
                </h1>
                <p class="mt-2 flex flex-wrap items-center gap-2 text-sm">
                    <Badge variant="outline">{{ team.market }}</Badge>
                    <Badge variant="outline">{{ team.timezone }}</Badge>
                    <Badge variant="outline">{{ team.locale }}</Badge>
                    <Badge v-if="team.suspended_at" variant="warning">
                        Suspended {{ dateTime(team.suspended_at) }}
                    </Badge>
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link :href="`/admin/resources/teams/${team.id}/edit`">
                    Edit settings
                </Link>
            </Button>
        </header>

        <section aria-label="Usage">
            <dl class="grid grid-cols-3 gap-3">
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Prospects
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ number(usage.leads) }}
                        </dd>
                    </CardContent>
                </Card>
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Discovery runs
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ number(usage.discovery_runs) }}
                        </dd>
                    </CardContent>
                </Card>
                <Card class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                        >
                            Messages sent
                        </dt>
                        <dd class="mt-1 text-2xl font-semibold tabular-nums">
                            {{ number(usage.messages_sent) }}
                        </dd>
                    </CardContent>
                </Card>
            </dl>
        </section>

        <section class="flex flex-col gap-3" aria-label="Members">
            <h2 class="text-lg font-semibold text-foreground">Members</h2>

            <div class="overflow-x-auto rounded-2xl border border-border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Name</TableHead>
                            <TableHead>Email</TableHead>
                            <TableHead>Role</TableHead>
                            <TableHead>Account</TableHead>
                            <TableHead class="w-0">
                                <span class="sr-only">Actions</span>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow v-for="member in members" :key="member.id">
                            <TableCell class="font-medium">
                                <Link
                                    :href="`/admin/users/${member.id}`"
                                    class="rounded-sm underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ member.name }}
                                </Link>
                                <Badge
                                    v-if="member.is_operator"
                                    variant="info"
                                    class="ms-2"
                                >
                                    Operator
                                </Badge>
                            </TableCell>
                            <TableCell class="text-muted-foreground">
                                {{ member.email }}
                            </TableCell>
                            <TableCell>
                                <Label
                                    :for="`role-${member.id}`"
                                    class="sr-only"
                                >
                                    Role for {{ member.name }}
                                </Label>
                                <NativeSelect
                                    :id="`role-${member.id}`"
                                    :model-value="member.role ?? ''"
                                    class="h-8 w-36"
                                    @change="
                                        changeRole(
                                            member.id,
                                            ($event.target as HTMLSelectElement)
                                                .value,
                                        )
                                    "
                                >
                                    <option
                                        v-for="role in roles"
                                        :key="role.value"
                                        :value="role.value"
                                    >
                                        {{ role.label }}
                                    </option>
                                </NativeSelect>
                            </TableCell>
                            <TableCell class="space-x-1">
                                <Badge
                                    :variant="
                                        member.verified ? 'success' : 'warning'
                                    "
                                >
                                    {{
                                        member.verified
                                            ? 'Verified'
                                            : 'Unverified'
                                    }}
                                </Badge>
                                <Badge
                                    :variant="
                                        member.two_factor
                                            ? 'success'
                                            : 'secondary'
                                    "
                                >
                                    {{ member.two_factor ? '2FA' : 'No 2FA' }}
                                </Badge>
                            </TableCell>
                            <!--
                              Two clicks, and the second one is `status.error`. Removing somebody
                              from a team takes away their access to work their colleagues can see,
                              and a single ghost button next to a row is the easiest misclick on the
                              page. The confirm button takes focus, so a keyboard user is not
                              dropped to the body when the first button disappears.
                            -->
                            <TableCell class="space-x-1 text-end">
                                <Button
                                    v-if="confirmingRemoval !== member.id"
                                    variant="ghost"
                                    size="sm"
                                    @click="confirmingRemoval = member.id"
                                >
                                    <UserMinus class="size-4" />
                                    <span class="sr-only">
                                        Remove {{ member.name }}
                                    </span>
                                </Button>
                                <template v-else>
                                    <Button
                                        v-focus
                                        variant="destructive"
                                        size="sm"
                                        @click="removeMember(member.id)"
                                    >
                                        Remove
                                    </Button>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        @click="confirmingRemoval = null"
                                    >
                                        Cancel
                                    </Button>
                                </template>
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>

            <p class="text-xs text-muted-foreground">
                Removing a member and changing a role both go through Auth's own
                actions, which refuse to leave a team without an owner.
            </p>
        </section>

        <section class="flex flex-col gap-3" aria-label="Suspension">
            <h2 class="text-lg font-semibold text-foreground">Suspension</h2>

            <div
                v-if="team.suspended_at"
                class="flex flex-col gap-3 rounded-2xl border border-warning/30 bg-warning/12 px-5 py-4"
            >
                <p class="text-sm text-foreground">
                    Suspended {{ dateTime(team.suspended_at) }} —
                    <span class="font-medium">
                        {{ team.suspension_reason ?? 'no reason recorded' }}
                    </span>
                </p>

                <form class="flex flex-col gap-2" @submit.prevent="restore">
                    <Label for="restore-note">Note (optional)</Label>
                    <Input
                        id="restore-note"
                        v-model="restoreForm.note"
                        placeholder="Paid up, invoice #1234"
                    />
                    <InputError :message="restoreForm.errors.note" />
                    <Button
                        type="submit"
                        class="self-start"
                        :disabled="restoreForm.processing"
                    >
                        Lift the suspension
                    </Button>
                </form>
            </div>

            <form
                v-else
                class="flex flex-col gap-2 rounded-2xl border border-border bg-card px-5 py-4"
                @submit.prevent="suspend"
            >
                <Label for="suspend-reason">Reason</Label>
                <Input
                    id="suspend-reason"
                    v-model="suspendForm.reason"
                    required
                    placeholder="Three spam complaints from the same campaign"
                />
                <InputError :message="suspendForm.errors.reason" />
                <p class="text-xs text-muted-foreground">
                    Required, and for a reason: a suspension nobody explained is
                    one the next operator cannot safely lift. Suspension stops
                    sourcing, sending and spending — members keep signing in and
                    reading their own data.
                </p>
                <Button
                    type="submit"
                    variant="outline"
                    class="self-start"
                    :disabled="suspendForm.processing"
                >
                    <ShieldOff class="size-4" />
                    Suspend this tenant
                </Button>
            </form>
        </section>
    </div>
</template>
