<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, RefreshCw, Scan, Sparkles } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import FindingEvidence from '@/components/business-intel/FindingEvidence.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Label } from '@/components/ui/label';
import { Progress } from '@/components/ui/progress';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import type {
    BackgroundCheckEvidence,
    BackgroundCheckProgress,
    BusinessIntelProfile,
    BusinessIntelReport,
    ProfileMappingRow,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Background check', href: '/business-intel' }],
    },
});

const props = defineProps<{
    report: BusinessIntelReport | null;
    profile: BusinessIntelProfile;
    /** What accepting would change in the profile, field by field. Empty unless a report is ready. */
    mapping: ProfileMappingRow[];
    can: { manage: boolean };
}>();

const { t } = useI18n();
const page = usePage();

// Shown on the progress bar before the first Reverb event arrives.
const INITIAL_PERCENT = 10;

const teamId = computed(() => page.props.auth.currentTeam?.id ?? null);

// Live progress pushed over Reverb while a check runs; null until the first event.
const progress = ref<BackgroundCheckProgress | null>(null);

const isRunning = computed(
    () =>
        props.report?.status === 'pending' ||
        progress.value?.status === 'pending',
);

const findings = computed(() => props.report?.findings ?? null);

const confidencePct = computed(() =>
    findings.value?.confidence != null
        ? Math.round(findings.value.confidence * 100)
        : null,
);

/**
 * The team's own trade. Reports written under prompt v1 carry it in `suggested_industries`, where it
 * was the only reading that framing allowed.
 */
const ownIndustry = computed(
    () =>
        findings.value?.own_industry ??
        findings.value?.suggested_industries?.[0] ??
        null,
);

/** The industries the team's customers are in — what the suggested ICP is built from. */
const customerIndustries = computed(
    () => findings.value?.customer_industries ?? [],
);

const targetCustomers = computed(() => findings.value?.target_customers ?? []);

const decisionMakerTitles = computed(
    () => findings.value?.decision_maker_titles ?? [],
);

const competitors = computed(() => findings.value?.competitors ?? []);

/**
 * Whether the check worked out who buys from this business. When it didn't, seeding an ICP would
 * produce one with no customer-side signal at all, so the option is disabled and a re-run offered
 * instead — that is the state an older v1 report lands in.
 */
const hasCustomerSignals = computed(
    () =>
        customerIndustries.value.length > 0 ||
        targetCustomers.value.length > 0 ||
        decisionMakerTitles.value.length > 0,
);

/**
 * The quotes behind one finding. `undefined` for a report written before findings carried
 * evidence, which the evidence block reads as "show nothing" rather than "nothing supports this".
 */
function evidenceFor(field: string): BackgroundCheckEvidence[] | undefined {
    return findings.value?.evidence?.filter((item) => item.field === field);
}

const priceBand = computed(() => {
    const min = findings.value?.pricing_min ?? null;
    const max = findings.value?.pricing_max ?? null;
    const code = (findings.value?.pricing_currency ?? '').toUpperCase();
    const format = (value: number) => value.toLocaleString('en');

    if (min == null && max == null) {
        return null;
    }

    if (min != null && max != null) {
        return `${format(min)} – ${format(max)} ${code}`.trim();
    }

    return min != null
        ? t('From :amount', { amount: `${format(min)} ${code}`.trim() })
        : t('Up to :amount', {
              amount: `${format(max as number)} ${code}`.trim(),
          });
});

const deliveryLabels: Record<string, string> = {
    local: 'In person, close to where you are based',
    regional: 'In person, across your region',
    national: 'Anywhere in the country',
    remote: 'Remotely, wherever the customer is',
};

