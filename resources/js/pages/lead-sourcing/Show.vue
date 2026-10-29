<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { AlertTriangle, ArrowLeft, Building2, ExternalLink } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Spinner } from '@/components/ui/spinner';
import { useI18n } from '@/composables/useI18n';
import type { DiscoveryRun, DiscoveryRunProgress } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Lead discovery', href: '/discovery' },
            { title: 'Run', href: '/discovery' },
        ],
    },
});

const props = defineProps<{
    run: DiscoveryRun;
    can: { manage: boolean };
}>();

const { t } = useI18n();
const page = usePage();

const INITIAL_PERCENT = 10;

const teamId = computed(() => page.props.auth.currentTeam?.id ?? null);

// Live progress pushed over Reverb while the run works; null until the first event.
const progress = ref<DiscoveryRunProgress | null>(null);

const status = computed(() => progress.value?.status ?? props.run.status);
const isRunning = computed(
    () => status.value === 'pending' || status.value === 'running',
);
const leads = computed(() => props.run.leads ?? []);

// --- Reverb: live progress -------------------------------------------------
let channelName: string | null = null;

function onProgress(payload: DiscoveryRunProgress): void {
    if (payload.run_id !== props.run.id) return;
    progress.value = payload;
    // When the run finishes, pull the fresh run (leads / failure) from the server.
    if (payload.status === 'completed' || payload.status === 'failed') {
        router.reload({ only: ['run'] });
    }
}

onMounted(() => {
    if (teamId.value == null || !window.Echo) return;
    channelName = `teams.${teamId.value}`;
    window.Echo.private(channelName).listen(
        '.discovery-run.progressed',
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
    <Head :title="t('Discovery run')" />

    <div class="flex flex-col gap-8 py-2">
        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="max-w-2xl space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t(run.source_label) }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                >
                    {{ t('Discovery run') }}<span class="text-orange">.</span>
                </h1>
                <p
                    v-if="run.params?.terms"
                    class="text-base text-muted-foreground"
                    dir="auto"
                >
                    {{
                        t('Searching for “:terms”', { terms: run.params.terms })
                    }}
                    <span v-if="run.params?.location"
                        >· {{ run.params.location }}</span
                    >
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/discovery">
                    <ArrowLeft class="size-4" />
                    {{ t('All runs') }}
                </Link>
            </Button>
        </header>

        <!-- Running -->
        <section
            v-if="isRunning"
            class="flex flex-col items-center gap-5 rounded-2xl border border-border bg-card px-6 py-14 text-center"
            data-test="running"
        >
            <Spinner class="size-8 text-brand" />
            <div class="space-y-1">
                <p class="text-lg font-semibold rtl:font-bold">
                    {{ t('Alima is searching for leads') }}
                </p>
                <p class="text-sm text-muted-foreground" aria-live="polite">
                    {{ progress?.step ?? t('Starting discovery') }}
                </p>
            </div>
            <div
                class="h-1.5 w-full max-w-sm overflow-hidden rounded-full bg-accent"
                role="progressbar"
                aria-valuemin="0"
                aria-valuemax="100"
                :aria-valuenow="progress?.percent ?? INITIAL_PERCENT"
                :aria-label="t('Discovery run')"
            >
                <div
                    class="h-full rounded-full bg-brand transition-all duration-300 motion-reduce:transition-none"
                    :style="{
                        width: `${progress?.percent ?? INITIAL_PERCENT}%`,
                    }"
                />
            </div>
        </section>

        <!-- Failed -->
        <section
            v-else-if="status === 'failed'"
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
                        run.error_message
                            ? t(run.error_message)
                            : t('We couldn’t complete this discovery run.')
                    }}
                </p>
            </div>
            <Button variant="outline" as-child>
                <Link href="/discovery">
                    <ArrowLeft class="size-4" />
                    {{ t('All runs') }}
                </Link>
            </Button>
        </section>

        <!-- Completed -->
        <template v-else>
            <div class="flex flex-wrap items-center gap-3">
                <Badge variant="success">{{ t(run.status_label) }}</Badge>
                <span class="font-mono text-xs text-muted-foreground">
                    {{ t(':count leads found', { count: run.leads_found }) }}
                </span>
                <span
                    v-if="run.leads_created !== run.leads_found"
                    class="font-mono text-xs text-muted-foreground"
                >
                    {{ t(':count new', { count: run.leads_created }) }}
                </span>
            </div>

            <EmptyState
                v-if="!leads.length"
                :icon="Building2"
                :title="t('No companies matched')"
                :description="
                    t(
                        'This run found no companies. Try broadening your search terms or picking a different source.',
                    )
                "
            />

            <div
                v-else
                class="overflow-hidden rounded-2xl border border-border bg-card"
            >
                <table class="w-full text-sm">
                    <caption class="sr-only">
                        {{
                            t('Companies found by this discovery run')
                        }}
                    </caption>
                    <thead class="bg-accent/50 text-muted-foreground">
                        <tr>
                            <th
                                scope="col"
                                class="px-4 py-3 text-start font-medium"
                            >
                                {{ t('Company') }}
                            </th>
                            <th
                                scope="col"
                                class="hidden px-4 py-3 text-start font-medium sm:table-cell"
                            >
                                {{ t('Company number') }}
                            </th>
                            <th
                                scope="col"
                                class="hidden px-4 py-3 text-start font-medium md:table-cell"
                            >
                                {{ t('Location') }}
                            </th>
                            <th
                                scope="col"
                                class="px-4 py-3 text-end font-medium"
                            >
                                {{ t('Source') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="lead in leads"
                            :key="lead.id"
                            class="border-t border-border"
                            :data-test="`lead-${lead.id}`"
                        >
                            <td class="px-4 py-3">
                                <span
                                    class="font-medium text-foreground"
                                    dir="auto"
                                    >{{ lead.company_name }}</span
                                >
                                <span
                                    v-if="lead.company_status"
                                    class="block text-xs text-muted-foreground"
                                    >{{ lead.company_status }}</span
                                >
                            </td>
                            <td
                                class="hidden px-4 py-3 font-mono text-xs text-muted-foreground sm:table-cell"
                                dir="ltr"
                            >
                                {{ lead.company_number ?? '—' }}
                            </td>
                            <td
                                class="hidden px-4 py-3 text-muted-foreground md:table-cell"
                                dir="auto"
                            >
                                {{ lead.locality ?? '—' }}
                            </td>
                            <td class="px-4 py-3 text-end">
                                <a
                                    v-if="lead.source_url"
                                    :href="lead.source_url"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ t(lead.source_label) }}
                                    <ExternalLink class="size-3" />
                                    <span class="sr-only">{{
                                        t('(opens in a new tab)')
                                    }}</span>
                                </a>
                                <span v-else class="text-muted-foreground">{{
                                    t(lead.source_label)
                                }}</span>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </template>
    </div>
</template>
