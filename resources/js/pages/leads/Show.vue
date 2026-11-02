<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowLeft,
    ExternalLink,
    Mail,
    Newspaper,
    Phone,
    RefreshCw,
    Scale,
    Send,
    ShieldAlert,
    Sparkles,
} from '@lucide/vue';
import { computed } from 'vue';
import CopyButton from '@/components/CopyButton.vue';
import LeadNoticeRow from '@/components/leads/LeadNoticeRow.vue';
import ActivityTimeline from '@/components/leads/ActivityTimeline.vue';
import LeadNotes from '@/components/leads/LeadNotes.vue';
import LeadTasks from '@/components/leads/LeadTasks.vue';
import LeadWorkPanel from '@/components/leads/LeadWorkPanel.vue';
import { ListCell, ListHead, ListRow, ListTable } from '@/components/list';
import MeterBar from '@/components/MeterBar.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import ScoreDial from '@/components/scoring/ScoreDial.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    EnrichmentStatus,
    Lead,
    LeadActivity,
    LeadContact,
    LeadDiscoveryRun,
    LeadNote,
    LeadOrigin,
    LeadScore,
    LeadTask,
    PipelineStage,
    TeamMemberOption,
} from '@/types';

/*
 * One lead, everything Alima knows about it, and everything the team has done with it.
 *
 * What the rebuild changed, and why:
 *
 *  - **The screen opens by answering the five questions somebody opens it with.** The name and a
 *    website used to be all that was above the fold: the grade was in a card down the right-hand
 *    column, the stage and the owner were inside the work panel below that, and how stale the data
 *    was lived at the very bottom of the page. A member deciding whether to pick up the phone had
 *    to scroll to assemble it. It is now one rail across the header: fit, stage, who's on it,
 *    contacts, and the state of the data.
 *  - **The warnings are one block, not two full-width cards** pushing everything down the page.
 *    They also finally include the one that was missing: **enrichment having failed.** A lead with
 *    three empty facts and no explanation reads as a bug in Alima; it is usually a company with no
 *    website, and saying so is the difference between a member moving on and a member filing a
 *    support ticket.
 *  - **The fit score is a dial**, the same one the score screen uses, instead of a number in a grey
 *    square whose scale the reader had to remember.
 *  - **A contact can be copied.** An email address somebody came here to *use* had to be selected
 *    by hand across a table cell, which on a phone is close to impossible. Role-based addresses
 *    also sort to the top, because they are the ones the compliance note tells members to prefer —
 *    saying "prefer the role-based ones" and then listing them in arbitrary order was advice the
 *    screen did not follow itself.
 *  - **The work panel comes first on a phone.** It is laid out by grid placement rather than DOM
 *    order, so the things a member *does* to a lead — move the stage, assign it, tag it — are not
 *    below five sections of reading matter on the screen where that matters most.
 */
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
    /** Where the lead came from when its `source` isn't the whole story (a converted signal). */
    origin: LeadOrigin | null;
    can: { work: boolean };
}>();

const { t } = useI18n();
const { date, dateTime, relative, number } = useFormat();

