<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Check,
    CirclePlay,
    Lightbulb,
    Plus,
    Radar,
    Send,
    Sparkles,
    Star,
} from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PandaPeek from '@/components/PandaPeek.vue';
import ProductShowcase from '@/components/marketing/ProductShowcase.vue';
import Reveal from '@/components/marketing/Reveal.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { useI18n } from '@/composables/useI18n';
import { useReducedMotion } from '@/composables/useReducedMotion';
import { dashboard, login, register } from '@/routes';

/*
 * The landing page, after the Alima Planner homepage: a statement whose last word cycles under a
 * lime marker, live product notices floating beside the lead, the workspace framed on a teal band,
 * a marquee of where the data comes from, a bento of what is inside, a teal proof band, the
 * questions, and one Ink closing card with the page's only peek.
 *
 * Everything on it is true of this product. The reference's pricing, before/after slider and ROI
 * calculator are Planner features with no Prospects equivalent on a public page, so they are not
 * here rather than here with invented numbers.
 */
const { t } = useI18n();
const page = usePage();
const reduced = useReducedMotion();

const startHref = computed(() =>
    page.props.auth.user ? dashboard() : register(),
);

/* --- The cycling word ------------------------------------------------------------------------ */

// Each is its own key, because a translator has to be free to inflect it for the line it ends.
const words = computed(() => [t('lead'), t('signal'), t('match'), t('reply')]);
const wordIndex = ref(0);
const paused = ref(false);
let ticks = 0;
let timer: ReturnType<typeof setInterval> | undefined;

/*
 * Three loops, then it rests on the first word (WCAG 2.2.2: movement that lasts more than five
 * seconds needs a way to stop — this one stops itself). Hovering or focusing the hero pauses it.
 */
onMounted(() => {
    timer = setInterval(() => {
        if (reduced.value || paused.value) {
            return;
        }

        wordIndex.value = (wordIndex.value + 1) % words.value.length;
        ticks += 1;

        if (ticks >= words.value.length * 3) {
            clearInterval(timer);
        }
    }, 2200);
});

onBeforeUnmount(() => clearInterval(timer));

/* --- Content --------------------------------------------------------------------------------- */

const steps = [
    {
        icon: Radar,
        step: '01',
        title: 'Source',
        body: 'Pull in companies that fit from Companies House, Google Places and the open web — deduplicated, attributed and dated as they land.',
    },
    {
        icon: Sparkles,
        step: '02',
        title: 'Qualify',
        body: 'Every lead is graded against your ideal customer profile — part arithmetic, part AI read — with the reasoning written out.',
    },
    {
        icon: Send,
        step: '03',
        title: 'Reach',
        body: 'Drafts built from your business and this prospect, in English or Arabic — checked against the rules before anything sends.',
    },
    {
        icon: Lightbulb,
        step: '04',
        title: 'Learn',
        body: 'Replies, stages and scores roll back into one picture, and the model tells you what to do about it each morning.',
    },
];

/*
 * The bento. `tone` is the tile's surface and `span` its footprint on the four-column grid; on a
 * phone the grid becomes a swipe row and both are ignored.
 */
