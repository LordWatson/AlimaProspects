<script setup lang="ts">
import { Head, usePage, useForm } from '@inertiajs/vue3';
import { ShieldCheck, TriangleAlert } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Textarea } from '@/components/ui/textarea';
import type { AdminProvisioningOptions, ProvisionPreview } from '@/types';

/*
 * Onboarding a business from the panel (Phase 19b).
 *
 * **Two buttons, and the first one writes nothing.** "Check" runs the dry run and shows exactly
 * what will happen, because the first real use of this screen is somebody typing a customer's name
 * wrong and the second is discovering that the owner's address already belongs to an account — a
 * thing an operator must be told *before* a stranger is emailed an invitation into somebody else's
 * business.
 *
 * There is **no password field**, and the copy says why: the invitation is the only way in.
 */
const props = defineProps<{ options: AdminProvisioningOptions }>();

const page = usePage();

const preview = computed<ProvisionPreview | null>(
    () => (page.props.provision_preview ?? null) as ProvisionPreview | null,
);

const form = useForm({
    team_name: '',
    market: props.options.markets[0]?.value ?? 'uk',
    timezone: 'Europe/London',
    locale: props.options.locales[0]?.value ?? 'en',
    owner_email: '',
    owner_name: '',
    billing_mode: 'stripe',
    grant_plan: '',
    grant_seats: '',
    grant_leads: '',
    grant_credits: '',
    grant_note: '',
    trial_days: '',
    company_website: '',
    industry: '',
    value_proposition: '',
    icp_name: '',
    capabilities_on: [] as string[],
});

/*
 * The market picks a sensible timezone, because an operator setting up a Dubai customer should not
 * have to remember the IANA name — and a wrong timezone is invisible until the team's reports are
 * all a few hours out (`DateRange::constrain()`).
 */
function onMarketChange(): void {
    form.timezone = form.market === 'uae' ? 'Asia/Dubai' : 'Europe/London';
}

const isInvoiced = computed(() => form.billing_mode === 'manual');

/** Real labels. `capitalize` on the raw keys produced "Seats", "Leads" and "Credits" by luck. */
const allowanceLabels: Record<string, string> = {
    seats: 'Seats',
    leads: 'Leads a month',
    credits: 'AI credits',
};

function check(): void {
    form.post('/admin/provisioning/preview', { preserveScroll: true });
}

function submit(): void {
    form.post('/admin/provisioning');
}
</script>

