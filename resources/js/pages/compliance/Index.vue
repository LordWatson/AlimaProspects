<script setup lang="ts">
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import {
    CheckCircle2,
    Download,
    ExternalLink,
    FileText,
    Search,
    ShieldAlert,
    ShieldOff,
} from '@lucide/vue';
import { computed } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type {
    LiaRecord,
    RetentionForm,
    RetentionPolicy,
    RetentionPreview,
} from '@/types';

/*
 * The compliance centre (Phase 15, docs/compliance.md).
 *
 * One screen rather than four, because the four things on it answer one question — is this team
 * allowed to be doing what it is doing, and for how long — and a member who has to visit four
 * pages to find out will visit none. Writing the assessment gets its own screen; everything else
 * is a status or a short form.
 *
 * Two things the screen is careful about:
 *
 *  - **The lawful-basis card states the consequence, not the status.** "Lead discovery is closed"
 *    is what a member needs to read; "Draft" is what a database needs to store.
 *  - **The retention form shows what tonight would cost** before it is saved. Retention is
 *    irreversible, and a number typed into a box with no feedback is how a team deletes a year of
 *    work by accident.
 *
 * Brand: cream page, Ink hero, Deep Teal cards. Every state here is a **status**, so it uses the
 * `status.*` tokens — Signal Orange appears once, as the hero's rule, and never as a warning
 * (brand-tokens.md §2.1).
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Compliance', href: '/compliance' }],
    },
});

const props = defineProps<{
    assessments: LiaRecord[];
    team_market: string;
    retention: RetentionPolicy;
    retention_bounds: { min_days: number; max_days: number };
    retention_preview: RetentionPreview;
    privacy_notice_url: string;
    can: {
        manage_retention: boolean;
        manage_assessment: boolean;
        export: boolean;
        dsar: boolean;
    };
}>();

const { t } = useI18n();
const { date, number } = useFormat();
const page = usePage();

const csrfToken = computed(() => page.props.csrfToken ?? '');

/** The team's own market first — the one that decides whether sourcing is open today. */
const assessments = computed(() =>
    [...props.assessments].sort((a, b) =>
        a.market === props.team_market
            ? -1
            : b.market === props.team_market
              ? 1
              : 0,
    ),
);

const primary = computed(
    () =>
        props.assessments.find((a) => a.market === props.team_market) ??
        props.assessments[0],
);

const retentionForm = useForm<RetentionForm>({
    is_enabled: props.retention.is_enabled,
    uncontacted_lead_days: props.retention.uncontacted_lead_days,
    contacted_lead_days: props.retention.contacted_lead_days,
});

function saveRetention(): void {
    retentionForm.patch('/compliance/retention', { preserveScroll: true });
}

function months(days: number): number {
    return Math.round(days / 30);
}
</script>

