<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Radar, Search } from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type {
    DiscoveryIcpOption,
    DiscoveryRun,
    DiscoveryRunStatus,
    DiscoverySource,
    LeadSource,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Lead discovery', href: '/discovery' }],
    },
});

const props = defineProps<{
    runs: DiscoveryRun[];
    sources: DiscoverySource[];
    icps: DiscoveryIcpOption[];
    defaults: { limit: number; max_limit: number };
    can: { manage: boolean };
}>();

const { t } = useI18n();

const activeIcp = computed(() => props.icps.find((i) => i.is_active) ?? null);
const firstAvailable = computed(
    () => props.sources.find((s) => s.available)?.value ?? null,
);

const form = useForm<{
    source: LeadSource | null;
    icp_id: number | null;
    terms: string;
    location: string;
    limit: number;
}>({
    source: firstAvailable.value,
    icp_id: null,
    terms: '',
    location: '',
    limit: props.defaults.limit,
});

function submit(): void {
    form.post('/discovery', { preserveScroll: true });
}

const statusVariant: Record<
    DiscoveryRunStatus,
    'success' | 'info' | 'warning' | 'error'
> = {
    completed: 'success',
    running: 'info',
    pending: 'warning',
    failed: 'error',
};
</script>

<template>
    <Head :title="t('Lead discovery')" />

    <div class="flex flex-col gap-8 py-2">
        <!-- Header -->
        <header class="max-w-2xl space-y-2">
            <p
                class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
            >
                {{ t('Lead discovery') }}
            </p>
            <h1
                class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
            >
                {{ t('Find your next leads')
                }}<span class="text-orange">.</span>
            </h1>
            <p class="text-base text-muted-foreground">
                {{
                    t(
                        'Search public sources for companies that match your ideal customer. Alima runs the search in the background and collects the results here.',
                    )
                }}
            </p>
        </header>

        <!-- New discovery form -->
        <Card v-if="can.manage">
            <CardContent>
                <form class="space-y-6" @submit.prevent="submit">
                    <!-- Source picker -->
                    <fieldset class="space-y-3">
                        <legend
                            class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Lead source') }}
                        </legend>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <label
                                v-for="source in sources"
                                :key="source.value"
                                class="flex cursor-pointer flex-col gap-1 rounded-2xl border p-4 transition-colors focus-within:ring-[3px] focus-within:ring-ring"
                                :class="[
                                    form.source === source.value
                                        ? 'border-brand bg-brand/5'
                                        : 'border-border hover:border-brand/40',
                                    !source.available &&
                                        'cursor-not-allowed opacity-60',
                                ]"
                            >
                                <span
                                    class="flex items-center justify-between gap-2"
                                >
                                    <span
                                        class="text-sm font-semibold text-foreground rtl:font-bold"
                                        >{{ t(source.label) }}</span
                                    >
                                    <input
                                        type="radio"
                                        name="source"
                                        class="sr-only"
                                        :value="source.value"
                                        :disabled="!source.available"
                                        :checked="form.source === source.value"
                                        @change="form.source = source.value"
                                    />
                                    <Badge
                                        v-if="!source.available"
                                        variant="outline"
                                        >{{ t('Soon') }}</Badge
                                    >
                                    <span
                                        v-else
                                        aria-hidden="true"
                                        class="flex size-4 shrink-0 items-center justify-center rounded-full border"
                                        :class="
                                            form.source === source.value
                                                ? 'border-brand'
                                                : 'border-input'
                                        "
                                    >
                                        <span
                                            v-if="form.source === source.value"
                                            class="size-2 rounded-full bg-brand"
                                        />
                                    </span>
                                </span>
                                <span class="text-xs text-muted-foreground">{{
                                    t(source.description)
                                }}</span>
                            </label>
                        </div>
                        <InputError :message="form.errors.source" />
                    </fieldset>

                    <div class="grid gap-5 sm:grid-cols-2">
                        <!-- ICP -->
                        <div class="grid gap-2">
                            <Label for="icp">{{
                                t('Ideal customer profile')
                            }}</Label>
                            <select
                                id="icp"
                                v-model="form.icp_id"
                                class="h-9 rounded-md border border-input bg-background px-3 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <option :value="null">
                                    {{
                                        activeIcp
                                            ? t('Active profile (:name)', {
                                                  name: activeIcp.name,
                                              })
                                            : t('No active profile')
                                    }}
                                </option>
                                <option
                                    v-for="icp in icps"
                                    :key="icp.id"
                                    :value="icp.id"
                                >
                                    {{ icp.name }}
                                </option>
                            </select>
                            <InputError :message="form.errors.icp_id" />
                        </div>

                        <!-- Limit -->
                        <div class="grid gap-2">
                            <Label for="limit">{{ t('Maximum leads') }}</Label>
                            <Input
                                id="limit"
                                v-model.number="form.limit"
                                type="number"
                                min="1"
                                :max="defaults.max_limit"
                            />
                            <InputError :message="form.errors.limit" />
                        </div>

                        <!-- Terms -->
                        <div class="grid gap-2">
                            <Label for="terms">{{
                                t('Search terms (optional)')
                            }}</Label>
                            <Input
                                id="terms"
                                v-model="form.terms"
                                :placeholder="
                                    t('Left blank, Alima uses your profile')
                                "
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.terms" />
                        </div>

                        <!-- Location -->
                        <div class="grid gap-2">
                            <Label for="location">{{
                                t('Location (optional)')
                            }}</Label>
                            <Input
                                id="location"
                                v-model="form.location"
                                :placeholder="t('e.g. London, Dubai')"
                                autocomplete="off"
                            />
                            <InputError :message="form.errors.location" />
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <Button
                            type="submit"
                            variant="brand"
                            :disabled="form.processing || !form.source"
                            data-test="start-discovery"
                        >
                            <Search class="size-4" />
                            {{ t('Start discovery') }}
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <!-- Runs list -->
        <section class="space-y-4">
            <h2 class="text-lg font-semibold text-foreground rtl:font-bold">
                {{ t('Recent runs') }}
            </h2>

            <EmptyState
                v-if="!runs.length"
                :icon="Radar"
                :title="t('No discovery runs yet')"
                :description="
                    t(
                        'Start a discovery above and your runs — and the companies they find — will appear here.',
                    )
                "
            />

            <ul v-else class="space-y-2">
                <li v-for="run in runs" :key="run.id">
                    <Link
                        :href="`/discovery/${run.id}`"
                        class="flex flex-wrap items-center gap-4 rounded-2xl border border-border bg-card px-5 py-4 transition-colors hover:border-brand/40 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :data-test="`run-${run.id}`"
                    >
                        <Badge :variant="statusVariant[run.status]">{{
                            t(run.status_label)
                        }}</Badge>
                        <span class="text-sm font-medium text-foreground">{{
                            t(run.source_label)
                        }}</span>
                        <span
                            v-if="run.icp"
                            class="text-sm text-muted-foreground"
                            dir="auto"
                            >{{ run.icp.name }}</span
                        >
                        <span class="flex-1" />
                        <span class="font-mono text-xs text-muted-foreground">
                            {{ t(':count leads', { count: run.leads_found }) }}
                        </span>
                    </Link>
                </li>
            </ul>
        </section>
    </div>
</template>