const tiles: {
    eyebrow: string;
    title: string;
    body: string;
    tone: 'teal' | 'lilac' | 'ink' | 'card' | 'lime';
    span: string;
    /** A small drawing that moves on hover; only the wide tiles carry one. */
    visual?: 'scores' | 'signals' | 'chart' | 'privacy';
}[] = [
    {
        eyebrow: 'Hybrid scoring',
        title: 'One score out of 100, with its reasons.',
        body: 'Your own criteria, weighed exactly as you set them, plus an AI read of the rest — one score out of 100 and a grade you can argue with.',
        tone: 'teal',
        span: 'lg:col-span-2',
        visual: 'scores',
    },
    {
        eyebrow: 'Trigger-event signals',
        title: 'Arrive while it still matters.',
        body: 'Funding rounds, exits and relocations in your market, found in the news, classified and dated — so you arrive while it still matters.',
        tone: 'lilac',
        span: 'lg:col-span-2',
        visual: 'signals',
    },
    {
        eyebrow: 'Ideal customer profiles',
        title: 'Say who you want.',
        body: 'Define who you actually want in weighted criteria and exclusions. Change the definition and everything re-scores behind you.',
        tone: 'card',
        span: '',
    },
    {
        eyebrow: 'Multi-source discovery',
        title: 'Three sources, one run.',
        body: 'Three live sources, one run. Watch it work through them, and stop at the limit your plan sets.',
        tone: 'ink',
        span: '',
    },
    {
        eyebrow: 'Your business, researched',
        title: 'It reads you first.',
        body: 'An AI background check on your own company reads your site and public sources, then fills the blanks you left — never overwriting your words.',
        tone: 'card',
        span: '',
    },
    {
        eyebrow: 'Compliant outreach',
        title: 'Drafted, checked, then sent.',
        body: 'Personalised drafts, reusable briefs, a full contact log — and suppression, sender identity and the opt-out enforced at send time.',
        tone: 'lime',
        span: '',
    },
    {
        eyebrow: 'Insights that act',
        title: 'What to do this morning.',
        body: 'The companies you went quiet on, the strong matches nobody has approached, and the model’s read of your week.',
        tone: 'card',
        span: 'lg:col-span-2',
        visual: 'chart',
    },
    {
        eyebrow: 'Privacy, enforced',
        title: 'The rules are in the software.',
        body: 'Nothing is sourced until your lawful basis is written down, old data clears out every night, and a request to see or erase it is a screen, not a scramble.',
        tone: 'teal',
        span: 'lg:col-span-2',
        visual: 'privacy',
    },
];

const tileTone = {
    teal: 'bg-teal text-cream',
    lilac: 'bg-lilac text-ink',
    ink: 'bg-ink text-cream',
    card: 'bg-card text-foreground shadow-sm',
    lime: 'bg-lime text-ink',
} as const;

const tileEyebrow = {
    teal: 'text-lime',
    lilac: 'text-ink',
    ink: 'text-lime',
    card: 'text-brand',
    lime: 'text-ink',
} as const;

const tileBody = {
    teal: 'text-cream/80',
    lilac: 'text-ink/80',
    ink: 'text-ink-20',
    card: 'text-muted-foreground',
    lime: 'text-ink/80',
} as const;

// Illustrative only: three grades and how far each bar fills when the tile is hovered.
const scoreBars = [
    { grade: 'A', value: 92 },
    { grade: 'B', value: 74 },
    { grade: 'C', value: 51 },
];
const signalChips = ['Funding round', 'Acquisition', 'Relocation'];
const chipTone = ['bg-ink text-cream', 'bg-cream text-ink', 'bg-lime text-ink'];
const chipFan = [
    '-rotate-6 group-hover:-translate-x-[7.5rem] group-hover:-rotate-12',
    'z-10 group-hover:-translate-y-3',
    'rotate-6 group-hover:translate-x-[7.5rem] group-hover:rotate-12',
];
const chartBars = [38, 52, 44, 66, 58, 81, 72, 94];

const sources = [
    'Companies House',
    'Google Places',
    'Open web',
    'News signals',
    'UK GDPR',
    'UAE PDPL',
    'English',
    'العربية',
];
const sparkTones = ['text-lime', 'text-orange', 'text-lilac'];

const faqs = [
    {
        q: 'Where do the leads come from?',
        a: 'Pull in companies that fit from Companies House, Google Places and the open web — deduplicated, attributed and dated as they land.',
    },
    {
        q: 'How is a lead scored?',
        a: 'Your own criteria, weighed exactly as you set them, plus an AI read of the rest — one score out of 100 and a grade you can argue with.',
    },
    {
        q: 'What is a signal?',
        a: 'Funding rounds, exits and relocations in your market, found in the news, classified and dated — so you arrive while it still matters.',
    },
    {
        q: 'Does Alima send emails for me?',
        a: 'Personalised drafts, reusable briefs, a full contact log — and suppression, sender identity and the opt-out enforced at send time.',
    },
    {
        q: 'Does it work in Arabic?',
        a: 'Sources, rules and tone differ by market — so Alima treats them as different, and the whole product speaks English and Arabic, right to left included.',
    },
    {
        q: 'How do you handle personal data?',
        a: 'Nothing is sourced until your lawful basis is written down, old data clears out every night, and a request to see or erase it is a screen, not a scramble.',
    },
];
const openFaq = ref(0);

