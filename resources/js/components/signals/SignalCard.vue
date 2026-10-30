<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ExternalLink, Undo2 } from '@lucide/vue';
import { computed } from 'vue';
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
 * - **No "found by" row.** Which watch produced a signal is plumbing; it belongs on the watch.
 * - **No tinted panel or icon circle** around the suggested approach — an eyebrow, a line of text
 *   and a thin brand rule to anchor it.
 * - **Relevance sits in the header**, beside the chip and the date, because it is the number the
 *   feed is implicitly ordered by rather than a band of its own half-way down. One rendering of
 *   the value, so a screen reader doesn't read it twice.
 *
 * Nothing here can show a contact detail, because a signal holds none (ADR 0011).
 */
const props = defineProps<{
    signal: Signal;
    /** False for a read-only member: they see the card, not the buttons. */
    canAction: boolean;
}>();

const emit = defineEmits<{
    convert: [signal: Signal];
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

const cardTestId = computed(() => `signal-${props.signal.id}`);
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
                    <Badge variant="lilac">{{
                        t(signal.event_type_label)
                    }}</Badge>
                    <span
                        v-if="signal.location"
                        class="text-xs text-muted-foreground"
                        dir="auto"
                        >{{ signal.location }}</span
                    >
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <span
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                        dir="ltr"
                    >
                        <span class="sr-only"
                            >{{ t('Relevance to you') }}:
                        </span>
                        {{ signal.relevance }}/100
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
                        <Button
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
