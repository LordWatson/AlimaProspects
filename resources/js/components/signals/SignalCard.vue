<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Building2, ChevronDown, ExternalLink, Info, Undo2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { Signal } from '@/types';

/*
 * One signal in the feed.
 *
 * The card leads with the **event**, not with a person: that is what makes a signal a signal
 * rather than a contact record. Then the people and the money, then where it came from, then what
 * to do about it.
 *
 * Colour discipline (CLAUDE.md §2a, brand-tokens.md §2.1): the surface is `bg-card` on the cream
 * canvas and **the event-type chip is the card's one accent**. Signal Orange appears nowhere; it
 * is decorative only and never a status colour.
 *
 * Deliberately *not* on the card, after a brand review found it over-busy — which is the plan's
 * own risk note ("lead with cream, favour fewer colours"):
 *
 * - **No Converted/Dismissed badge.** The tab the member is on already says it. A dismiss reason
 *   is still shown, as plain muted text, because that is something the tab doesn't carry.
 * - **No "found by" row on the face of the card.** Which watch produced a signal is plumbing. It
 *   moved *inside* S12's "why you're seeing this" disclosure, where somebody debugging a feed
 *   wants it first and nobody else has to look at it.
 * - **No tinted panel or icon circle** around the suggested approach — an eyebrow, a line of text
 *   and a thin brand rule to anchor it.
 * - **Relevance sits in the header**, beside the chip and the date, because it is the number the
 *   feed is implicitly ordered by rather than a band of its own half-way down. One rendering of
 *   the value, so a screen reader doesn't read it twice.
 *
 * Nothing here can show a contact detail, because a signal holds none (ADR 0011).
 *
 * **Matched cards (S10).** When the event is about a company the team already has, the primary
 * action changes from "Convert to lead" — which would make a second copy — to "Add to Acme Ltd".
 * Three rules, all of them from this phase's own risk note (a wrong match is the way account
 * monitoring fails):
 *
 *  - The match is **named and explained** in words, never only implied by the button's label.
 *  - The weaker match says it is weaker, in text, not in a colour.
 *  - **Convert anyway** stays on the card as a real button, because the member is the one who
 *    knows whether two companies with the same name are the same company.
 *
 * **The score, and the panel behind it (S12).** The header carries the **heat** — what this event
 * is worth to this team — in place of the raw relevance it used to show, because heat is now the
 * number the feed is ordered by and relevance is one of the eight things that make it up. Three
 * decisions there:
 *
 *  - **A neutral scale, never a status colour** (§2a). Heat is a magnitude, not a state: the meter
 *    is a `bg-muted` track with a `bg-foreground/70` fill, so it carries no hue at all. Signal
 *    Orange appears nowhere, and a `status.*` token would have been wrong even in the right
 *    colour — nothing here is reporting that something is fine or broken.
 *  - **An unscored signal shows relevance instead**, labelled as relevance. A signal found before
 *    the scorer existed has `heat: null`, and showing that as zero would read as "we judged this
 *    and it is worthless" rather than "we have not judged it".
 *  - **"Why you're seeing this" is a disclosure**, closed by default, carrying the whole breakdown
 *    including the components the scorer could **not** judge. A ranking a member cannot interrogate
 *    is worse than date order, and the unjudged rows are half the explanation: "no amount given"
 *    tells somebody the article was thin, not that the deal was small.
 *
 * **Clustered cards (S11).** One card is one *event*, however many outlets reported it, so the card
 * says "Reported by 6 sources" and opens to the list. Three decisions in that one line:
 *
 *  - It is a **disclosure, not a list**. The coverage is reassurance that the event is real, not
 *    something to read — so it costs one line until somebody asks, and it is a `<button>` with
 *    `aria-expanded` rather than a hover trick.
 *  - **No badge and no colour.** The card's one accent is still the lilac type chip
 *    (brand-tokens.md §2.1); a count is information, not a status.
 *  - The source row above it keeps naming **this** article's publisher, because that is the link
 *    the headline and summary came from. The disclosure is the rest of the coverage, including this
 *    one, so a member can see at a glance that the two agree.
 */
const props = defineProps<{
    signal: Signal;
    /** False for a read-only member: they see the card, not the buttons. */
    canAction: boolean;
}>();

const emit = defineEmits<{
    convert: [signal: Signal];
    /** Accept the match: the event joins a lead that already exists (S10). */
    attach: [signal: Signal];
    dismiss: [signal: Signal];
    restore: [signal: Signal];
}>();

