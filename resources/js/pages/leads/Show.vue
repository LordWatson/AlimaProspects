<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ExternalLink,
    Mail,
    Phone,
    ShieldAlert,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import ActivityTimeline from '@/components/leads/ActivityTimeline.vue';
import LeadNotes from '@/components/leads/LeadNotes.vue';
import LeadTasks from '@/components/leads/LeadTasks.vue';
import LeadWorkPanel from '@/components/leads/LeadWorkPanel.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    Lead,
    LeadActivity,
    LeadDiscoveryRun,
    LeadNote,
    LeadScore,
    LeadTask,
    PipelineStage,
    TeamMemberOption,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Leads', href: '/leads' },
            { title: 'Lead', href: '/leads' },
        ],
    },
});

const props = defineProps<{
    lead: Lead;
    score: LeadScore | null;
    activities: LeadActivity[];
    notes: LeadNote[];
    tasks: LeadTask[];
    stages: PipelineStage[];
    members: TeamMemberOption[];
    discoveryRun: LeadDiscoveryRun | null;
    can: { work: boolean };
}>();

const { t } = useI18n();
const { date, dateTime, number } = useFormat();

/** The company facts worth showing, with the empty ones left out rather than rendered as dashes. */
const facts = computed(() =>
    [
        { label: 'Industry', value: props.lead.industry },
        {
            label: 'Company number',
            value: props.lead.company_number,
            ltr: true,
        },
        { label: 'Company status', value: props.lead.company_status },
        { label: 'Company type', value: props.lead.company_type },
        { label: 'Team size', value: props.lead.employee_range },
        {
            label: 'Incorporated',
            value: props.lead.incorporated_on
                ? date(props.lead.incorporated_on)
                : null,
        },
        {
            label: 'Location',
            value:
                [props.lead.locality, props.lead.region, props.lead.country]
                    .filter(Boolean)
                    .join(', ') || null,
        },
    ].filter((fact) => fact.value),
);

/** How stale the lead's data is, as the one date a member actually asks about. */
const freshness = computed(
    () => props.lead.enriched_at ?? props.lead.collected_at ?? null,
);

const website = computed(() => {
    const site = props.lead.website;
    if (!site) return null;

    return site.startsWith('http') ? site : `https://${site}`;
});
</script>

