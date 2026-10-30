<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { AlertTriangle, Check, RefreshCw, Scan, Sparkles } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
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
    BackgroundCheckProgress,
    BusinessIntelProfile,
    BusinessIntelReport,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Background check', href: '/business-intel' }],
    },
});

const props = defineProps<{
    report: BusinessIntelReport | null;
    profile: BusinessIntelProfile;
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

const acceptForm = useForm({
    apply_to_profile: true,
    seed_icp: false,
});

function accept(): void {
    if (!props.report) return;
    acceptForm.post(`/business-intel/${props.report.id}/accept`, {
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
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
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
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
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
                </article>

                <article
                    class="space-y-2 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Positioning') }}
                    </h2>
                    <p class="text-foreground" dir="auto">
                        {{ findings.positioning }}
                    </p>
                </article>

                <article
                    v-if="findings.suggested_industries.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Suggested industries') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in findings.suggested_industries"
                            :key="item"
                            class="inline-flex rounded-full bg-brand/10 px-3 py-1 text-xs text-brand"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                </article>

                <article
                    v-if="findings.target_customers.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Likely target customers') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in findings.target_customers"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
                </article>

                <article
                    v-if="findings.competitors.length"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                >
                    <h2
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Possible competitors') }}
                    </h2>
                    <ul class="flex flex-wrap gap-1.5">
                        <li
                            v-for="item in findings.competitors"
                            :key="item"
                            class="inline-flex rounded-full bg-accent px-3 py-1 text-xs text-foreground"
                            dir="auto"
                        >
                            {{ item }}
                        </li>
                    </ul>
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
                                'Accepting fills any blank fields in your business profile. It never overwrites what you’ve already entered.',
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

                <div class="flex items-start gap-3">
                    <Checkbox
                        id="seed-icp"
                        v-model="acceptForm.seed_icp"
                        aria-describedby="seed-icp-hint"
                        data-test="seed-icp"
                    />
                    <span class="text-sm">
                        <Label for="seed-icp" class="font-normal">{{
                            t('Also create a suggested ideal-customer profile')
                        }}</Label>
                        <span
                            id="seed-icp-hint"
                            class="block text-muted-foreground"
                            >{{
                                t('You can tune its weights afterwards.')
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