const { t } = useI18n();
const { currency, date } = useFormat();

/** The deal size, when the article gave one. Minor units in its own currency. */
const amount = computed(() => {
    if (props.signal.amount_minor === null || props.signal.currency === null) {
        return null;
    }

    return currency(props.signal.amount_minor, props.signal.currency);
});

/** "Jane Founder · Founder & CEO · Acme DMCC" — whichever of the three the article named. */
const subject = computed(() =>
    [
        props.signal.subject_person_name,
        props.signal.subject_person_role,
        props.signal.subject_company_name,
    ]
        .filter(Boolean)
        .join(' · '),
);

const isNew = computed(() => props.signal.status === 'new');
const isDismissed = computed(() => props.signal.status === 'dismissed');
const isConverted = computed(() => props.signal.status === 'converted');

/**
 * The lead this event appears to be about — present only when the matcher was sure enough to say
 * so, which means a card either offers "add to it" or does not mention it at all.
 */
const matched = computed(() => props.signal.matched_lead);

const matchedHref = computed(() => `/leads/${matched.value?.id ?? ''}`);

/**
 * The rest of the coverage of this event (S11), or null when the card stands alone — which is both
 * an unclustered signal and a cluster of one, because "reported by 1 source" would be noise on most
 * of the feed.
 */
const cluster = computed(() => props.signal.cluster);

/** Closed by default: the coverage is reassurance, not something to read. */
const showSources = ref(false);

/**
 * The score's working (S12), or null on a signal that has never been scored.
 *
 * Read off the server's stored breakdown rather than recomputed here, which is what lets the panel
 * explain the score the feed was actually ranked by.
 */
const explanation = computed(() => props.signal.explanation);

/** Closed by default too: most cards are never questioned, and the ones that are, are. */
const showExplanation = ref(false);

const explanationId = computed(() => `signal-why-${props.signal.id}`);

/**
 * The number in the header: heat where there is one, relevance where there is not.
 *
 * The label changes with it, because telling a member "Score 70" when the 70 is a relevance would
 * be a quiet lie about what the feed did with this card.
 */
const headline = computed(() =>
    props.signal.heat === null
        ? { label: 'Relevance to you', value: props.signal.relevance }
        : { label: 'Score', value: props.signal.heat },
);

const sourcesId = computed(() => `signal-sources-${props.signal.id}`);

const cardTestId = computed(() => `signal-${props.signal.id}`);
const attachTestId = computed(() => `attach-${props.signal.id}`);
const convertTestId = computed(() => `convert-${props.signal.id}`);
const dismissTestId = computed(() => `dismiss-${props.signal.id}`);
const restoreTestId = computed(() => `restore-${props.signal.id}`);
const leadHref = computed(() => `/leads/${props.signal.lead?.id ?? ''}`);
</script>

