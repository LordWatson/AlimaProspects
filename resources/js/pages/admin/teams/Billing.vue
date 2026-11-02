<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { ref } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import { useFormat } from '@/composables/useFormat';
import type { AdminCapabilityRow, AdminTeamBilling } from '@/types';

/*
 * One tenant's billing and capabilities (Phase 19b, ADR 0015).
 *
 * The page leads with the **resolved** answer rather than the levers, which is the whole design: an
 * operator needs to see that this team is on Scale *because of a grant* and that Signals is on
 * *because of an override* — four things can decide a capability, and a screen that showed only the
 * switches would leave somebody guessing which one was winning.
 *
 * Brand (§2a): `status.*` for every state. Nothing here is Signal Orange.
 */
const props = defineProps<{
    team: AdminTeamBilling['team'];
    entitlements: AdminTeamBilling['entitlements'];
    grant: AdminTeamBilling['grant'];
    capabilities: AdminCapabilityRow[];
    options: AdminTeamBilling['options'];
}>();

const { number, utcDateTime } = useFormat();

const modeForm = useForm({ mode: props.team.billing_mode });

const grantForm = useForm({
    plan: props.options.plans[0]?.value ?? '',
    note: '',
    seats: '',
    leads: '',
    credits: '',
    starts_at: '',
    ends_at: '',
});

/** Which capability's override form is open. One at a time — the reason field needs the room. */
const editing = ref<string | null>(null);

const overrideForm = useForm({
    capability: '',
    enabled: true,
    reason: '',
    expires_at: '',
});

function openOverride(row: AdminCapabilityRow): void {
    editing.value = row.value;
    overrideForm.capability = row.value;
    overrideForm.enabled = row.override?.enabled ?? !row.allowed;
    overrideForm.reason = row.override?.reason ?? '';
    overrideForm.expires_at = '';
}

/** Real labels. `capitalize` on the raw keys produced "Seats", "Leads" and "Credits" by luck. */
const allowanceLabels: Record<string, string> = {
    seats: 'Seats',
    leads: 'Leads a month',
    credits: 'AI credits',
};

/**
 * Which destructive action is asking "are you sure?".
 *
 * Two clicks on both, as on the team screen. Revoking a grant can drop a contracted customer to
 * the default plan, and clearing an override hands a pilot customer's feature back to the plan —
 * neither has an undo, and both are small buttons beside a lot of similar ones.
 */
const confirmingRevoke = ref(false);
const confirmingClear = ref<string | null>(null);

function revokeGrant(): void {
    if (!props.grant) {
        return;
    }

    router.delete(
        `/admin/teams/${props.team.id}/billing/grants/${props.grant.id}`,
        {
            preserveScroll: true,
            onFinish: () => {
                confirmingRevoke.value = false;
            },
        },
    );
}

function clearOverride(capability: string): void {
    router.delete(`/admin/teams/${props.team.id}/capabilities/${capability}`, {
        preserveScroll: true,
        onFinish: () => {
            confirmingClear.value = null;
        },
    });
}

/** What the panel should say is deciding this capability. */
const decidedBy: Record<string, string> = {
    suspension: 'Suspended',
    kill_switch: 'Kill switch',
    override: 'Override',
    plan: 'Plan',
};

function allowance(value: number | null): string {
    // Null is **unlimited** on a plan, which is a different statement from zero. Saying so beats
    // rendering an empty cell an operator has to interpret.
    return value === null ? 'Unlimited' : number(value);
}
</script>