/** The findings a search for customers depends on, each with the evidence field it is filed under. */
const moreFindings = computed(() => {
    const value = findings.value;

    if (!value) {
        return [];
    }

    return [
        {
            field: 'proof_customers',
            title: t('Customers your site names'),
            hint: t('Alima looks for more companies like these.'),
            items: value.proof_customers ?? [],
            text: null,
        },
        {
            field: 'problems_solved',
            title: t('Problems you solve'),
            hint: null,
            items: value.problems_solved ?? [],
            text: null,
        },
        {
            field: 'buying_triggers',
            title: t('What makes a customer ready to buy'),
            hint: null,
            items: value.buying_triggers ?? [],
            text: null,
        },
        {
            field: 'anti_customers',
            title: t('Who is not a customer'),
            hint: t('Alima leaves these out when it looks for leads.'),
            items: value.anti_customers ?? [],
            text: null,
        },
        {
            field: 'pricing',
            title: t('What a job costs'),
            hint: t('This tells Alima how big a customer needs to be.'),
            items: [],
            text: priceBand.value,
        },
        {
            field: 'delivery_model',
            title: t('How you deliver'),
            hint: t('This tells Alima how far away a customer can be.'),
            items: [],
            text: value.delivery_model
                ? t(
                      deliveryLabels[value.delivery_model] ??
                          value.delivery_model,
                  )
                : null,
        },
        {
            field: 'customer_size_range',
            title: t('How big your customers are'),
            hint: null,
            items: [],
            text: value.customer_size_range ?? null,
        },
    ].filter((finding) => finding.items.length > 0 || finding.text);
});

const fieldLabels: Record<string, string> = {
    industry: 'Your industry',
    value_proposition: 'What you offer',
    customer_examples: 'Customers you already have',
    customer_industries: 'Industries that buy from you',
    target_customers: 'Kinds of customer',
    buyer_titles: 'Who decides at a customer',
    competitors: 'Competitors',
    problems_solved: 'Problems you solve',
    buying_triggers: 'What makes a customer ready to buy',
    anti_customers: 'Who is not a customer',
    pricing: 'What a job costs',
    delivery_model: 'How you deliver',
    customer_size_range: 'How big your customers are',
};

const fills = computed(() =>
    props.mapping.filter((row) => row.state === 'fill'),
);
const conflicts = computed(() =>
    props.mapping.filter((row) => row.state === 'conflict'),
);

const acceptForm = useForm({
    apply_to_profile: true,
    seed_icp: false,
    // Fields where the team entered something else and chose Alima's finding. Empty by default:
    // what the team typed wins unless they say otherwise.
    overrides: [] as string[],
});

function setOverride(field: string, chosen: boolean | 'indeterminate'): void {
    acceptForm.overrides =
        chosen === true
            ? [...new Set([...acceptForm.overrides, field])]
            : acceptForm.overrides.filter((item) => item !== field);
}

function accept(): void {
    if (!props.report) return;
    // A choice made while the profile option was ticked means nothing once it is unticked.
    acceptForm
        .transform((data) => ({
            ...data,
            overrides: data.apply_to_profile ? data.overrides : [],
        }))
        .post(`/business-intel/${props.report.id}/accept`, {
            preserveScroll: true,
        });
}

function rerun(): void {
    router.post('/business-intel/rerun', {}, { preserveScroll: true });
}

// --- Reverb: live progress -------------------------------------------------
let channelName: string | null = null;

function onProgress(payload: BackgroundCheckProgress): void {
    progress.value = payload;
    // When the run finishes, pull the fresh report (findings / failure) from the server.
    if (payload.status !== 'pending') {
        router.reload({ only: ['report'] });
    }
}

onMounted(() => {
    if (teamId.value == null || !window.Echo) return;
    channelName = `teams.${teamId.value}`;
    window.Echo.private(channelName).listen(
        '.background-check.progressed',
        onProgress,
    );
});

onBeforeUnmount(() => {
    if (channelName && window.Echo) {
        window.Echo.leave(channelName);
    }
});
</script>