/** The company facts worth showing, with the empty ones left out rather than rendered as dashes. */
const facts = computed(() =>
    [
        { label: 'Industry', value: props.lead.industry },
        {
            label: 'Company number',
            value: props.lead.company_number,
            ltr: true,
            copyAs: t('company number'),
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

/**
 * The legal limit on approaching this company, when one applies (ADR 0007). Shown, not enforced —
 * Outreach (Phase 11) is what honours it; this is so a member reading the lead today knows before
 * they pick up the phone.
 */
const restriction = computed(() => props.lead.outreach_restriction);

const website = computed(() => {
    const site = props.lead.website;
    if (!site) return null;

    return site.startsWith('http') ? site : `https://${site}`;
});

// Data state reads as neutral information, never as an error — the same mapping the list uses, so
// a lead's data state is one colour wherever a member meets it.
//
// `enriching` is **not** `info`: `status.info` over its own 12% tint is about 4.4:1, under AA at
// badge size, and §7g.2 therefore allows info as a non-text icon only until the token is approved
// (§7.6). An outline badge with a spinner says "in progress" without leaning on the colour at all.
const enrichmentVariant: Record<
    EnrichmentStatus,
    'success' | 'outline' | 'warning'
> = {
    enriched: 'success',
    enriching: 'outline',
    pending: 'outline',
    failed: 'warning',
};

const stageName = computed(
    () =>
        props.lead.stage?.name ??
        props.stages.find((stage) => stage.is_default)?.name ??
        null,
);

const assignees = computed(() =>
    (props.lead.assignees ?? []).map((person) => person.name).join(', '),
);

type Notice = {
    key: string;
    tone: 'warning' | 'info';
    icon: typeof ShieldAlert;
    title: string;
    body: string;
};

/**
 * Whether the data is all here, and what to do if it isn't.
 *
 * It sits **in the company card**, not with the compliance notices: "Alima is still filling this
 * in" is not something to know *before you reach out*, it is an explanation of the empty fields
 * directly below it. A lead with three facts and no explanation reads as a bug in Alima, when it is
 * usually a company with no website — which is the one notice this screen never had.
 */
const dataState = computed<Notice | null>(() => {
    if (props.lead.enrichment_status === 'failed') {
        return {
            key: 'enrichment-failed',
            tone: 'warning',
            icon: RefreshCw,
            title: t('Alima couldn’t fill this lead in'),
            body: t(
                'Usually that means there was no website to read. What you can see is what the source gave us — add anything you know yourself.',
            ),
        };
    }

    if (
        props.lead.enrichment_status === 'pending' ||
        props.lead.enrichment_status === 'enriching'
    ) {
        return {
            key: 'enrichment-running',
            tone: 'info',
            icon: Sparkles,
            title: t('Alima is still filling this in'),
            body: t(
                'Contacts, technologies and the fit grade land here as they are found. It usually takes a minute or two.',
            ),
        };
    }

    return null;
});

/**
 * What a member needs to know before they approach this company — and nothing else, which is why
 * the data state is not in here.
 *
 * `tone` only ever reaches a status token — Signal Orange is decorative and is never a state
 * (brand-tokens.md §2.1) — and the words stay `text-foreground` on the tint, which is the rule
 * §7f.3 wrote down when the status panels were measured.
 */
const notices = computed(() => {
    const items: Notice[] = [];

    if (props.lead.has_personal_data) {
        items.push({
            key: 'personal-data',
            tone: 'warning',
            icon: ShieldAlert,
            title: t('Some of this looks personal'),
            body: t(
                'At least one contact here looks like a named person rather than a business role. Prefer the role-based contacts when you reach out.',
            ),
        });
    }

    if (restriction.value) {
        items.push({
            key: 'restriction',
            tone: 'warning',
            // Scales, not a shield with a tick in it: this is a legal limit on how the company
            // may be approached, and `ShieldCheck` reads as "you are fine to go".
            icon: Scale,
            title: t(restriction.value.label),
            body: t(restriction.value.guidance),
        });
    }

    return items;
});

/**
 * Contacts with the role-based addresses first.
 *
 * `info@` and `sales@` are the ones compliance tells a member to prefer over a named person's
 * address, and the note at the top of the screen says exactly that — so the table puts them where
 * the advice points. Within each group the server's order is kept.
 */
const contacts = computed<LeadContact[]>(() =>
    [...(props.lead.contacts ?? [])].sort(
        (a, b) => Number(b.is_role_based) - Number(a.is_role_based),
    ),
);

const hasRolePreference = computed(
    () =>
        contacts.value.some((contact) => contact.is_role_based) &&
        contacts.value.some((contact) => !contact.is_role_based),
);
</script>

<template>
    <Head :title="lead.company_name" />

    <div class="flex flex-col gap-6 py-2">
        <!--
          Who this is, and the five things a member opens the screen to find out. The composition
          echoes `PageHero` — a tracked mono eyebrow with a right-hand meta, a hairline rule, the
          name — but on the cream card surface rather than Ink, because this is a record rather than
          a destination, and because every hero in the product carries the panda and a screen should
          not carry two (brand-tokens.md §5).
        -->
        <header
            class="space-y-5 rounded-2xl border border-border bg-card p-6"
            data-test="lead-header"
        >
            <div
                class="flex items-center justify-between gap-4 font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                <span class="truncate">{{ t(lead.source_label) }}</span>
                <!-- Relative for reading, exact on hover: "how old is this?" is the question, and
                     "2 March, 14:05" makes the reader answer it themselves. -->
                <span
                    v-if="freshness"
                    class="shrink-0"
                    :title="dateTime(freshness)"
                >
                    {{ t('Checked :when', { when: relative(freshness) }) }}
                </span>
            </div>

            <div class="h-px w-full bg-border" aria-hidden="true" />

            <div
                class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between"
            >
                <div class="min-w-0 space-y-2">
                    <h1
                        class="text-3xl font-semibold tracking-tight text-foreground sm:text-4xl rtl:font-bold"
                        dir="auto"
                    >
                        <!-- No brand full stop here: this is a data value, not a statement — a name
                             that already ends in one would read "Acme Ltd..", and under RTL the dot
                             would land on the wrong end. -->
                        {{ lead.company_name }}
                    </h1>
                    <!-- `dir="ltr"` goes on the link, not the paragraph: a full-width
                         `ltr` block left-aligns the URL under a right-aligned Arabic name. -->
                    <p v-if="website" class="text-base text-muted-foreground">
                        <a
                            :href="website"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            dir="ltr"
                        >
                            {{ lead.website }}
                            <ExternalLink class="size-3.5" />
                            <span class="sr-only">{{
                                t('(opens in a new tab)')
                            }}</span>
                        </a>
                    </p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <!-- The primary action on this screen, and the one the whole pipeline exists
                         to reach. Offered to anyone who may work the lead; the Outreach screen
                         itself decides which channels are open (Phase 11). -->
                    <Button v-if="can.work" as-child>
                        <Link :href="`/leads/${lead.id}/outreach`">
                            <!-- Mirrored, not rotated: a rotated paper plane points backwards. -->
                            <Send class="size-4 rtl:-scale-x-100" />
                            {{ t('Reach out') }}
                        </Link>
                    </Button>
                    <Button variant="outline" as-child>
                        <Link href="/leads">
                            <ArrowLeft class="size-4 rtl:rotate-180" />
                            {{ t('All leads') }}
                        </Link>
                    </Button>
                </div>
            </div>

            <!--
              The rail. Read-only on purpose: the stage, the owner and the labels are *changed* in
              the work panel, and shown here because they are the first thing somebody needs and
              the last thing they should have to scroll for. A summary at the top and the controls
              in one place beats the same control in two.
            -->
            <dl
                class="flex flex-wrap gap-x-8 gap-y-4 border-t border-border pt-5"
                data-test="lead-rail"
            >
                <div class="space-y-1">
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Fit') }}
                    </dt>
                    <dd>
                        <!-- The name has to carry the grade *and* where the link goes. An
                             `aria-label` of "See the full breakdown" alone replaced the badge's
                             own label, so a screen reader was given a link and never the one
                             fact this rail exists to state. -->
                        <Link
                            v-if="score"
                            :href="`/leads/${lead.id}/score`"
                            class="inline-flex rounded-full transition-opacity hover:opacity-80 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :aria-label="
                                t(
                                    ':label, score :score. See the full breakdown',
                                    {
                                        label: t(score.grade_label),
                                        score: score.score,
                                    },
                                )
                            "
                        >
                            <GradeBadge
                                :grade="score.grade"
                                :score="score.score"
                                :label="t(score.grade_label)"
                            />
                        </Link>
                        <span v-else class="text-sm text-muted-foreground">{{
                            t('Not graded')
                        }}</span>
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Stage') }}
                    </dt>
                    <dd>
                        <Badge v-if="stageName" variant="outline" dir="auto">{{
                            stageName
                        }}</Badge>
                        <span v-else class="text-sm text-muted-foreground"
                            >—</span
                        >
                    </dd>
                </div>

                <div class="min-w-0 space-y-1">
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Who’s on it') }}
                    </dt>
                    <dd class="truncate text-sm text-foreground" dir="auto">
                        {{ assignees || t('Nobody') }}
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Contacts') }}
                    </dt>
                    <!-- An anchor rather than a bare figure: the count on its own
                         repeated the section below it, and this is how a member gets there. -->
                    <dd class="font-mono text-sm tabular-nums" dir="ltr">
                        <a
                            v-if="contacts.length"
                            href="#contacts"
                            class="rounded-sm text-brand underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            >{{ number(contacts.length) }}</a
                        >
                        <span v-else class="text-foreground">{{
                            number(0)
                        }}</span>
                    </dd>
                </div>

                <div class="space-y-1">
                    <dt
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Data') }}
                    </dt>
                    <dd>
                        <Badge
                            :variant="enrichmentVariant[lead.enrichment_status]"
                        >
                            <Spinner
                                v-if="lead.enrichment_status === 'enriching'"
                                class="size-3"
                            />
                            {{ t(lead.enrichment_status_label) }}
                        </Badge>
                    </dd>
                </div>
            </dl>
        </header>

        <!--
          What to know before reaching out: the compliance flags, and whether the data is all here.
          One block rather than a stack of full-width cards, each tinted at 12% with a 30% border
          (brand-tokens.md §7f.2) and with the words in `text-foreground`, never in the status
          colour (§7f.3).
        -->
        <section
            v-if="notices.length"
            class="space-y-3 rounded-2xl border border-border bg-card p-6"
            aria-labelledby="notices-heading"
            data-test="lead-notices"
        >
            <!-- Named by its own heading rather than by an `aria-label` repeating it: a region
                 whose name and first heading are the same sentence says it twice. -->
            <h2
                id="notices-heading"
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Before you reach out') }}
            </h2>

            <ul class="space-y-2">
                <li v-for="notice in notices" :key="notice.key">
                    <LeadNoticeRow :notice="notice" />
                </li>
            </ul>
        </section>

        <div class="grid gap-6 lg:grid-cols-[1fr_20rem]">
            <!--
              The side column is **first in the document and second on screen** (grid placement,
              not `order`): on a phone the things a member does to a lead would otherwise sit below
              the facts, the contacts, the tasks, the notes and the whole history.
            -->
            <!-- Unnamed on purpose: the first thing in it is a panel headed "Your workflow",
                 and naming the landmark the same sentence would announce it twice. -->
            <aside class="space-y-6 lg:col-start-2 lg:row-start-1">
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
                            <ScoreDial
                                :score="score.score"
                                :grade="score.grade"
                                :grade-label="t(score.grade_label)"
                            />
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

                <!-- The event: read-only. Why this lead exists at all, when "a provider found it"
                     isn't the whole story — a converted signal and the event behind it. Named for
                     what it shows, so it doesn't read as a second "Where this came from" (which is
                     further down, and is about per-field provenance). The server resolves this
                     through a contract, so the panel renders whatever module filled it in. -->
                <section
                    v-if="origin"
                    class="space-y-4 rounded-2xl border border-border bg-card p-6"
                    data-test="origin"
                >
                    <h2
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('The event') }}
                    </h2>

                    <div class="flex items-start gap-3">
                        <span
                            class="flex size-9 shrink-0 items-center justify-center rounded-full bg-accent text-muted-foreground"
                            aria-hidden="true"
                        >
                            <Newspaper class="size-4" />
                        </span>
                        <div class="min-w-0 space-y-1">
                            <p
                                class="text-sm font-semibold text-foreground rtl:font-bold"
                            >
                                {{ t(origin.label) }}
                            </p>
                            <p
                                v-if="origin.detail"
                                class="text-xs text-muted-foreground"
                            >
                                {{ t(origin.detail) }}
                            </p>
                        </div>
                    </div>

                    <!-- The article's own words, so it is read as quoted, not as Alima's copy. -->
                    <blockquote
                        v-if="origin.headline"
                        class="border-s-2 border-border ps-3 text-sm text-foreground"
                        dir="auto"
                    >
                        {{ origin.headline }}
                    </blockquote>

                    <dl class="space-y-3 text-sm">
                        <div v-if="origin.source_name" class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Published by') }}
                            </dt>
                            <dd class="text-foreground" dir="auto">
                                {{ origin.source_name }}
                            </dd>
                        </div>

                        <div v-if="origin.occurred_at" class="space-y-0.5">
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Published') }}
                            </dt>
                            <dd class="text-foreground" dir="auto">
                                <time :datetime="origin.occurred_at">{{
                                    dateTime(origin.occurred_at)
                                }}</time>
                            </dd>
                        </div>
                    </dl>

                    <div class="flex flex-wrap gap-x-4 gap-y-2 text-sm">
                        <a
                            v-if="origin.url"
                            :href="origin.url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ t('Read the article') }}
                            <ExternalLink class="size-3" aria-hidden="true" />
                            <span class="sr-only">{{
                                t('(opens in a new tab)')
                            }}</span>
                        </a>
                        <Link
                            v-if="origin.href"
                            :href="origin.href"
                            class="rounded text-brand underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        >
                            {{ t('See the signal') }}
                        </Link>
                    </div>
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

            <!-- Main column -->
            <div class="space-y-6 lg:col-start-1 lg:row-start-1">
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

                    <!-- Why the fields below are thin, or still arriving. It belongs here
                         rather than with the compliance notices: it is an explanation of this
                         card, not something to know before reaching out. -->
                    <LeadNoticeRow v-if="dataState" :notice="dataState" />

                    <dl
                        v-if="facts.length"
                        class="grid gap-x-6 gap-y-4 sm:grid-cols-2"
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
                                class="flex items-center gap-1.5 text-sm text-foreground"
                                :dir="fact.ltr ? 'ltr' : 'auto'"
                            >
                                {{ fact.value }}
                                <!-- A registry number is for pasting somewhere else, which is the
                                     only reason anybody reads one. -->
                                <CopyButton
                                    v-if="fact.copyAs && fact.value"
                                    :value="fact.value"
                                    :label="fact.copyAs"
                                />
                            </dd>
                        </div>
                    </dl>
                    <p
                        v-else-if="!dataState"
                        class="text-sm text-muted-foreground"
                    >
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
                <section
                    id="contacts"
                    class="scroll-mt-6 space-y-3"
                    data-test="contacts"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ t('Contacts') }}
                        </h2>
                        <p
                            v-if="hasRolePreference"
                            class="text-xs text-muted-foreground"
                        >
                            {{ t('Role addresses first — prefer those.') }}
                        </p>
                    </div>

                    <ListTable
                        v-if="contacts.length"
                        :caption="t('Contacts found for this company')"
                    >
                        <thead>
                            <tr>
                                <ListHead :label="t('Contact')" />
                                <ListHead :label="t('Reach them')" />
                                <ListHead
                                    :label="t('Email check')"
                                    align="end"
                                    hide="sm"
                                />
                            </tr>
                        </thead>
                        <tbody>
                            <ListRow
                                v-for="contact in contacts"
                                :key="contact.id"
                                :data-test="`contact-${contact.id}`"
                            >
                                <ListCell>
                                    <span
                                        class="font-medium text-foreground"
                                        dir="auto"
                                        >{{
                                            contact.name ?? t('Unnamed contact')
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
                                </ListCell>
                                <ListCell muted class="space-y-1">
                                    <span
                                        v-if="contact.email"
                                        class="flex items-center gap-1"
                                    >
                                        <a
                                            :href="`mailto:${contact.email}`"
                                            class="inline-flex min-w-0 items-center gap-1.5 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                            dir="ltr"
                                        >
                                            <Mail class="size-3.5 shrink-0" />
                                            <span class="truncate">{{
                                                contact.email
                                            }}</span>
                                        </a>
                                        <CopyButton
                                            :value="contact.email"
                                            :label="t('email address')"
                                        />
                                    </span>
                                    <span
                                        v-if="contact.phone"
                                        class="flex items-center gap-1"
                                    >
                                        <a
                                            :href="`tel:${contact.phone}`"
                                            class="inline-flex min-w-0 items-center gap-1.5 rounded text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                            dir="ltr"
                                        >
                                            <Phone class="size-3.5 shrink-0" />
                                            <span class="truncate">{{
                                                contact.phone
                                            }}</span>
                                        </a>
                                        <CopyButton
                                            :value="contact.phone"
                                            :label="t('phone number')"
                                        />
                                    </span>
                                    <span
                                        v-if="!contact.email && !contact.phone"
                                        >—</span
                                    >
                                </ListCell>
                                <ListCell align="end" hide="sm">
                                    <span
                                        v-if="contact.email_verification_label"
                                        class="inline-flex items-center gap-2"
                                    >
                                        <span
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{
                                                t(
                                                    contact.email_verification_label,
                                                )
                                            }}
                                        </span>
                                        <!-- How sure Alima is, as a bar: the figure alone made the
                                             reader compare numbers down the column themselves. -->
                                        <MeterBar
                                            v-if="
                                                contact.email_confidence != null
                                            "
                                            :value="contact.email_confidence"
                                            width="sm"
                                            :label="
                                                t(
                                                    'Confidence :score out of 100',
                                                    {
                                                        score: contact.email_confidence,
                                                    },
                                                )
                                            "
                                        />
                                    </span>
                                    <span v-else class="text-muted-foreground"
                                        >—</span
                                    >
                                </ListCell>
                            </ListRow>
                        </tbody>
                    </ListTable>

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
        </div>
    </div>
</template>
