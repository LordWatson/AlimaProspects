<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowRight, CalendarClock, CircleAlert } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { MyWork } from '@/types';

/*
 * The dashboard's one personal block: what is waiting on the member looking at it.
 *
 * Everything else on that screen is the team's position, which is the right default and also the
 * reason this exists — on a ten-person team there is no other way to find your own four companies
 * in a list of four hundred, and "48 signals waiting" tells a member nothing about whether any of
 * them are theirs.
 *
 * **Two scopes on one card, and it says so.** The four counts are queues and ignore the date
 * filter entirely — a task that went overdue last month is still overdue today. The three figures
 * at the foot are a record of the selected window, under a heading that says so, and are the only
 * things here the filter moves. Printing the two kinds without distinguishing them is how a member
 * comes to read "3 overdue" as "3 overdue in the last 7 days", which is a different and much less
 * alarming claim.
 *
 * A count links somewhere **only when it has a destination of its own**. The leads list has no
 * task filter (see `ListLeadsRequest`), so making all three task counts link to the same unfiltered
 * list would give a screen-reader user three differently-named links to one place, and promise a
 * sighted one a filter that does not exist. The two task figures are therefore plain text, and the
 * list of actual tasks beside them — each one a link to its company — is the way in.
 *
 * Overdue is the only figure that carries a status tone, and it carries a worded "Overdue" badge
 * beside it rather than relying on the colour (WCAG AA; and Signal Orange appears nowhere near it —
 * this is state from end to end, brand-tokens.md §2.1).
 */
const props = defineProps<{ work: MyWork; memberId: number | null }>();

const { t } = useI18n();
const { number, date } = useFormat();

/** The leads list, pre-filtered to this member — the same `assignees[]` the list itself reads. */
const myLeadsHref = computed(() =>
    props.memberId === null ? '/leads' : `/leads?assignees[]=${props.memberId}`,
);

const firstName = computed(() => props.work.member.split(' ')[0] || '');

/**
 * The four queues. A list rather than four blocks of markup, so the grid and the wording stay in
 * step and a fifth queue is one entry.
 *
 * `tone` is only ever set on a figure that is genuinely a problem — an overdue task. The others
 * are inventory, and tinting them would leave nothing for the one that matters to stand out from.
 */
const queues = computed<
    {
        key: string;
        label: string;
        value: number;
        caption: string;
        href: string | null;
        tone: string | null;
    }[]
>(() => [
    {
        key: 'open_leads',
        label: t('Your companies'),
        value: props.work.open_leads,
        caption: t('Assigned to you and still in play'),
        href: myLeadsHref.value,
        tone: null,
    },
    {
        key: 'tasks_overdue',
        label: t('Overdue'),
        value: props.work.tasks_overdue,
        caption: t('Tasks past their date'),
        href: null,
        tone: props.work.tasks_overdue > 0 ? 'text-destructive' : null,
    },
    {
        key: 'tasks_due_today',
        label: t('Due today'),
        value: props.work.tasks_due_today,
        caption: t('Still to do before the day is out'),
        href: null,
        tone: null,
    },
    {
        key: 'signals_waiting',
        label: t('Your signals'),
        value: props.work.signals_waiting,
        caption: t('Trigger events at companies you own'),
        href: '/signals',
        tone: null,
    },
]);

/**
 * The window-scoped half of the card — what the member got through in the selected period, as
 * label-and-number pairs rather than a sentence (see the note on the markup).
 */
const activity = computed(() => [
    { key: 'actions', label: t('Actions'), value: props.work.actions },
    {
        key: 'companies',
        label: t('Companies worked'),
        value: props.work.companies_worked,
    },
    { key: 'approaches', label: t('Approaches'), value: props.work.approaches },
]);

const nothingWaiting = computed(
    () =>
        props.work.open_leads === 0 &&
        props.work.tasks_overdue === 0 &&
        props.work.tasks_due_today === 0 &&
        props.work.signals_waiting === 0,
);
</script>