<template>
    <Head :title="`${team.name} — billing`" />

    <div class="flex flex-col gap-8">
        <div>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="`/admin/teams/${team.id}`">
                    <ArrowLeft class="size-4" />
                    {{ team.name }}
                </Link>
            </Button>
        </div>

        <header class="min-w-0">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Billing · tenant {{ team.id }}
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                {{ team.name }}
            </h1>
        </header>

        <!-- What is actually in force, and why. -->
        <section
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card px-5 py-4"
            aria-label="Entitlements in force"
        >
            <div class="flex flex-wrap items-center gap-2">
                <h2 class="text-lg font-semibold text-foreground">
                    In force: {{ entitlements.plan_name }}
                </h2>
                <Badge
                    :variant="
                        entitlements.state === 'past_due'
                            ? 'warning'
                            : entitlements.state === 'none' ||
                                entitlements.state === 'cancelled'
                              ? 'secondary'
                              : 'success'
                    "
                >
                    {{ entitlements.state_label }}
                </Badge>
                <Badge v-if="team.suspended" variant="warning">Suspended</Badge>
            </div>

            <!--
              The consequence as a sentence, outside the Badge. A Badge is
              `whitespace-nowrap overflow-hidden`, so a sentence in one clips on a narrow screen —
              and this is the sentence that explains why the tenant is complaining.
            -->
            <p
                v-if="team.suspended"
                class="rounded-2xl border border-warning/30 bg-warning/12 px-5 py-3 text-sm text-foreground"
            >
                Nothing is sourcing, sending or spending for this team while it
                is suspended — whatever their plan or overrides say.
            </p>

            <dl class="grid grid-cols-3 gap-4 text-sm">
                <div>
                    <dt class="text-muted-foreground">Seats</dt>
                    <dd class="font-mono tabular-nums">
                        {{ allowance(entitlements.seats) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">Leads a month</dt>
                    <dd class="font-mono tabular-nums">
                        {{ allowance(entitlements.leads) }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">AI credits a month</dt>
                    <dd class="font-mono tabular-nums">
                        {{ allowance(entitlements.credits) }}
                    </dd>
                </div>
            </dl>

            <p
                v-if="entitlements.state === 'granted'"
                class="text-sm text-muted-foreground"
            >
                This comes from a grant, not from Stripe — it outranks any
                subscription on the account.
            </p>
        </section>

        <!-- Billing mode -->
        <section class="flex flex-col gap-3" aria-label="Billing mode">
            <h2 class="text-lg font-semibold text-foreground">
                How they are billed
            </h2>

            <form
                class="flex flex-wrap items-end gap-3 rounded-2xl border border-border bg-card px-5 py-4"
                @submit.prevent="
                    modeForm.patch(`/admin/teams/${team.id}/billing/mode`, {
                        preserveScroll: true,
                    })
                "
            >
                <div>
                    <Label for="billing-mode">Mode</Label>
                    <NativeSelect id="billing-mode" v-model="modeForm.mode">
                        <option
                            v-for="mode in options.modes"
                            :key="mode.value"
                            :value="mode.value"
                        >
                            {{ mode.label }}
                        </option>
                    </NativeSelect>
                </div>

                <Button type="submit" :disabled="modeForm.processing">
                    Save
                </Button>

                <p class="w-full max-w-prose text-xs text-muted-foreground">
                    Changing this does not change what they are entitled to.
                    Moving a paying customer to invoiced billing without
                    granting them a plan drops them to the default plan — grant
                    one below first.
                </p>
                <InputError :message="modeForm.errors.mode" />
            </form>
        </section>

        <!-- Plan grant -->
        <section class="flex flex-col gap-3" aria-label="Plan grant">
            <h2 class="text-lg font-semibold text-foreground">Plan grant</h2>

            <div
                v-if="grant"
                class="flex flex-col gap-3 rounded-2xl border border-info/30 bg-info/12 px-5 py-4"
            >
                <p class="text-sm text-foreground">
                    <span class="font-semibold">{{ grant.plan }}</span>
                    from {{ utcDateTime(grant.starts_at) }} UTC
                    <template v-if="grant.ends_at">
                        until {{ utcDateTime(grant.ends_at) }}
                    </template>
                    <template v-else>with no end date</template>
                    — {{ grant.note }}
                </p>
                <!--
                  Two clicks, and the second one is `status.error`. Revoking a grant can drop a
                  contracted customer to the default plan the moment it lands — which is a support
                  call, not an undo.
                -->
                <Button
                    v-if="!confirmingRevoke"
                    variant="outline"
                    class="self-start"
                    @click="confirmingRevoke = true"
                >
                    Revoke it
                </Button>
                <div v-else class="flex flex-wrap items-center gap-2">
                    <p class="text-sm text-foreground">
                        They lose the {{ grant.plan }} plan immediately and fall
                        back to whatever Stripe says — or to the default plan if
                        Stripe says nothing.
                    </p>
                    <Button v-focus variant="destructive" @click="revokeGrant">
                        Revoke the grant
                    </Button>
                    <Button variant="ghost" @click="confirmingRevoke = false">
                        Cancel
                    </Button>
                </div>
            </div>

            <form
                class="grid gap-3 rounded-2xl border border-border bg-card px-5 py-4 sm:grid-cols-2"
                @submit.prevent="
                    grantForm.post(`/admin/teams/${team.id}/billing/grants`, {
                        preserveScroll: true,
                        onSuccess: () => grantForm.reset(),
                    })
                "
            >
                <div class="sm:col-span-2">
                    <Label for="grant-plan">Plan</Label>
                    <NativeSelect id="grant-plan" v-model="grantForm.plan">
                        <option
                            v-for="plan in options.plans"
                            :key="plan.value"
                            :value="plan.value"
                        >
                            {{ plan.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="grantForm.errors.plan" />
                </div>

                <div
                    v-for="field in ['seats', 'leads', 'credits']"
                    :key="field"
                >
                    <Label :for="`grant-${field}`">
                        {{ allowanceLabels[field] }}
                    </Label>
                    <Input
                        :id="`grant-${field}`"
                        v-model="
                            grantForm[field as 'seats' | 'leads' | 'credits']
                        "
                        type="number"
                        min="0"
                        placeholder="Plan default"
                    />
                    <InputError
                        :message="
                            grantForm.errors[
                                field as 'seats' | 'leads' | 'credits'
                            ]
                        "
                    />
                </div>

                <div>
                    <Label for="grant-ends">Ends, UTC (optional)</Label>
                    <Input
                        id="grant-ends"
                        v-model="grantForm.ends_at"
                        type="datetime-local"
                    />
                    <InputError :message="grantForm.errors.ends_at" />
                </div>

                <div class="sm:col-span-2">
                    <Label for="grant-note">Why</Label>
                    <Textarea
                        id="grant-note"
                        v-model="grantForm.note"
                        rows="2"
                        required
                        placeholder="Signed contract, 25 seats and 40,000 leads a month — ref CS-412"
                    />
                    <InputError :message="grantForm.errors.note" />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Blank allowances take the plan's own numbers — not
                        unlimited. An end date is how a goodwill upgrade stops
                        being one without anybody remembering.
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <Button type="submit" :disabled="grantForm.processing">
                        Grant this plan
                    </Button>
                </div>
            </form>
        </section>

        <!-- Capabilities -->
        <section class="flex flex-col gap-3" aria-label="Capabilities">
            <h2 class="text-lg font-semibold text-foreground">Features</h2>
            <p class="max-w-prose text-sm text-muted-foreground">
                Four things decide each of these, in order: suspension, the
                global kill switch, this team's override, then their plan.
            </p>

            <div
                v-for="row in capabilities"
                :key="row.value"
                class="flex flex-col gap-2 rounded-2xl border border-border bg-card px-5 py-4"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <span class="font-medium text-foreground">
                        {{ row.label }}
                    </span>
                    <Badge :variant="row.allowed ? 'success' : 'secondary'">
                        {{ row.allowed ? 'On' : 'Off' }}
                    </Badge>
                    <!--
                      Which of the four layers settled it. ADR 0015 accepts four layers on the
                      condition that the screen says which one is answering, rather than leaving
                      an operator to work it out from the other badges.
                    -->
                    <Badge variant="outline">
                        {{ decidedBy[row.decided_by] ?? row.decided_by }}
                    </Badge>
                    <Badge
                        v-if="row.override && row.override.active"
                        variant="info"
                    >
                        Override: {{ row.override.enabled ? 'on' : 'off' }}
                    </Badge>
                    <Badge
                        v-else-if="row.override"
                        variant="secondary"
                        title="This override has expired, so the plan decides again."
                    >
                        Override expired
                    </Badge>

                    <div class="ms-auto flex items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :aria-label="`${row.override ? 'Change' : 'Add'} the ${row.label} override`"
                            @click="openOverride(row)"
                        >
                            {{ row.override ? 'Change' : 'Override' }}
                        </Button>
                        <template v-if="row.override">
                            <Button
                                v-if="confirmingClear !== row.value"
                                variant="ghost"
                                size="sm"
                                :aria-label="`Clear the ${row.label} override`"
                                @click="confirmingClear = row.value"
                            >
                                Clear
                            </Button>
                            <template v-else>
                                <Button
                                    v-focus
                                    variant="destructive"
                                    size="sm"
                                    @click="clearOverride(row.value)"
                                >
                                    Clear it
                                </Button>
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    @click="confirmingClear = null"
                                >
                                    Cancel
                                </Button>
                            </template>
                        </template>
                    </div>
                </div>

                <p
                    v-if="confirmingClear === row.value"
                    class="text-sm text-foreground"
                >
                    The plan decides again, so this goes
                    {{ row.plan_allows ? 'on' : 'off' }} for them.
                </p>

                <p v-if="row.override" class="text-sm text-muted-foreground">
                    {{ row.override.reason }}
                    <template v-if="row.override.expires_at">
                        — until {{ utcDateTime(row.override.expires_at) }} UTC
                    </template>
                </p>

                <p class="font-mono text-xs text-muted-foreground">
                    Enforced at: {{ row.enforced_at }}
                </p>

                <form
                    v-if="editing === row.value"
                    class="mt-2 flex flex-col gap-2 border-t border-border pt-3"
                    @submit.prevent="
                        overrideForm.post(
                            `/admin/teams/${team.id}/capabilities`,
                            {
                                preserveScroll: true,
                                onSuccess: () => (editing = null),
                            },
                        )
                    "
                >
                    <div class="flex items-center gap-3">
                        <Label :for="`ov-enabled-${row.value}`">
                            Set it to
                        </Label>
                        <NativeSelect
                            :id="`ov-enabled-${row.value}`"
                            :model-value="overrideForm.enabled ? '1' : '0'"
                            class="w-28"
                            @change="
                                overrideForm.enabled =
                                    ($event.target as HTMLSelectElement)
                                        .value === '1'
                            "
                        >
                            <option value="1">On</option>
                            <option value="0">Off</option>
                        </NativeSelect>
                    </div>

                    <Label :for="`ov-reason-${row.value}`">Why</Label>
                    <Textarea
                        :id="`ov-reason-${row.value}`"
                        v-model="overrideForm.reason"
                        rows="2"
                        required
                        placeholder="Signals pilot agreed on the onboarding call — review in January"
                    />
                    <InputError :message="overrideForm.errors.reason" />

                    <Label :for="`ov-expires-${row.value}`">
                        Expires, UTC (optional)
                    </Label>
                    <Input
                        :id="`ov-expires-${row.value}`"
                        v-model="overrideForm.expires_at"
                        type="datetime-local"
                        class="max-w-60"
                    />
                    <InputError :message="overrideForm.errors.expires_at" />

                    <div class="flex items-center gap-2 pt-1">
                        <Button
                            type="submit"
                            :disabled="overrideForm.processing"
                        >
                            Save override
                        </Button>
                        <Button
                            variant="ghost"
                            type="button"
                            @click="editing = null"
                        >
                            Cancel
                        </Button>
                    </div>
                </form>
            </div>
        </section>
    </div>
</template>