<template>
    <Card as="article" :data-test="cardTestId">
        <CardContent class="flex flex-col gap-4">
            <!-- Event type, place, relevance, date. The chip is the card's one accent; relevance
                 belongs here because it is the number the feed is ordered by. -->
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="flex flex-wrap items-center gap-2">
                    <!-- The type's name. Translated only when it is Alima's own wording: once a
                         team renames their type the name is theirs, in whatever language they wrote
                         it, and looking it up in a translation file would overwrite what they typed
                         (S7, ADR 0018). -->
                    <!-- `Badge` is `whitespace-nowrap shrink-0 overflow-hidden`, which was fine
                         for three fixed labels and is not for a name a team typed: it would clip
                         with no indication on a narrow card. Truncated with the full name in
                         `title`, so nothing is lost. -->
                    <Badge
                        variant="lilac"
                        class="max-w-full truncate"
                        dir="auto"
                        :title="
                            signal.event_label_translatable
                                ? t(signal.event_label)
                                : signal.event_label
                        "
                        >{{
                            signal.event_label_translatable
                                ? t(signal.event_label)
                                : signal.event_label
                        }}</Badge
                    >
                    <span
                        v-if="signal.location"
                        class="text-xs text-muted-foreground"
                        dir="auto"
                        >{{ signal.location }}</span
                    >
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="inline-flex items-center gap-1.5 text-xs text-muted-foreground"
                    >
                        <!-- **Visible, not `sr-only`.** The label is the whole reason an unscored
                             card is honest: it shows relevance and says "relevance", where a bare
                             "70/100" would be indistinguishable from a score. Outside the
                             `dir="ltr"` span, so an Arabic label is not isolated as LTR. -->
                        <span dir="auto">{{ t(headline.label) }}</span>
                        <span
                            class="inline-flex items-center gap-2 font-mono tabular-nums"
                            dir="ltr"
                        >
                            {{ headline.value }}/100
                            <!-- A neutral magnitude bar: no hue, no status token, no Orange (§2a).
                                 `aria-hidden` because the number beside it already says this, and
                                 a screen reader reading it twice is worse than not drawing it.
                                 `bg-border` rather than `bg-muted` for the track: in dark mode
                                 `--muted` equals `--card`, and a track you cannot see is not a
                                 scale. -->
                            <span
                                class="h-1 w-10 overflow-hidden rounded-full bg-border"
                                aria-hidden="true"
                            >
                                <span
                                    class="block h-full rounded-full bg-foreground/70"
                                    :style="{ width: `${headline.value}%` }"
                                />
                            </span>
                        </span>
                    </span>
                    <time
                        v-if="signal.published_at"
                        class="text-xs text-muted-foreground"
                        :datetime="signal.published_at"
                    >
                        {{ date(signal.published_at) }}
                    </time>
                </div>
            </div>

            <!-- The event. The headline is the article's own words, so no brand full stop. -->
            <div class="space-y-2">
                <h2
                    class="text-lg font-semibold text-foreground rtl:font-bold"
                    dir="auto"
                >
                    {{ signal.headline }}
                </h2>
                <p
                    class="line-clamp-3 text-sm text-muted-foreground"
                    dir="auto"
                >
                    {{ signal.summary }}
                </p>
            </div>

            <!-- Who, and how much — one line rather than a two-column grid. -->
            <p
                v-if="subject || amount"
                class="text-sm text-foreground"
                dir="auto"
            >
                <span v-if="subject">{{ subject }}</span>
                <span v-if="subject && amount" aria-hidden="true"> · </span>
                <span v-if="amount" class="font-mono tabular-nums" dir="ltr">{{
                    amount
                }}</span>
            </p>

            <!-- This is about somebody you already know.

                 **A neutral panel, not a status tint.** It was `status.info` for one draft and the
                 brand review was right about it twice: the card's one accent is the lilac type chip
                 (brand-tokens.md §2.1) and a blue panel beside a brand rule and a teal button is a
                 third hue; and `status.info` is a *status* colour, which this is not reporting —
                 it is a fact about the card. (It is also still pending sign-off, §7.6/§7f.4, and a
                 customer surface is the wrong place to spend a pending token.) The name is a link,
                 so a member can check who Alima means before accepting it. -->
            <div
                v-if="matched"
                class="flex flex-wrap items-start gap-x-2 gap-y-1 rounded-2xl border border-border bg-accent/60 p-3 text-sm text-foreground"
                :data-test="`matched-${signal.id}`"
            >
                <Building2
                    class="mt-0.5 size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
                <p dir="auto">
                    <template v-if="matched.company_name">
                        {{ t('About a lead you already have:') }}
                        <Link
                            :href="matchedHref"
                            class="rounded font-semibold underline decoration-dotted underline-offset-4 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none rtl:font-bold"
                            >{{ matched.company_name }}</Link
                        >
                    </template>
                    <template v-else>
                        {{ t('About a lead you already have.') }}
                    </template>
                    <!-- `text-foreground`, not muted: on a tinted panel the words stay at full
                         contrast (§7f.3), and on the weaker match this sentence is the caution. -->
                    <span v-if="matched.confidence_explanation">
                        {{ t(matched.confidence_explanation) }}
                    </span>
                </p>
            </div>

            <!-- Why it was cleared away, and how long it has left. Plain text: the tab already
                 says it was dismissed. The countdown is the promise compliance.md §5a makes to the
                 team — kept visible so retention never deletes something they assumed was safe. -->
            <p v-if="isDismissed" class="text-xs text-muted-foreground">
                <template v-if="signal.dismiss_reason_label">
                    {{ t('Dismissed as') }}
                    {{ t(signal.dismiss_reason_label) }}
                </template>
                <template
                    v-if="
                        signal.dismiss_reason_label &&
                        signal.deletes_in_days !== null
                    "
                    >·
                </template>
                <template v-if="signal.deletes_in_days !== null">
                    {{
                        t('Deletes in :days days', {
                            days: signal.deletes_in_days,
                        })
                    }}
                </template>
            </p>

            <!-- What to do next. Always LinkedIn or a referral — never cold email (UAE PDPL).
                 A thin brand rule anchors it; no tint, no icon circle. -->
            <div
                v-if="signal.suggested_approach"
                class="border-s-2 border-brand ps-3"
            >
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Suggested approach') }}
                </p>
                <p class="text-sm text-foreground" dir="auto">
                    {{ signal.suggested_approach }}
                </p>
            </div>

            <!-- Why this card is here, and why it is this far up the feed. A disclosure, because
                 most cards are never questioned and the ones that are, are. -->
            <div v-if="explanation" :data-test="`why-${signal.id}`">
                <!-- `py-1` and `rounded-sm`: a 20px-tall target is under WCAG 2.5.8's 24px
                     minimum, and bare `rounded` is Tailwind's 4px rather than a brand radius. -->
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-sm py-1 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="showExplanation"
                    :aria-controls="explanationId"
                    @click="showExplanation = !showExplanation"
                >
                    <Info class="size-4" aria-hidden="true" />
                    {{ t('Why you’re seeing this') }}
                    <ChevronDown
                        class="duration-fast size-4 transition-transform ease-brand motion-reduce:transition-none"
                        :class="showExplanation ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </button>

                <div
                    v-show="showExplanation"
                    :id="explanationId"
                    class="mt-2 space-y-3 border-s border-border ps-3"
                >
                    <!-- The breakdown. Every component, including the ones Alima could not judge:
                         "no amount given" tells a member the article was thin, not that the deal
                         was small, and dropping those rows would turn half the explanation into a
                         shorter list. -->
                    <dl class="space-y-2">
                        <div
                            v-for="component in explanation.components"
                            :key="component.key"
                            class="space-y-0.5 text-sm"
                        >
                            <div
                                class="flex flex-wrap items-baseline justify-between gap-x-3"
                            >
                                <dt class="text-muted-foreground">
                                    {{ t(component.label) }}
                                </dt>
                                <!-- A flex row with its own gap, rather than a margin on the
                                     evidence: `ms-2` inside a `dir="ltr"` parent becomes
                                     margin-right once `dir="auto"` flips an Arabic value, which
                                     puts the gap on the wrong side and collapses it. -->
                                <dd
                                    class="flex flex-wrap items-baseline gap-2 text-xs"
                                    :class="
                                        component.assessed
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    "
                                >
                                    <span
                                        v-if="component.assessed"
                                        class="font-mono tabular-nums"
                                        dir="ltr"
                                        >{{ component.value }}/100</span
                                    >
                                    <!-- Never an empty cell: an unjudged component with no reason
                                         still has to say that it was not judged. -->
                                    <span v-else>{{ t('Not judged') }}</span>
                                    <!-- Translated only where the backend says the sentence is
                                         **ours**. A figure out of the row ("$40m, higher than 80%
                                         of the deals you have seen") is rendered as given; a
                                         reason a component could not be judged ("No amount
                                         given.") is a key, because the breakdown is *stored* and
                                         an English sentence written into it today would reach an
                                         Arabic card untranslated for as long as that signal
                                         lives. -->
                                    <span
                                        v-if="component.evidence"
                                        class="text-muted-foreground"
                                        dir="auto"
                                        >{{
                                            component.evidence_translatable
                                                ? t(component.evidence)
                                                : component.evidence
                                        }}</span
                                    >
                                </dd>
                            </div>
                            <!-- Visible rather than a `title`. These sentences *are* the "why",
                                 and a tooltip is unreachable by keyboard and by touch — so the
                                 disclosure would have hidden its best content from everybody not
                                 using a mouse. -->
                            <p class="text-xs text-muted-foreground">
                                {{ t(component.explanation) }}
                            </p>
                        </div>
                    </dl>

                    <!-- Where it came from and what the law says about approaching it. The
                         restriction belongs next to the reasoning: somebody reading why this card
                         is here should also be reading how they may act on it (ADR 0019). -->
                    <p class="text-xs text-muted-foreground" dir="auto">
                        {{ t('Found by') }}:
                        <span v-if="explanation.watch">{{
                            explanation.watch.name
                        }}</span>
                        <span v-else>{{
                            t('a watch you have since removed')
                        }}</span>
                        ·
                        {{
                            explanation.definition.name_translatable
                                ? t(explanation.definition.name)
                                : explanation.definition.name
                        }}
                        <span v-if="explanation.definition.version"
                            >(v{{ explanation.definition.version }})</span
                        >
                        <template v-if="explanation.jurisdiction">
                            · {{ explanation.jurisdiction }}
                        </template>
                        <template v-if="explanation.read_full_article">
                            · {{ t('read in full') }}
                        </template>
                        <template v-else>
                            · {{ t('from the headline and snippet') }}
                        </template>
                    </p>
                </div>
            </div>

            <!-- Who else reported it. A disclosure, so it costs one line until somebody asks. -->
            <div v-if="cluster" :data-test="`sources-${signal.id}`">
                <button
                    type="button"
                    class="inline-flex items-center gap-1 rounded-sm py-1 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :aria-expanded="showSources"
                    :aria-controls="sourcesId"
                    @click="showSources = !showSources"
                >
                    <!-- Count-neutral wording: Arabic needs dual and plural forms a single
                         ":count sources" string cannot give. -->
                    {{
                        t('Reported by several sources: :count', {
                            count: cluster.sources_count,
                        })
                    }}
                    <ChevronDown
                        class="duration-fast size-4 transition-transform ease-brand motion-reduce:transition-none"
                        :class="showSources ? 'rotate-180' : ''"
                        aria-hidden="true"
                    />
                </button>

                <ul
                    v-show="showSources"
                    :id="sourcesId"
                    class="mt-2 space-y-1 border-s border-border ps-3"
                >
                    <li
                        v-for="source in cluster.sources"
                        :key="source.id"
                        class="flex flex-wrap items-baseline gap-x-2 text-sm"
                    >
                        <a
                            :href="source.source_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="inline-flex items-center gap-1 rounded text-muted-foreground hover:text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            dir="auto"
                        >
                            {{ source.source_name ?? t('Read the article') }}
                            <ExternalLink class="size-3" aria-hidden="true" />
                            <span class="sr-only">{{
                                t('(opens in a new tab)')
                            }}</span>
                        </a>
                        <time
                            v-if="source.published_at"
                            class="text-xs text-muted-foreground"
                            :datetime="source.published_at"
                        >
                            {{ date(source.published_at) }}
                        </time>
                    </li>
                </ul>
            </div>

            <!-- Source + actions. One primary action per card. -->
            <div
                class="flex flex-wrap items-center justify-between gap-x-4 gap-y-3 border-t border-border pt-4"
            >
                <a
                    :href="signal.source_url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 rounded text-sm text-muted-foreground hover:text-brand hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    dir="auto"
                >
                    {{ signal.source_name ?? t('Read the article') }}
                    <ExternalLink class="size-3" aria-hidden="true" />
                    <span class="sr-only">{{ t('(opens in a new tab)') }}</span>
                </a>

                <div class="flex flex-wrap items-center gap-2">
                    <!-- Converted: the only thing left is to go and work the lead. -->
                    <Button
                        v-if="isConverted && signal.lead"
                        variant="outline"
                        as-child
                    >
                        <Link :href="leadHref">
                            {{ t('View lead') }}
                        </Link>
                    </Button>
                    <p
                        v-else-if="isConverted"
                        class="text-sm text-muted-foreground"
                    >
                        {{ t('The lead this created has since been deleted.') }}
                    </p>

                    <template v-if="canAction && isNew">
                        <Button
                            variant="ghost"
                            :data-test="dismissTestId"
                            @click="emit('dismiss', signal)"
                        >
                            {{ t('Dismiss') }}
                        </Button>

                        <!-- Matched: adding it to the lead is the primary action, and converting
                             is still there for the member who thinks Alima has the wrong Acme.
                             Converting is the destructive-by-accident one here — it makes a
                             duplicate — so it is the quiet button, not the loud one. -->
                        <template v-if="matched">
                            <Button
                                variant="outline"
                                :data-test="convertTestId"
                                @click="emit('convert', signal)"
                            >
                                {{ t('Convert anyway') }}
                            </Button>
                            <Button
                                :data-test="attachTestId"
                                @click="emit('attach', signal)"
                            >
                                {{
                                    matched.company_name
                                        ? t('Add to :company', {
                                              company: matched.company_name,
                                          })
                                        : t('Add to the lead')
                                }}
                            </Button>
                        </template>

                        <Button
                            v-else
                            :data-test="convertTestId"
                            @click="emit('convert', signal)"
                        >
                            {{ t('Convert to lead') }}
                        </Button>
                    </template>

                    <Button
                        v-if="canAction && isDismissed"
                        variant="outline"
                        :data-test="restoreTestId"
                        @click="emit('restore', signal)"
                    >
                        <Undo2
                            class="size-4 rtl:rotate-180"
                            aria-hidden="true"
                        />
                        {{ t('Bring it back') }}
                    </Button>
                </div>
            </div>
        </CardContent>
    </Card>
</template>