<template>
    <Head :title="t('Background check')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="03"
            :eyebrow="t('Background check')"
            :title="t('What Alima learned|about you.')"
            :description="
                t(
                    'Alima reads your public web presence and drafts a profile of your business. Review it, then accept to fill in your profile — and optionally seed an ideal-customer profile.',
                )
            "
        >
            <template v-if="can.manage && report && !isRunning" #actions>
                <Button variant="hero" data-test="rerun" @click="rerun">
                    <RefreshCw class="size-4" />
                    {{ t('Run again') }}
                </Button>
            </template>
        </PageHero>

        <!-- Empty: never run -->
        <EmptyState
            v-if="!report && !isRunning"
            :icon="Scan"
            :title="t('No background check yet')"
            :description="
                t(
                    'Run a check and Alima will summarise what your business does, how it’s positioned, and who it should target.',
                )
            "
        >
            <Button
                v-if="can.manage"
                variant="brand"
                data-test="run-first"
                @click="rerun"
            >
                <Sparkles class="size-4" />
                {{ t('Run background check') }}
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Ask an owner or admin to run it.') }}
            </p>
        </EmptyState>

        <!-- Running -->
        <section
            v-else-if="isRunning"
            class="flex flex-col items-center gap-5 rounded-2xl border border-border bg-card px-6 py-14 text-center"
            data-test="running"
        >
            <Spinner class="size-8 text-brand" />
            <div class="space-y-1">
                <p class="text-lg font-semibold rtl:font-bold">
                    {{ t('Alima is working on your background check') }}
                </p>
                <p class="text-sm text-muted-foreground" aria-live="polite">
                    {{ progress?.step ?? t('Starting your background check') }}
                </p>
            </div>
            <Progress
                :value="progress?.percent ?? INITIAL_PERCENT"
                :label="t('Background check')"
                class="max-w-sm"
            />
        </section>

        <!-- Failed -->
        <section
            v-else-if="report && report.status === 'failed'"
            class="flex flex-col items-center gap-4 rounded-2xl border border-border bg-card px-6 py-12 text-center"
            role="alert"
            data-test="failed"
        >
            <span
                class="flex size-11 items-center justify-center rounded-full bg-destructive/10 text-destructive"
            >
                <AlertTriangle class="size-5" />
            </span>
            <div class="space-y-1">
                <p class="text-lg font-semibold rtl:font-bold">
                    {{ t('That didn’t work') }}
                </p>
                <p class="max-w-md text-sm text-muted-foreground" dir="auto">
                    {{
                        report.error_message
                            ? t(report.error_message)
                            : t('We couldn’t complete the background check.')
                    }}
                </p>
            </div>
            <Button
                v-if="can.manage"
                variant="brand"
                data-test="retry"
                @click="rerun"
            >
                <RefreshCw class="size-4" />
                {{ t('Try again') }}
            </Button>
        </section>

        <!-- Ready / Accepted: findings -->
        <template v-else-if="report && findings">
            <div class="flex flex-wrap items-center gap-3">
                <Badge v-if="report.status === 'accepted'" variant="brand">
                    <Check class="size-3" />
                    {{ t('Accepted') }}
                </Badge>
                <Badge v-else variant="outline">{{
                    t('Ready to review')
                }}</Badge>
                <span class="font-mono text-xs text-muted-foreground">
                    {{ t('Version :n', { n: report.version }) }}
                </span>
                <span
                    v-if="confidencePct != null"
                    class="font-mono text-xs text-muted-foreground"
                >
                    {{ t('Confidence :pct%', { pct: confidencePct }) }}
                </span>
            </div>

            <!-- Findings cards -->
            <div class="grid gap-5 lg:grid-cols-2">
                <article
                    class="space-y-2 rounded-2xl border border-border bg-card p-6 lg:col-span-2"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Summary') }}
                    </h2>
                    <p class="leading-relaxed text-foreground" dir="auto">
                        {{ findings.summary }}
                    </p>
                </article>

                <article
                    class="space-y-2 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('What you offer') }}
                    </h2>
                    <p class="text-foreground" dir="auto">
                        {{ findings.offering }}
                    </p>
                    <p
                        v-if="profile.value_proposition"
                        class="border-t border-border pt-2 text-sm text-muted-foreground"
                    >
                        {{ t('You said:') }}
                        <span dir="auto">{{ profile.value_proposition }}</span>
                    </p>
                    <FindingEvidence :items="evidenceFor('offering')" />
                </article>

                <article
                    class="space-y-2 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Positioning') }}
                    </h2>
                    <p class="text-foreground" dir="auto">
                        {{ findings.positioning }}
                    </p>
                </article>

                <article
                    v-if="ownIndustry"
                    class="space-y-2 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Your industry') }}
                    </h2>
                    <p class="text-foreground" dir="auto">{{ ownIndustry }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'This is your trade, so Alima skips it when looking for leads. Businesses like yours are competitors, not customers.',
                            )
                        }}
                    </p>
                    <FindingEvidence :items="evidenceFor('own_industry')" />
                </article>

                <article
                    v-if="customerIndustries.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Industries that buy from you') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Alima looks for leads in these industries. Spot a miss? Run the check again.',
                            )
                        }}
                    </p>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in customerIndustries"
                            :key="item"
                            class="inline-flex rounded-full bg-brand/10 px-3 py-1 text-xs text-brand"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                    <FindingEvidence
                        :items="evidenceFor('customer_industries')"
                    />
                </article>

                <article
                    v-if="targetCustomers.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Likely target customers') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in targetCustomers"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                    <FindingEvidence :items="evidenceFor('target_customers')" />
                </article>

                <article
                    v-if="decisionMakerTitles.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Who to ask for') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in decisionMakerTitles"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                    <FindingEvidence
                        :items="evidenceFor('decision_maker_titles')"
                    />
                </article>

                <!-- What a search for customers depends on beyond who they are: the customers
                     already won, what a job costs, how far the business can serve. -->
                <article
                    v-for="finding in moreFindings"
                    :key="finding.field"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                    :data-test="`finding-${finding.field}`"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ finding.title }}
                    </h2>
                    <p v-if="finding.text" class="text-foreground" dir="auto">
                        {{ finding.text }}
                    </p>
                    <ul v-else class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in finding.items"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                    <p
                        v-if="finding.hint"
                        class="text-sm text-muted-foreground"
                    >
                        {{ finding.hint }}
                    </p>
                    <FindingEvidence :items="evidenceFor(finding.field)" />
                </article>

                <!-- Carries its meaning with an icon and a real heading, not with the tint alone. -->
                <article
                    v-if="!hasCustomerSignals"
                    class="flex items-start gap-3 rounded-2xl border border-warning/30 bg-warning/12 p-6"
                    role="note"
                    data-test="no-customer-signals"
                >
                    <span
                        class="flex size-8 shrink-0 items-center justify-center rounded-full text-warning"
                        aria-hidden="true"
                    >
                        <AlertTriangle class="size-4" />
                    </span>
                    <div class="space-y-1">
                        <h2
                            class="text-sm font-semibold text-foreground rtl:font-bold"
                        >
                            {{ t('We couldn’t tell who buys from you') }}
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Without that, Alima can’t suggest an ideal-customer profile — one built from your own trade would send you looking for competitors. Run the check again, or set your ideal customer by hand.',
                                )
                            }}
                        </p>
                    </div>
                </article>

                <article
                    v-if="competitors.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Possible competitors') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in competitors"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                    <FindingEvidence :items="evidenceFor('competitors')" />
                </article>
            </div>

            <!-- Accept panel -->
            <section
                v-if="can.manage && report.status === 'ready'"
                class="flex flex-col gap-4 rounded-2xl border border-brand/40 bg-brand/5 p-6"
            >
                <div class="space-y-1">
                    <h2 class="text-lg font-semibold rtl:font-bold">
                        {{ t('Accept these findings?') }}
                    </h2>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Accepting fills the blank fields in your business profile. Where you already entered something different, yours stays unless you choose otherwise below.',
                            )
                        }}
                    </p>
                </div>

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="apply-to-profile"
                        v-model="acceptForm.apply_to_profile"
                        data-test="apply-to-profile"
                    />
                    <Label for="apply-to-profile" class="text-sm font-normal">
                        {{ t('Fill in my business profile') }}
                    </Label>
                </div>

                <!-- What that will do, field by field, so nothing changes unseen. -->
                <div
                    v-if="acceptForm.apply_to_profile && fills.length"
                    class="flex flex-col gap-2 ps-7"
                    data-test="mapping-fills"
                >
                    <h3 class="text-sm font-medium">
                        {{ t('Alima will fill in') }}
                    </h3>
                    <dl class="flex flex-col gap-2 text-sm">
                        <div v-for="row in fills" :key="row.field">
                            <dt class="text-muted-foreground">
                                {{ t(fieldLabels[row.field] ?? row.field) }}
                            </dt>
                            <dd class="break-words" dir="auto">
                                {{ row.proposed }}
                            </dd>
                        </div>
                    </dl>
                </div>

                <fieldset
                    v-if="acceptForm.apply_to_profile && conflicts.length"
                    class="flex flex-col gap-3 ps-7"
                    data-test="mapping-conflicts"
                >
                    <legend class="text-sm font-medium">
                        {{ t('Where your site says something different') }}
                    </legend>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Your own entry is kept. Tick a row to use what Alima found instead.',
                            )
                        }}
                    </p>
                    <div
                        v-for="row in conflicts"
                        :key="row.field"
                        class="flex flex-col gap-2 rounded-lg border border-border bg-background p-3"
                    >
                        <p class="text-sm font-medium">
                            {{ t(fieldLabels[row.field] ?? row.field) }}
                        </p>
                        <dl class="grid gap-2 text-sm sm:grid-cols-2">
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    {{ t('You entered') }}
                                </dt>
                                <dd class="break-words" dir="auto">
                                    {{ row.current }}
                                </dd>
                            </div>
                            <div>
                                <dt class="text-xs text-muted-foreground">
                                    {{ t('Alima found') }}
                                </dt>
                                <dd class="break-words" dir="auto">
                                    {{ row.proposed }}
                                </dd>
                            </div>
                        </dl>
                        <div class="flex items-start gap-3">
                            <Checkbox
                                :id="`override-${row.field}`"
                                :model-value="
                                    acceptForm.overrides.includes(row.field)
                                "
                                :data-test="`override-${row.field}`"
                                @update:model-value="
                                    setOverride(row.field, $event)
                                "
                            />
                            <Label
                                :for="`override-${row.field}`"
                                class="text-sm font-normal"
                            >
                                {{ t('Use what Alima found') }}
                                <span class="sr-only">{{
                                    t(fieldLabels[row.field] ?? row.field)
                                }}</span>
                            </Label>
                        </div>
                    </div>
                </fieldset>

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="seed-icp"
                        v-model="acceptForm.seed_icp"
                        :disabled="!hasCustomerSignals"
                        aria-describedby="seed-icp-hint"
                        data-test="seed-icp"
                    />
                    <span class="text-sm">
                        <Label
                            for="seed-icp"
                            class="font-normal"
                            :class="
                                hasCustomerSignals
                                    ? ''
                                    : 'text-muted-foreground'
                            "
                            >{{
                                t(
                                    'Also create a suggested ideal-customer profile',
                                )
                            }}</Label
                        >
                        <span
                            id="seed-icp-hint"
                            class="block text-muted-foreground"
                            >{{
                                hasCustomerSignals
                                    ? t('You can tune its weights afterwards.')
                                    : t(
                                          'Not available — this check didn’t find who buys from you.',
                                      )
                            }}</span
                        >
                    </span>
                </div>

                <div class="flex flex-wrap gap-3 pt-1">
                    <Button
                        variant="brand"
                        :disabled="acceptForm.processing"
                        data-test="accept"
                        @click="accept"
                    >
                        <Check class="size-4" />
                        {{ t('Accept and apply') }}
                    </Button>
                </div>
            </section>

            <p
                v-else-if="report.status === 'accepted'"
                class="text-sm text-muted-foreground"
            >
                {{ t('You accepted this background check.') }}
            </p>
        </template>
    </div>
</template>