<template>
    <Head :title="t('Compliance')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            :eyebrow="t('Compliance')"
            :title="t('Doing this|properly.')"
            :description="
                t(
                    'Your lawful basis, how long you keep prospect data, and the requests people make about it — all enforced by Alima rather than remembered by your team.',
                )
            "
            size="compact"
        >
            <template #actions>
                <Button variant="outline" as-child>
                    <a
                        :href="privacy_notice_url"
                        target="_blank"
                        rel="noopener"
                    >
                        <ExternalLink class="size-4" />
                        {{ t('Privacy notice') }}
                    </a>
                </Button>
            </template>
        </PageHero>

        <!-- Lawful basis ------------------------------------------------------>
        <section class="space-y-4">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <p
                        class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{ t('Lawful basis') }}
                    </p>
                    <h2 class="mt-1 text-lg font-semibold text-foreground">
                        {{ t('Why you are allowed to hold this data') }}
                    </h2>
                </div>

                <Button v-if="can.manage_assessment" as-child>
                    <Link href="/compliance/lawful-basis">
                        <FileText class="size-4" />
                        {{
                            primary?.in_force
                                ? t('Review the assessment')
                                : t('Write the assessment')
                        }}
                    </Link>
                </Button>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <article
                    v-for="assessment in assessments"
                    :key="assessment.market"
                    class="space-y-3 rounded-2xl border border-border bg-card p-6"
                    :data-test="`lia-${assessment.market}`"
                >
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="font-semibold text-foreground">
                                {{ t(assessment.market_label) }}
                            </h3>
                            <p
                                v-if="assessment.market === team_market"
                                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                            >
                                {{ t('Your market') }}
                            </p>
                        </div>

                        <Badge
                            :variant="
                                assessment.in_force ? 'success' : 'warning'
                            "
                        >
                            {{
                                assessment.in_force
                                    ? t('In force')
                                    : assessment.has_expired
                                      ? t('Due for review')
                                      : t(assessment.status_label)
                            }}
                        </Badge>
                    </div>

                    <!-- The consequence, not the status: this is the sentence a member needs. -->
                    <p
                        class="flex items-start gap-2 text-sm"
                        :class="
                            assessment.in_force
                                ? 'text-success'
                                : 'text-warning'
                        "
                    >
                        <component
                            :is="
                                assessment.in_force ? CheckCircle2 : ShieldAlert
                            "
                            class="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>{{ t(assessment.guidance) }}</span>
                    </p>

                    <dl
                        v-if="assessment.id !== null"
                        class="grid grid-cols-2 gap-3 border-t border-border pt-3 text-sm"
                    >
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Signed off') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{
                                    assessment.completed_at
                                        ? date(assessment.completed_at)
                                        : '—'
                                }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs text-muted-foreground">
                                {{ t('Review due') }}
                            </dt>
                            <dd class="font-mono text-xs">
                                {{
                                    assessment.review_due_at
                                        ? date(assessment.review_due_at)
                                        : '—'
                                }}
                            </dd>
                        </div>
                    </dl>

                    <p v-else class="text-sm text-muted-foreground">
                        {{
                            t(
                                'Nothing recorded for this market yet. You only need one for the markets you actually source in.',
                            )
                        }}
                    </p>
                </article>
            </div>
        </section>

        <!-- Retention --------------------------------------------------------->
        <section
            class="space-y-4 rounded-2xl border border-border bg-card p-6"
            data-test="retention"
        >
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Retention') }}
                </p>
                <h2 class="mt-1 text-lg font-semibold text-foreground">
                    {{ t('How long you keep prospect data') }}
                </h2>
                <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                    {{
                        t(
                            'Every night Alima deletes companies nobody ever approached, and strips the personal data from the ones that went quiet. The company record and what you did stay; the contacts, messages and notes do not.',
                        )
                    }}
                </p>
            </div>

            <form class="space-y-5" @submit.prevent="saveRetention">
                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="space-y-2">
                        <Label for="uncontacted">{{
                            t('Delete untouched companies after')
                        }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="uncontacted"
                                v-model.number="
                                    retentionForm.uncontacted_lead_days
                                "
                                type="number"
                                inputmode="numeric"
                                :min="retention_bounds.min_days"
                                :max="retention_bounds.max_days"
                                :disabled="!can.manage_retention"
                                class="max-w-28"
                                data-test="uncontacted-days"
                            />
                            <span class="text-sm text-muted-foreground">
                                {{
                                    t('days (about :count months)', {
                                        count: months(
                                            retentionForm.uncontacted_lead_days,
                                        ),
                                    })
                                }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t(
                                    'No approach, no note, no task, no owner, still in the first column.',
                                )
                            }}
                        </p>
                        <InputError
                            :message="
                                retentionForm.errors.uncontacted_lead_days
                            "
                        />
                    </div>

                    <div class="space-y-2">
                        <Label for="contacted">{{
                            t(
                                'Remove personal data from worked companies after',
                            )
                        }}</Label>
                        <div class="flex items-center gap-2">
                            <Input
                                id="contacted"
                                v-model.number="
                                    retentionForm.contacted_lead_days
                                "
                                type="number"
                                inputmode="numeric"
                                :min="retention_bounds.min_days"
                                :max="retention_bounds.max_days"
                                :disabled="!can.manage_retention"
                                class="max-w-28"
                                data-test="contacted-days"
                            />
                            <span class="text-sm text-muted-foreground">
                                {{
                                    t('days (about :count months)', {
                                        count: months(
                                            retentionForm.contacted_lead_days,
                                        ),
                                    })
                                }}
                            </span>
                        </div>
                        <p class="text-xs text-muted-foreground">
                            {{
                                t('Counted from the last thing that happened.')
                            }}
                        </p>
                        <InputError
                            :message="retentionForm.errors.contacted_lead_days"
                        />
                    </div>
                </div>

                <label
                    class="flex max-w-2xl items-start gap-3 rounded-xl border border-border p-4"
                >
                    <Checkbox
                        v-model="retentionForm.is_enabled"
                        :disabled="!can.manage_retention"
                        data-test="retention-enabled"
                    />
                    <span class="space-y-1">
                        <span class="block text-sm font-medium text-foreground">
                            {{ t('Run the nightly sweep') }}
                        </span>
                        <!-- Said plainly, because a team that switches this off and forgets is
                             holding data it promised not to. -->
                        <span class="block text-xs text-muted-foreground">
                            {{
                                t(
                                    'Turn this off only while you have a reason to hold everything — a legal hold, say. Nothing is removed while it is off.',
                                )
                            }}
                        </span>
                    </span>
                </label>

                <!-- What tonight would cost, under the windows currently saved. -->
                <div
                    class="flex flex-wrap gap-6 rounded-xl bg-muted/50 p-4 text-sm"
                    data-test="retention-preview"
                >
                    <p>
                        <span class="font-mono text-lg font-semibold">{{
                            number(retention_preview.deletable)
                        }}</span>
                        <span class="ms-2 text-muted-foreground">{{
                            t('companies would be deleted tonight')
                        }}</span>
                    </p>
                    <p>
                        <span class="font-mono text-lg font-semibold">{{
                            number(retention_preview.anonymisable)
                        }}</span>
                        <span class="ms-2 text-muted-foreground">{{
                            t('would lose their personal data')
                        }}</span>
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <Button
                        v-if="can.manage_retention"
                        type="submit"
                        :disabled="retentionForm.processing"
                        data-test="save-retention"
                    >
                        {{ t('Save retention policy') }}
                    </Button>

                    <p
                        v-if="retention.last_swept_at"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t(
                                'Last run :when — deleted :deleted, anonymised :anonymised.',
                                {
                                    when: date(retention.last_swept_at),
                                    deleted: retention.last_deleted_count,
                                    anonymised: retention.last_anonymised_count,
                                },
                            )
                        }}
                    </p>
                    <p v-else class="text-xs text-muted-foreground">
                        {{ t('The sweep has not run for your team yet.') }}
                    </p>
                </div>
            </form>
        </section>

        <!-- Rights and export ------------------------------------------------>
        <section class="grid gap-4 md:grid-cols-2">
            <article
                v-if="can.dsar"
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-6"
            >
                <Search class="size-5 text-teal" aria-hidden="true" />
                <h2 class="font-semibold text-foreground">
                    {{ t('Someone has asked about their data') }}
                </h2>
                <p class="flex-1 text-sm text-muted-foreground">
                    {{
                        t(
                            'Find everything your team holds about one person, then erase it. Searching and erasing are both recorded.',
                        )
                    }}
                </p>
                <Button variant="outline" class="self-start" as-child>
                    <Link href="/compliance/requests">
                        {{ t('Handle a request') }}
                    </Link>
                </Button>
            </article>

            <article
                v-if="can.export"
                class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-6"
            >
                <Download class="size-5 text-teal" aria-hidden="true" />
                <h2 class="font-semibold text-foreground">
                    {{ t('Take your data with you') }}
                </h2>
                <p class="flex-1 text-sm text-muted-foreground">
                    {{
                        t(
                            'Every company, contact, message and note your team holds, as spreadsheets in one zip file.',
                        )
                    }}
                </p>
                <!-- A real browser form POST, not an Inertia visit: the response is a file, and
                     an XHR response cannot reach the user's disk. POST rather than GET because the
                     export is metered and audited, and a GET would be prefetched by the browser. -->
                <form method="post" action="/compliance/export">
                    <input type="hidden" name="_token" :value="csrfToken" />
                    <Button
                        type="submit"
                        variant="outline"
                        data-test="export-data"
                    >
                        <Download class="size-4" />
                        {{ t('Download export') }}
                    </Button>
                </form>
            </article>
        </section>

        <!-- The do-not-contact list lives in Outreach; linked, not duplicated. -->
        <p class="text-sm text-muted-foreground">
            {{ t('Looking for the do-not-contact list?') }}
            <Link
                href="/outreach/do-not-contact"
                class="inline-flex items-center gap-1 font-medium text-teal underline-offset-2 hover:underline"
            >
                <ShieldOff class="size-3.5" aria-hidden="true" />
                {{ t('It is under Outreach.') }}
            </Link>
        </p>
    </div>
</template>