function toggleFaq(index: number): void {
    openFaq.value = openFaq.value === index ? -1 : index;
}
</script>

<template>
    <Head :title="t('Alima — Marketing brighter together')" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            @pointerenter="paused = true"
            @pointerleave="paused = false"
            @focusin="paused = true"
            @focusout="paused = false"
            class="mx-auto flex w-full max-w-[80rem] flex-col gap-[clamp(1.375rem,3vw,2.125rem)] px-5 pt-[clamp(2.5rem,6vw,5.25rem)] pb-[clamp(2.25rem,4vw,3.5rem)] sm:px-[clamp(1.25rem,5.5vw,5rem)]"
        >
            <p
                class="flex items-center gap-3.5 font-mono text-[0.8125rem] tracking-[0.24em] text-teal uppercase rtl:tracking-normal dark:text-brand"
            >
                <span class="h-0.5 w-7 bg-orange" aria-hidden="true" />
                {{ t('Bold ideas. Real impact.') }}
            </p>

            <!--
              The statement names its whole sentence once for a screen reader, then draws the
              cycling version for the eye: a word that changes every two seconds inside a live
              heading would be read out every two seconds.
            -->
            <h1
                class="text-[clamp(2.75rem,6.6vw,6rem)] leading-[1.04] font-bold tracking-[-0.045em] rtl:leading-tight rtl:tracking-normal"
                :aria-label="`${t('One calm workspace')} ${t('for every')} ${words[0]}.`"
            >
                <span aria-hidden="true">
                    {{ t('One calm workspace') }}<br />{{ t('for every') }}
                    <span class="inline-grid text-start">
                        <!-- The longest word holds the width, so the line never reflows. -->
                        <span
                            v-for="word in words"
                            :key="`sizer-${word}`"
                            class="invisible col-start-1 row-start-1 px-[0.06em] whitespace-nowrap"
                            >{{ word }}.</span
                        >
                        <span
                            :key="wordIndex"
                            class="col-start-1 row-start-1 animate-word justify-self-start whitespace-nowrap"
                            ><span
                                class="bg-[linear-gradient(transparent_56%,var(--color-lime)_56%,var(--color-lime)_90%,transparent_90%)] px-[0.06em] text-teal dark:text-brand"
                                >{{ words[wordIndex] }}</span
                            ><span class="text-orange">.</span></span
                        >
                    </span>
                </span>
            </h1>

            <div class="flex flex-wrap items-end justify-between gap-8">
                <div
                    class="flex max-w-[37.5rem] flex-[1_1_28rem] flex-col gap-6"
                >
                    <p
                        class="text-[clamp(1.125rem,1.5vw,1.3125rem)] leading-[1.55] text-ink-80 dark:text-muted-foreground"
                    >
                        {{
                            t(
                                'Alima finds, qualifies and reaches the businesses most likely to buy — with AI built for the UK and the UAE, bilingual from day one.',
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2.5">
                        <Link
                            :href="startHref"
                            class="inline-flex min-h-14 items-center gap-2.5 rounded-full bg-lime px-7 text-[1.0625rem] font-semibold text-ink transition-[background-color,transform] duration-150 ease-brand hover:-translate-y-0.5 hover:bg-lime-hover focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none active:translate-y-0"
                        >
                            {{ t("Let's grow") }}
                            <ArrowRight class="size-[18px] rtl:rotate-180" />
                        </Link>
                        <a
                            href="#tour"
                            class="inline-flex min-h-14 items-center gap-2.5 rounded-full border-[1.5px] border-foreground px-6.5 text-[1.0625rem] font-semibold text-foreground transition-colors duration-150 hover:bg-foreground hover:text-background focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:outline-none"
                        >
                            <CirclePlay class="size-[18px]" />
                            {{ t('See it working') }}
                        </a>
                    </div>
                </div>

                <!-- Live notices: decoration, so hidden from assistive technology. -->
                <div
                    class="relative hidden h-[14.375rem] w-[29.375rem] shrink-0 lg:block"
                    aria-hidden="true"
                >
                    <div
                        class="absolute start-0 top-0 flex animate-float-in items-center gap-3 rounded-[1.125rem] bg-card px-4 py-3 text-sm leading-snug shadow-pop [--float-delay:200ms]"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-lime text-ink"
                            ><Check class="size-[18px]" stroke-width="2.6"
                        /></span>
                        <span class="flex flex-col">
                            <b class="font-semibold">{{
                                t('New A-grade lead · scored 92')
                            }}</b>
                            <span class="text-xs text-muted-foreground">{{
                                t('Ideal customer match · just now')
                            }}</span>
                        </span>
                    </div>
                    <div
                        class="absolute end-0 top-14 flex animate-float-in items-center gap-3 rounded-[1.125rem] bg-ink px-4 py-3 text-sm leading-snug text-cream shadow-pop [--float-delay:600ms]"
                    >
                        <span
                            class="font-mono text-xl font-medium text-lime"
                            dir="ltr"
                            >AED 40m</span
                        >
                        <span class="flex flex-col">
                            <b class="font-semibold">{{
                                t('Funding round spotted')
                            }}</b>
                            <span class="text-xs text-ink-20">{{
                                t('Signals · Dubai')
                            }}</span>
                        </span>
                    </div>
                    <div
                        class="absolute start-10 top-[7.375rem] flex animate-float-in items-center gap-3 rounded-[1.125rem] bg-card px-4 py-3 text-sm leading-snug shadow-pop [--float-delay:1000ms]"
                    >
                        <span
                            class="flex size-9 items-center justify-center rounded-full bg-teal text-cream"
                            ><Send class="size-4 rtl:-scale-x-100"
                        /></span>
                        <span class="flex flex-col">
                            <b class="font-semibold">{{
                                t('Draft ready for review')
                            }}</b>
                            <span class="text-xs text-muted-foreground">{{
                                t('Outreach · in Arabic')
                            }}</span>
                        </span>
                    </div>
                    <div
                        class="absolute end-[1.125rem] top-[10.75rem] flex animate-float-in items-center gap-2.5 rounded-[1.125rem] bg-lilac px-3.5 py-2.5 text-sm font-semibold text-ink shadow-pop [--float-delay:1400ms]"
                    >
                        <Star class="size-4" />
                        {{ t('5 strong matches nobody has approached') }}
                    </div>
                </div>
            </div>
        </section>

        <!-- The workspace, moving, on a teal band --------------------------------->
        <section
            id="tour"
            class="relative scroll-mt-24 px-3 pb-[clamp(3rem,6vw,5.5rem)] sm:px-[clamp(0.75rem,3vw,3rem)]"
            :aria-label="t('The workspace')"
        >
            <div
                class="absolute inset-x-0 bottom-0 h-3/5 bg-teal"
                aria-hidden="true"
            />
            <Reveal class="relative mx-auto max-w-[84rem]">
                <ProductShowcase />
            </Reveal>
        </section>

        <!-- Marquee --------------------------------------------------------------->
        <section
            class="overflow-hidden bg-ink py-6 text-cream"
            :aria-label="t('Where the data comes from')"
        >
            <div
                class="flex w-max animate-marquee gap-14 px-5 text-[clamp(1.375rem,2.6vw,2.125rem)] font-bold tracking-[-0.02em] whitespace-nowrap motion-reduce:w-auto motion-reduce:animate-none motion-reduce:flex-wrap motion-reduce:gap-x-8 motion-reduce:gap-y-2 rtl:tracking-normal"
                dir="ltr"
            >
                <template v-for="copy in 2" :key="copy">
                    <template v-for="(source, i) in sources" :key="source">
                        <!-- The second copy only exists to make the loop seamless; with motion
                             reduced the strip wraps instead, and shows each name once. -->
                        <span
                            :class="copy === 2 ? 'motion-reduce:hidden' : ''"
                            :aria-hidden="copy === 2 ? 'true' : undefined"
                            >{{ source }}</span
                        >
                        <span
                            :class="[
                                sparkTones[i % 3],
                                copy === 2 ? 'motion-reduce:hidden' : '',
                            ]"
                            aria-hidden="true"
                            >✦</span
                        >
                    </template>
                </template>
            </div>
        </section>

        <!-- How it works ---------------------------------------------------------->
        <section
            id="how"
            class="mx-auto flex w-full max-w-[80rem] scroll-mt-24 flex-col gap-9 px-5 py-[clamp(4rem,8vw,7rem)] sm:px-[clamp(1.25rem,5.5vw,5rem)]"
        >
            <Reveal class="flex flex-wrap items-end justify-between gap-6">
                <div class="flex max-w-[51rem] flex-col gap-5">
                    <p
                        class="flex items-center gap-3.5 font-mono text-[0.8125rem] tracking-[0.24em] text-teal uppercase rtl:tracking-normal dark:text-brand"
                    >
                        <span class="h-0.5 w-7 bg-current" aria-hidden="true" />
                        {{ t('How Alima works') }}
                    </p>
                    <h2
                        class="text-[clamp(2.5rem,5.6vw,4.75rem)] leading-none font-bold tracking-[-0.045em] rtl:leading-tight rtl:tracking-normal"
                    >
                        {{ t('Four steps to your next customer')
                        }}<span class="text-orange">.</span>
                    </h2>
                </div>
            </Reveal>

            <div class="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <Reveal
                    v-for="(s, i) in steps"
                    :key="s.step"
                    :delay="i * 80"
                    class="flex flex-col border-t-[1.5px] border-foreground pt-4"
                >
                    <div class="flex items-center justify-between gap-3">
                        <span class="font-mono text-sm text-brand">{{
                            s.step
                        }}</span>
                        <span
                            class="flex size-11 items-center justify-center rounded-full bg-muted text-brand"
                            aria-hidden="true"
                        >
                            <component :is="s.icon" class="size-5" />
                        </span>
                    </div>
                    <h3
                        class="mt-5 text-2xl font-bold tracking-[-0.03em] rtl:tracking-normal"
                    >
                        {{ t(s.title) }}
                    </h3>
                    <p
                        class="mt-3 text-base leading-relaxed text-ink-80 dark:text-muted-foreground"
                    >
                        {{ t(s.body) }}
                    </p>
                </Reveal>
            </div>
        </section>

        <!-- Bento ----------------------------------------------------------------->
        <section
            id="features"
            class="mx-auto flex w-full max-w-[80rem] scroll-mt-24 flex-col gap-9 px-5 pb-[clamp(4rem,8vw,7rem)] sm:px-[clamp(1.25rem,5.5vw,5rem)]"
        >
            <Reveal class="flex flex-wrap items-end justify-between gap-6">
                <div class="flex max-w-[51rem] flex-col gap-5">
                    <p
                        class="flex items-center gap-3.5 font-mono text-[0.8125rem] tracking-[0.24em] text-teal uppercase rtl:tracking-normal dark:text-brand"
                    >
                        <span class="h-0.5 w-7 bg-current" aria-hidden="true" />
                        {{ t("What's inside") }}
                    </p>
                    <h2
                        class="text-[clamp(2.5rem,5.6vw,4.75rem)] leading-none font-bold tracking-[-0.045em] rtl:leading-tight rtl:tracking-normal"
                    >
                        {{ t('One workspace, every part of the hunt')
                        }}<span class="text-orange">.</span>
                    </h2>
                </div>
                <p
                    class="font-mono text-xs tracking-[0.16em] text-muted-foreground uppercase rtl:tracking-normal"
                >
                    <span class="hidden lg:inline"
                        >{{ t('Hover the tiles') }} ↓</span
                    >
                    <span class="lg:hidden">{{ t('Swipe') }} →</span>
                </p>
            </Reveal>

            <div
                class="-mx-5 flex snap-x snap-mandatory [scrollbar-width:none] gap-3 overflow-x-auto px-5 pb-2 lg:mx-0 lg:grid lg:grid-cols-4 lg:overflow-visible lg:px-0 lg:pb-0 [&::-webkit-scrollbar]:hidden"
            >
                <article
                    v-for="tile in tiles"
                    :key="tile.eyebrow"
                    class="group flex w-[18rem] shrink-0 snap-start flex-col gap-3.5 rounded-[1.75rem] p-7 transition-[transform,box-shadow] duration-300 ease-brand hover:-translate-y-1.5 hover:shadow-lift lg:w-auto"
                    :class="[tileTone[tile.tone], tile.span]"
                >
                    <p
                        class="font-mono text-xs tracking-eyebrow uppercase rtl:tracking-normal"
                        :class="tileEyebrow[tile.tone]"
                    >
                        {{ t(tile.eyebrow) }}
                    </p>
                    <h3
                        class="text-[1.75rem] leading-[1.08] font-bold tracking-[-0.03em] rtl:leading-tight rtl:tracking-normal"
                    >
                        {{ t(tile.title) }}
                    </h3>
                    <p
                        class="text-[0.9375rem] leading-[1.55]"
                        :class="[
                            tileBody[tile.tone],
                            tile.visual ? '' : 'mt-auto',
                        ]"
                    >
                        {{ t(tile.body) }}
                    </p>

                    <!-- Decoration that answers the hover: drawn, so hidden from a screen reader. -->
                    <div
                        v-if="tile.visual"
                        class="mt-auto pt-3"
                        aria-hidden="true"
                    >
                        <!-- Scores: bars fill to their value. -->
                        <div
                            v-if="tile.visual === 'scores'"
                            class="flex flex-col gap-2.5"
                        >
                            <div
                                v-for="bar in scoreBars"
                                :key="bar.grade"
                                class="flex items-center gap-3 text-sm"
                            >
                                <span
                                    class="flex size-7 shrink-0 items-center justify-center rounded-full bg-lime font-mono text-xs font-medium text-ink"
                                    >{{ bar.grade }}</span
                                >
                                <span
                                    class="h-2 flex-1 overflow-hidden rounded-full bg-cream/15"
                                >
                                    <span
                                        class="block h-full w-[14%] rounded-full bg-lime transition-[width] duration-[900ms] ease-brand group-hover:w-(--w) motion-reduce:w-(--w)"
                                        :style="{ '--w': `${bar.value}%` }"
                                    />
                                </span>
                                <span
                                    class="w-8 text-end font-mono text-xs tabular-nums"
                                    dir="ltr"
                                    >{{ bar.value }}</span
                                >
                            </div>
                        </div>

                        <!-- Signals: three chips that fan out. -->
                        <div
                            v-else-if="tile.visual === 'signals'"
                            class="relative flex h-24 items-end justify-center"
                        >
                            <span
                                v-for="(chip, i) in signalChips"
                                :key="chip"
                                class="absolute bottom-0 rounded-2xl px-4 py-3 text-sm font-semibold shadow-pop transition-transform duration-500 ease-brand"
                                :class="[chipTone[i], chipFan[i]]"
                                >{{ t(chip) }}</span
                            >
                        </div>

                        <!-- Insights: a week of bars that rise. -->
                        <div
                            v-else-if="tile.visual === 'chart'"
                            class="flex h-20 items-end gap-2"
                        >
                            <span
                                v-for="(height, i) in chartBars"
                                :key="i"
                                class="flex-1 origin-bottom scale-y-[0.35] rounded-t-md transition-transform duration-500 ease-brand group-hover:scale-y-100 motion-reduce:scale-y-100"
                                :class="
                                    i === chartBars.length - 1
                                        ? 'bg-teal'
                                        : 'bg-teal/25'
                                "
                                :style="{
                                    height: `${height}%`,
                                    transitionDelay: `${i * 40}ms`,
                                }"
                            />
                        </div>

                        <!-- Privacy: the two laws, and a live dot. -->
                        <p
                            v-else-if="tile.visual === 'privacy'"
                            class="flex items-center gap-3 font-mono text-xs text-teal-20"
                        >
                            <span
                                class="size-2 animate-live rounded-full bg-lime"
                            />
                            {{ t('UK GDPR · UAE PDPL') }}
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <!-- Proof ----------------------------------------------------------------->
        <section class="bg-teal text-cream">
            <div
                class="mx-auto flex max-w-[80rem] flex-col gap-12 px-5 py-[clamp(3.5rem,7vw,6rem)] sm:px-[clamp(1.25rem,5.5vw,5rem)]"
            >
                <Reveal
                    class="grid grid-cols-[repeat(auto-fit,minmax(12.5rem,1fr))] gap-6"
                >
                    <div
                        v-for="fact in [
                            {
                                figure: '3',
                                label: 'live lead sources in one run',
                            },
                            {
                                figure: '100',
                                label: 'point score, with its reasons',
                            },
                            {
                                figure: 'UK·UAE',
                                label: 'built for both markets',
                            },
                            {
                                figure: 'EN·AR',
                                label: 'right to left included',
                            },
                        ]"
                        :key="fact.figure"
                        class="flex flex-col gap-1.5 border-t-2 border-cream pt-4"
                    >
                        <span
                            class="text-[clamp(3rem,4.4vw,4rem)] leading-[0.9] font-bold tracking-[-0.04em] whitespace-nowrap text-lime"
                            dir="ltr"
                            >{{ fact.figure }}</span
                        >
                        <span class="text-base text-teal-20">{{
                            t(fact.label)
                        }}</span>
                    </div>
                </Reveal>
                <Reveal class="flex max-w-[61rem] flex-col gap-5">
                    <h2
                        class="text-[clamp(1.75rem,3.6vw,2.875rem)] leading-[1.2] font-light tracking-[-0.02em] rtl:tracking-normal"
                    >
                        {{ t('Built for the UK and the UAE')
                        }}<span class="text-lime">.</span>
                        {{
                            t(
                                'Sources, rules and tone differ by market — so Alima treats them as different, and the whole product speaks English and Arabic, right to left included.',
                            )
                        }}
                    </h2>
                </Reveal>
            </div>
        </section>

        <!-- Questions ------------------------------------------------------------->
        <section id="faq" class="scroll-mt-24 border-t border-border bg-card">
            <div
                class="mx-auto flex max-w-[80rem] flex-col gap-8 px-5 py-[clamp(4rem,8vw,7rem)] sm:px-[clamp(1.25rem,5.5vw,5rem)]"
            >
                <Reveal class="flex flex-wrap items-end justify-between gap-5">
                    <h2
                        class="text-[clamp(2.5rem,5vw,4rem)] leading-none font-bold tracking-[-0.045em] rtl:leading-tight rtl:tracking-normal"
                    >
                        {{ t('Good questions')
                        }}<span class="text-orange">.</span>
                    </h2>
                    <p class="text-ink-80 dark:text-muted-foreground">
                        {{ t('Got another one?') }}
                        <Link
                            :href="register()"
                            class="font-semibold text-brand hover:underline"
                            >{{ t("Let's talk") }}
                            <span class="rtl:hidden">→</span
                            ><span class="hidden rtl:inline">←</span></Link
                        >
                    </p>
                </Reveal>

                <div class="grid items-start gap-x-10 md:grid-cols-2">
                    <div
                        v-for="(faq, i) in faqs"
                        :key="faq.q"
                        class="border-t-[1.5px] border-foreground"
                    >
                        <h3>
                            <button
                                :id="`faq-q-${i}`"
                                type="button"
                                class="flex min-h-14 w-full items-center justify-between gap-5 py-5 text-start text-lg leading-snug font-semibold text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                :aria-expanded="openFaq === i"
                                :aria-controls="`faq-a-${i}`"
                                @click="toggleFaq(i)"
                            >
                                {{ t(faq.q) }}
                                <span
                                    class="flex size-[2.125rem] shrink-0 items-center justify-center rounded-full transition-[background-color,color,transform] duration-300 ease-brand"
                                    :class="
                                        openFaq === i
                                            ? 'rotate-45 bg-foreground text-background'
                                            : 'bg-muted text-foreground'
                                    "
                                    aria-hidden="true"
                                    ><Plus class="size-4"
                                /></span>
                            </button>
                        </h3>
                        <p
                            v-if="openFaq === i"
                            :id="`faq-a-${i}`"
                            role="region"
                            :aria-labelledby="`faq-q-${i}`"
                            class="animate-screen pe-10 pb-5.5 text-base text-ink-80 dark:text-muted-foreground"
                        >
                            {{ t(faq.a) }}
                        </p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Closing CTA ----------------------------------------------------------->
        <section
            class="px-3 pt-[clamp(1.5rem,4vw,3rem)] pb-[clamp(2.5rem,5vw,4rem)] sm:px-[clamp(0.75rem,3vw,3rem)]"
        >
            <div
                class="group relative mx-auto flex max-w-[84rem] flex-col gap-6.5 overflow-hidden rounded-4xl bg-ink px-[clamp(1.5rem,6vw,6rem)] pt-[clamp(2.5rem,7vw,6.5rem)] pb-[clamp(10.5rem,18vw,14.5rem)] text-cream"
            >
                <p
                    class="relative z-10 flex items-center gap-3.5 font-mono text-[0.8125rem] tracking-[0.24em] text-lime uppercase rtl:tracking-normal"
                >
                    <span class="h-0.5 w-7 bg-orange" aria-hidden="true" />
                    {{ t('Ready when you are') }}
                </p>
                <h2
                    class="relative z-10 max-w-[56rem] text-[clamp(3.25rem,8vw,7.5rem)] leading-[0.95] font-bold tracking-[-0.05em] rtl:leading-tight rtl:tracking-normal"
                >
                    {{ t('Marketing brighter together.').replace(/\.$/, '')
                    }}<span class="text-orange">.</span>
                </h2>
                <div class="relative z-10 flex flex-wrap gap-2.5">
                    <Link
                        :href="startHref"
                        class="inline-flex min-h-14 items-center gap-2.5 rounded-full bg-lime px-7.5 text-[1.0625rem] font-semibold text-ink transition-[background-color,transform] duration-150 ease-brand hover:-translate-y-0.5 hover:bg-lime-hover focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:ring-offset-2 focus-visible:ring-offset-ink focus-visible:outline-none"
                    >
                        {{ t("Let's grow") }}
                        <ArrowRight class="size-[18px] rtl:rotate-180" />
                    </Link>
                    <Link
                        v-if="!page.props.auth.user"
                        :href="login()"
                        class="inline-flex min-h-14 items-center rounded-full border-[1.5px] border-ink-60 px-7 text-[1.0625rem] font-semibold text-cream transition-colors duration-150 hover:border-cream hover:bg-cream hover:text-ink focus-visible:ring-[3px] focus-visible:ring-cream focus-visible:outline-none"
                    >
                        {{ t('Log in') }}
                    </Link>
                </div>

                <!-- The page's one peek, rising a little when the card is hovered. -->
                <PandaPeek
                    class="absolute -end-8 -bottom-[clamp(5.5rem,9vw,9.5rem)] aspect-[383/278] w-[clamp(18.75rem,40vw,35rem)] transition-transform duration-[400ms] ease-brand group-hover:-translate-y-9 group-hover:-rotate-4"
                />
            </div>
        </section>
    </MarketingLayout>
</template>