<template>
    <Head :title="lead.company_name" />

    <div class="flex flex-col gap-8 py-2">
        <!-- Header -->
        <header
            class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between"
        >
            <div class="max-w-2xl space-y-2">
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t(lead.source_label) }}
                </p>
                <h1
                    class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                    dir="auto"
                >
                    <!-- No brand full stop here: this is a data value, not a statement — a name
                         that already ends in one would read "Acme Ltd..", and under RTL the dot
                         would land on the wrong end. -->
                    {{ lead.company_name }}
                </h1>
                <p
                    v-if="website"
                    class="text-base text-muted-foreground"
                    dir="ltr"
                >
                    <a
                        :href="website"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    >
                        {{ lead.website }}
                        <ExternalLink class="size-3.5" />
                        <span class="sr-only">{{
                            t('(opens in a new tab)')
                        }}</span>
                    </a>
                </p>
            </div>

            <Button variant="outline" as-child>
                <Link href="/leads">
                    <ArrowLeft class="size-4 rtl:rotate-180" />
                    {{ t('All leads') }}
                </Link>
            </Button>
        </header>

        <!-- Compliance flag: business data is preferred, personal data is surfaced not hidden -->
        <div
            v-if="lead.has_personal_data"
            class="flex items-start gap-3 rounded-2xl border border-border bg-card p-5"
            role="note"
            data-test="personal-data"
        >
            <span
                class="flex size-9 shrink-0 items-center justify-center rounded-full bg-warning/12 text-warning"
                aria-hidden="true"
            >
                <ShieldAlert class="size-4" />
            </span>
            <div class="space-y-1">
                <h2 class="text-sm font-semibold text-foreground rtl:font-bold">
                    {{ t('Some of this looks personal') }}
                </h2>
                <p class="text-sm text-muted-foreground">
                    {{
                        t(
                            'At least one contact here looks like a named person rather than a business role. Prefer the role-based contacts when you reach out.',
                        )
                    }}
                </p>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <!-- Main column -->
            <div class="space-y-6">
                <!-- Company facts -->
                <section
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                    data-test="company"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('The company') }}
                    </h2>

                    <dl
                        v-if="facts.length"
                        class="grid gap-x-6 gap-y-3 sm:grid-cols-2"
                    >
                        <div
                            v-for="fact in facts"
                            :key="fact.label"
                            class="space-y-0.5"
                        >
                            <dt class="text-xs text-muted-foreground">
                                {{ t(fact.label) }}
                            </dt>
                            <dd
                                class="text-sm text-foreground"
                                :dir="fact.ltr ? 'ltr' : 'auto'"
                            >
                                {{ fact.value }}
                            </dd>
                        </div>
                    </dl>
                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Alima hasn’t filled this lead in yet — only the company name so far.',
                            )
                        }}
                    </p>

                    <ul
                        v-if="lead.technologies.length"
                        class="flex flex-wrap gap-1.5 border-t border-border pt-4"
                    >
                        <li
                            v-for="tech in lead.technologies"
                            :key="tech"
                            dir="auto"
                        >
                            <!-- Decorative taxonomy, not a state — hence a lilac chip. -->
                            <Badge variant="lilac">{{ tech }}</Badge>
                        </li>
                    </ul>
                </section>

                <!-- Contacts -->
                <section class="space-y-3" data-test="contacts">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Contacts') }}
                    </h2>

                    <div
                        v-if="lead.contacts?.length"
                        class="overflow-hidden rounded-2xl border border-border bg-card"
                    >
                        <table class="w-full text-sm">
                            <caption class="sr-only">
                                {{
                                    t('Contacts found for this company')
                                }}
                            </caption>
                            <thead class="bg-accent/50 text-muted-foreground">
                                <tr>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-start font-medium"
                                    >
                                        {{ t('Contact') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="px-4 py-3 text-start font-medium"
                                    >
                                        {{ t('Reach them') }}
                                    </th>
                                    <th
                                        scope="col"
                                        class="hidden px-4 py-3 text-end font-medium sm:table-cell"
                                    >
                                        {{ t('Email check') }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr
                                    v-for="contact in lead.contacts"
                                    :key="contact.id"
                                    class="border-t border-border"
                                    :data-test="`contact-${contact.id}`"
                                >
                                    <td class="px-4 py-3">
                                        <span
                                            class="font-medium text-foreground"
                                            dir="auto"
                                            >{{
                                                contact.name ??
                                                t('Unnamed contact')
                                            }}</span
                                        >
                                        <span
                                            v-if="contact.title"
                                            class="block text-xs text-muted-foreground"
                                            dir="auto"
                                            >{{ contact.title }}</span
                                        >
                                        <Badge
                                            v-if="contact.is_personal"
                                            variant="outline"
                                            class="mt-1"
                                            >{{ t('Looks personal') }}</Badge
                                        >
                                    </td>
                                    <td
                                        class="space-y-1 px-4 py-3 text-muted-foreground"
                                    >
                                        <a
                                            v-if="contact.email"
                                            :href="`mailto:${contact.email}`"
                                            class="inline-flex items-center gap-1.5 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                            dir="ltr"
                                        >
                                            <Mail class="size-3.5" />
                                            {{ contact.email }}
                                        </a>
                                        <a
                                            v-if="contact.phone"
                                            :href="`tel:${contact.phone}`"
                                            class="inline-flex items-center gap-1.5 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                            dir="ltr"
                                        >
                                            <Phone class="size-3.5" />
                                            {{ contact.phone }}
                                        </a>
                                        <span
                                            v-if="
                                                !contact.email && !contact.phone
                                            "
                                            >—</span
                                        >
                                    </td>
                                    <td
                                        class="hidden px-4 py-3 text-end sm:table-cell"
                                    >
                                        <span
                                            v-if="
                                                contact.email_verification_label
                                            "
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    contact.email_verification_label,
                                                )
                                            }}
                                            <template
                                                v-if="
                                                    contact.email_confidence !=
                                                    null
                                                "
                                            >
                                                ·
                                                <bdi dir="ltr">{{
                                                    number(
                                                        contact.email_confidence,
                                                    )
                                                }}</bdi>
                                            </template>
                                        </span>
                                        <span
                                            v-else
                                            class="text-muted-foreground"
                                            >—</span
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <p
                        v-else
                        class="rounded-2xl border border-dashed border-border bg-card/50 p-5 text-sm text-muted-foreground"
                        data-test="no-contacts"
                    >
                        {{
                            t(
                                'No contacts found yet. Alima looks for role-based addresses on the company’s own pages.',
                            )
                        }}
                    </p>
                </section>

                <!-- The team's own work on the lead (Phase 10b) -->
                <LeadTasks
                    :lead-id="lead.id"
                    :tasks="tasks"
                    :members="members"
                    :can-write="can.work"
                />

                <LeadNotes
                    :lead-id="lead.id"
                    :notes="notes"
                    :can-write="can.work"
                />

                <!-- Timeline -->
                <section class="space-y-4">
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('History') }}
                    </h2>
                    <ActivityTimeline :activities="activities" />
                </section>
            </div>

            <!-- Side column -->
            <aside class="space-y-6">
                <LeadWorkPanel
                    :lead="lead"
                    :stages="stages"
                    :members="members"
                    :can-write="can.work"
                />
                <!-- Fit -->
                <section
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                    data-test="fit"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Fit') }}
                    </h2>

                    <template v-if="score">
                        <div class="flex items-center gap-4">
                            <p
                                class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-accent font-mono text-2xl font-semibold text-foreground tabular-nums"
                                dir="ltr"
                            >
                                <span class="sr-only">{{
                                    t('Fit score :score out of 100', {
                                        score: score.score,
                                    })
                                }}</span>
                                <span aria-hidden="true">{{
                                    score.score
                                }}</span>
                            </p>
                            <div class="min-w-0 space-y-1">
                                <GradeBadge
                                    :grade="score.grade"
                                    :label="t(score.grade_label)"
                                />
                                <p class="text-xs text-muted-foreground">
                                    {{ t(score.grade_description) }}
                                </p>
                            </div>
                        </div>

                        <p
                            v-if="score.explanation"
                            class="text-sm leading-relaxed text-muted-foreground"
                            dir="auto"
                        >
                            {{ score.explanation }}
                        </p>

                        <Button variant="outline" size="sm" as-child>
                            <Link :href="`/leads/${lead.id}/score`">
                                <Sparkles class="size-4" />
                                {{ t('See the full breakdown') }}
                            </Link>
                        </Button>
                    </template>

                    <template v-else>
                        <p class="text-sm text-muted-foreground">
                            {{
                                t(
                                    'Not graded yet. Alima grades a lead once it has been enriched.',
                                )
                            }}
                        </p>
                        <Button variant="outline" size="sm" as-child>
                            <Link :href="`/leads/${lead.id}/score`">
                                <Sparkles class="size-4" />
                                {{ t('Grade this lead') }}
                            </Link>
                        </Button>
                    </template>
                </section>

                <!-- Where this came from -->
                <section
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                    data-test="provenance"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Where this came from') }}
                    </h2>

                    <dl class="space-y-3 text-sm">
                        <div class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Source') }}
                            </dt>
                            <dd>
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
                                <span v-else class="text-foreground">{{
                                    t(lead.source_label)
                                }}</span>
                            </dd>
                        </div>

                        <div v-if="freshness" class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Data last checked') }}
                            </dt>
                            <dd class="text-foreground" dir="auto">
                                <time :datetime="freshness">{{
                                    dateTime(freshness)
                                }}</time>
                            </dd>
                        </div>

                        <div v-if="discoveryRun" class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Found by') }}
                            </dt>
                            <dd>
                                <Link
                                    :href="`/discovery/${discoveryRun.id}`"
                                    class="rounded text-brand underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    {{ t('Discovery run') }}
                                </Link>
                            </dd>
                        </div>
                    </dl>

                    <p class="text-xs text-muted-foreground">
                        {{
                            t(
                                'Every field Alima fills in keeps a note of where it came from.',
                            )
                        }}
                    </p>
                </section>
            </aside>
        </div>
    </div>
</template>