<template>
    <Head title="Onboard a business" />

    <div class="flex max-w-3xl flex-col gap-8">
        <header>
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Onboard a business
            </h1>
            <p class="mt-2 max-w-prose text-sm text-muted-foreground">
                Creates the tenant, its Owner and anything you already know
                about them, then emails the Owner a link to set their own
                password. Check it first — the dry run writes nothing.
            </p>
        </header>

        <!-- The dry run's answer. -->
        <section
            v-if="preview"
            class="flex flex-col gap-3 rounded-2xl px-5 py-4"
            :class="
                preview.owner_exists
                    ? 'border border-warning/30 bg-warning/12'
                    : 'border border-info/30 bg-info/12'
            "
            aria-label="What this will do"
            role="status"
        >
            <div class="flex items-center gap-2">
                <component
                    :is="preview.owner_exists ? TriangleAlert : ShieldCheck"
                    class="size-4 shrink-0"
                    :class="preview.owner_exists ? 'text-warning' : 'text-info'"
                    aria-hidden="true"
                />
                <p class="font-semibold text-foreground">
                    {{ preview.summary }}
                </p>
            </div>

            <p v-if="preview.owner_exists" class="text-sm text-foreground/90">
                <strong>{{ preview.owner_email }}</strong> already belongs to
                {{ preview.owner_name }}, who is in
                {{ preview.owner_team_count }} team(s). They will be added to
                this one as its Owner. If that is not who you meant, change the
                address.
            </p>

            <ul class="flex flex-wrap gap-2 text-xs" aria-label="Also created">
                <!-- Each Badge in its own `<li>`: a `<ul>` may only contain list items. -->
                <li v-if="preview.will_create_profile">
                    <Badge variant="outline">
                        Business profile pre-filled
                    </Badge>
                </li>
                <li v-if="preview.will_create_icp">
                    <Badge variant="outline">
                        Draft ideal customer profile
                    </Badge>
                </li>
                <li
                    v-for="capability in preview.capabilities_on"
                    :key="capability"
                >
                    <Badge variant="outline">
                        {{ capability }} switched on
                    </Badge>
                </li>
            </ul>
        </section>

        <form class="flex flex-col gap-8" @submit.prevent="submit">
            <!-- The tenant -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
                <legend class="mb-2 text-lg font-semibold text-foreground">
                    The business
                </legend>

                <div class="sm:col-span-2">
                    <Label for="team_name">Name</Label>
                    <Input id="team_name" v-model="form.team_name" required />
                    <InputError :message="form.errors.team_name" />
                </div>

                <div>
                    <Label for="market">Market</Label>
                    <NativeSelect
                        id="market"
                        v-model="form.market"
                        @change="onMarketChange"
                    >
                        <option
                            v-for="m in options.markets"
                            :key="m.value"
                            :value="m.value"
                        >
                            {{ m.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.market" />
                </div>

                <div>
                    <Label for="timezone">Timezone</Label>
                    <Input id="timezone" v-model="form.timezone" required />
                    <InputError :message="form.errors.timezone" />
                </div>

                <div>
                    <Label for="locale">Language</Label>
                    <NativeSelect id="locale" v-model="form.locale">
                        <option
                            v-for="l in options.locales"
                            :key="l.value"
                            :value="l.value"
                        >
                            {{ l.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.locale" />
                </div>
            </fieldset>

            <!-- The owner -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
                <legend class="mb-2 text-lg font-semibold text-foreground">
                    Their first user
                </legend>

                <div>
                    <Label for="owner_name">Name</Label>
                    <Input id="owner_name" v-model="form.owner_name" required />
                    <InputError :message="form.errors.owner_name" />
                </div>

                <div>
                    <Label for="owner_email">Email</Label>
                    <Input
                        id="owner_email"
                        v-model="form.owner_email"
                        type="email"
                        required
                    />
                    <InputError :message="form.errors.owner_email" />
                </div>

                <p class="text-xs text-muted-foreground sm:col-span-2">
                    There is no password field on purpose. They get an
                    invitation and choose their own — we never know it.
                </p>
            </fieldset>

            <!-- Billing -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
                <legend class="mb-2 text-lg font-semibold text-foreground">
                    Billing
                </legend>

                <div>
                    <Label for="billing_mode">How they pay</Label>
                    <NativeSelect id="billing_mode" v-model="form.billing_mode">
                        <option
                            v-for="m in options.modes"
                            :key="m.value"
                            :value="m.value"
                        >
                            {{ m.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.billing_mode" />
                </div>

                <div>
                    <Label for="trial_days">Trial (days)</Label>
                    <Input
                        id="trial_days"
                        v-model="form.trial_days"
                        type="number"
                        min="0"
                        max="365"
                        :placeholder="isInvoiced ? '0' : 'Usual'"
                    />
                    <InputError :message="form.errors.trial_days" />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Blank uses the usual length. Invoiced customers get none
                        by default — their contract has already started.
                    </p>
                </div>

                <div class="sm:col-span-2">
                    <Label for="grant_plan">
                        Grant a plan (no Stripe involved)
                    </Label>
                    <NativeSelect id="grant_plan" v-model="form.grant_plan">
                        <option value="">None</option>
                        <option
                            v-for="p in options.plans"
                            :key="p.value"
                            :value="p.value"
                        >
                            {{ p.label }}
                        </option>
                    </NativeSelect>
                    <InputError :message="form.errors.grant_plan" />
                    <!--
                      `text-foreground`, not `text-warning`: amber type at 12px does not clear AA,
                      and the sentence is the thing that has to be readable. The warning colour
                      stays on icons, borders and tints.
                    -->
                    <p
                        v-if="isInvoiced && !form.grant_plan"
                        class="mt-1 text-xs text-foreground"
                    >
                        An invoiced tenant with no grant lands on the default
                        plan. Grant the one their contract says.
                    </p>
                </div>

                <template v-if="form.grant_plan">
                    <div v-for="f in ['seats', 'leads', 'credits']" :key="f">
                        <Label :for="`grant_${f}`">
                            {{ allowanceLabels[f] }}
                        </Label>
                        <Input
                            :id="`grant_${f}`"
                            v-model="
                                form[
                                    `grant_${f}` as
                                        | 'grant_seats'
                                        | 'grant_leads'
                                        | 'grant_credits'
                                ]
                            "
                            type="number"
                            min="0"
                            placeholder="Plan default"
                        />
                    </div>

                    <div class="sm:col-span-2">
                        <Label for="grant_note">Contract reference</Label>
                        <Input id="grant_note" v-model="form.grant_note" />
                    </div>
                </template>
            </fieldset>

            <!-- What sales already knows -->
            <fieldset class="grid gap-3 sm:grid-cols-2">
                <legend class="mb-2 text-lg font-semibold text-foreground">
                    What you already know
                </legend>
                <p class="text-xs text-muted-foreground sm:col-span-2">
                    Anything here lands in their onboarding half-done, so the
                    first screen is “we wrote this down, is it right?” rather
                    than a blank form.
                </p>

                <div>
                    <Label for="company_website">Website</Label>
                    <Input
                        id="company_website"
                        v-model="form.company_website"
                        type="url"
                    />
                    <InputError :message="form.errors.company_website" />
                </div>

                <div>
                    <Label for="industry">Industry</Label>
                    <Input id="industry" v-model="form.industry" />
                </div>

                <div class="sm:col-span-2">
                    <Label for="value_proposition">What they sell</Label>
                    <Textarea
                        id="value_proposition"
                        v-model="form.value_proposition"
                        rows="3"
                    />
                </div>

                <div class="sm:col-span-2">
                    <Label for="icp_name">
                        Draft ideal customer profile (optional)
                    </Label>
                    <Input
                        id="icp_name"
                        v-model="form.icp_name"
                        placeholder="Mid-market logistics"
                    />
                    <p class="mt-1 text-xs text-muted-foreground">
                        Created inactive — an ICP with no criteria would score
                        every company the same.
                    </p>
                </div>
            </fieldset>

            <!-- Capability overrides -->
            <fieldset class="flex flex-col gap-2">
                <legend class="mb-2 text-lg font-semibold text-foreground">
                    Features beyond their plan
                </legend>

                <div
                    v-for="c in options.capabilities"
                    :key="c.value"
                    class="flex items-center gap-2"
                >
                    <Checkbox
                        :id="`cap-${c.value}`"
                        :model-value="form.capabilities_on.includes(c.value)"
                        @update:model-value="
                            form.capabilities_on = $event
                                ? [...form.capabilities_on, c.value]
                                : form.capabilities_on.filter(
                                      (v) => v !== c.value,
                                  )
                        "
                    />
                    <Label :for="`cap-${c.value}`">{{ c.label }}</Label>
                </div>
            </fieldset>

            <div class="flex flex-wrap items-center gap-3">
                <Button
                    variant="outline"
                    type="button"
                    :disabled="form.processing"
                    @click="check"
                >
                    Check what this will do
                </Button>
                <Button type="submit" :disabled="form.processing || !preview">
                    Create the tenant
                </Button>
                <p v-if="!preview" class="text-xs text-muted-foreground">
                    Run the check first.
                </p>
            </div>
        </form>
    </div>
</template>