<template>
    <section
        class="rounded-3xl bg-card p-6 shadow-sm sm:p-8"
        :aria-label="t('Your work')"
        data-test="dashboard-my-work"
    >
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="font-mono text-xs tracking-eyebrow text-brand uppercase rtl:tracking-normal"
                >
                    {{ t('Your work') }}
                </p>
                <h3
                    class="mt-2 text-xl font-semibold tracking-tight rtl:font-bold"
                >
                    {{
                        firstName
                            ? t('What is waiting on you, :name', {
                                  name: firstName,
                              })
                            : t('What is waiting on you')
                    }}
                </h3>
            </div>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="myLeadsHref">
                    {{ t('Your leads') }}
                    <ArrowRight class="size-4 rtl:rotate-180" />
                </Link>
            </Button>
        </div>

        <div class="mt-6 grid gap-6 lg:grid-cols-[1.1fr_1fr]">
            <!--
              The queues. A `Link` where the figure has a destination of its own and a plain `div`
              where it has not — see the component note. The link's `aria-label` names the figure
              label first and the count second, because the two are separate elements and a links
              list read on its own would otherwise be a column of bare integers.

              The focus ring is `ring-inset`: the tiles sit in an `overflow-hidden` grid, which
              would clip an outset one, and the `accent` wash alone is about 1.1:1 against the card
              — a focus state nobody can see.
            -->
            <ul
                class="grid grid-cols-2 gap-px overflow-hidden rounded-2xl bg-border"
            >
                <li v-for="queue in queues" :key="queue.key" class="bg-card">
                    <component
                        :is="queue.href ? Link : 'div'"
                        :href="queue.href ?? undefined"
                        class="flex h-full flex-col gap-1 p-4 sm:p-5"
                        :class="
                            queue.href
                                ? 'transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-inset'
                                : ''
                        "
                        :aria-label="
                            queue.href
                                ? t(':label: :count', {
                                      label: queue.label,
                                      count: number(queue.value),
                                  })
                                : undefined
                        "
                        :data-test="`my-work-${queue.key}`"
                    >
                        <span
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                        >
                            {{ queue.label }}
                        </span>
                        <span
                            class="text-3xl font-bold tracking-tight tabular-nums"
                            :class="queue.tone ?? ''"
                        >
                            {{ number(queue.value) }}
                        </span>
                        <span
                            class="text-xs leading-snug text-muted-foreground"
                        >
                            {{ queue.caption }}
                        </span>
                    </component>
                </li>
            </ul>

            <!-- Next up: the tasks themselves, soonest first, each one a way into the company. -->
            <div class="flex flex-col">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Next up') }}
                </p>

                <ul
                    v-if="work.tasks.length"
                    class="mt-3 divide-y divide-border"
                >
                    <li v-for="task in work.tasks" :key="task.id">
                        <Link
                            :href="`/leads/${task.lead_id}`"
                            class="flex items-start justify-between gap-3 rounded-lg px-1 py-3 transition-colors hover:text-brand focus-visible:ring-[3px] focus-visible:ring-ring"
                        >
                            <span class="min-w-0">
                                <!-- Data, not a translation key: the member's own words. -->
                                <span class="block truncate font-medium">{{
                                    task.title
                                }}</span>
                                <span
                                    class="block truncate text-sm text-muted-foreground"
                                    >{{ task.company }}</span
                                >
                            </span>
                            <span class="flex shrink-0 items-center gap-2">
                                <Badge
                                    v-if="task.overdue"
                                    variant="error"
                                    class="gap-1"
                                >
                                    <CircleAlert aria-hidden="true" />
                                    {{ t('Overdue') }}
                                </Badge>
                                <span
                                    v-if="task.due_at"
                                    class="text-sm text-muted-foreground tabular-nums"
                                    >{{ date(task.due_at) }}</span
                                >
                            </span>
                        </Link>
                    </li>
                </ul>

                <!--
                  Two different empty states, because they mean different things. Nothing assigned
                  at all is a member who has not been given any work; an empty task list on top of
                  a full queue is somebody who is simply up to date.
                -->
                <div
                    v-else
                    class="mt-3 flex flex-1 flex-col items-start justify-center gap-3 rounded-2xl bg-accent/40 p-5"
                >
                    <CalendarClock
                        class="size-5 text-brand"
                        aria-hidden="true"
                    />
                    <p class="text-sm text-muted-foreground">
                        {{
                            nothingWaiting
                                ? t(
                                      "Nothing is assigned to you yet. Pick up a company from your team's list and it will show up here.",
                                  )
                                : t(
                                      'No tasks with a date on them. Add one to a company and the next few will show up here.',
                                  )
                        }}
                    </p>
                    <Button variant="outline" size="sm" as-child>
                        <Link href="/leads">{{ t('Browse leads') }}</Link>
                    </Button>
                </div>
            </div>
        </div>

        <!--
          The only window-scoped figures on the card, and it says which they are. Three
          **label-and-number** figures rather than one assembled sentence: Arabic has six plural
          forms, so a sentence that interpolates three counts cannot be inflected for any of them —
          and the English would read "1 actions". A label beside its number is correct in every
          language and needs no plural rule at all.
        -->
        <div class="mt-6 border-t border-border pt-4">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('In the selected period') }}
            </p>
            <dl class="mt-2 flex flex-wrap gap-x-8 gap-y-2 text-sm">
                <div
                    v-for="figure in activity"
                    :key="figure.key"
                    class="flex items-baseline gap-2"
                >
                    <dt class="text-muted-foreground">{{ figure.label }}</dt>
                    <dd class="font-medium tabular-nums">
                        {{ number(figure.value) }}
                    </dd>
                </div>
            </dl>
        </div>
    </section>
</template>
