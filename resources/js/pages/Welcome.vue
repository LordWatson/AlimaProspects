<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Languages,
    Lightbulb,
    Radar,
    Satellite,
    Send,
    ShieldCheck,
    Sparkles,
    Target,
} from '@lucide/vue';
import PandaPeek from '@/components/PandaPeek.vue';
import ProductShowcase from '@/components/marketing/ProductShowcase.vue';
import Reveal from '@/components/marketing/Reveal.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { dashboard, register } from '@/routes';

/*
 * The landing page. Its job is to make a stranger want to see the product, and then want the
 * whole story — so it shows the workspace moving before it explains anything, and it gives the
 * About page a real invitation rather than a link in the nav.
 *
 * Brand (brand-tokens.md): Cloud Cream leads; Acid Lime is the hero accent and carries the one
 * primary CTA; Signal Orange appears as the eyebrow dash, the full stops and the one rounded
 * rule, and never as a status (§1.2, §3). **One peek, one colour, one placement** (§5) — this
 * page spends its peek on the About invitation, because that is the moment meant to pull, and
 * the showcase is already the hero's figure.
 */
const { t } = useI18n();
const page = usePage();

/** The pipeline, in the order the product runs it. */
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

/** The parts of the system worth a stranger's attention, in the workspace's own order. */
const capabilities = [
    {
        icon: Building2,
        title: 'Your business, researched',
        body: 'An AI background check on your own company reads your site and public sources, then fills the blanks you left — never overwriting your words.',
    },
    {
        icon: Target,
        title: 'Ideal customer profiles',
        body: 'Define who you actually want in weighted criteria and exclusions. Change the definition and everything re-scores behind you.',
    },
    {
        icon: Radar,
        title: 'Multi-source discovery',
        body: 'Three live sources, one run. Watch it work through them, and stop at the limit your plan sets.',
    },
    {
        icon: Sparkles,
        title: 'Hybrid scoring',
        body: 'Your own criteria, weighed exactly as you set them, plus an AI read of the rest — one score out of 100 and a grade you can argue with.',
    },
    {
        icon: Satellite,
        title: 'Trigger-event signals',
        body: 'Funding rounds, exits and relocations in your market, found in the news, classified and dated — so you arrive while it still matters.',
    },
    {
        icon: Send,
        title: 'Compliant outreach',
        body: 'Personalised drafts, reusable briefs, a full contact log — and suppression, sender identity and the opt-out enforced at send time.',
    },
    {
        icon: Lightbulb,
        title: 'Insights that act',
        body: 'The companies you went quiet on, the strong matches nobody has approached, and the model’s read of your week.',
    },
    {
        icon: ShieldCheck,
        title: 'Privacy, enforced',
        body: 'Nothing is sourced until your lawful basis is written down, old data clears out every night, and a request to see or erase it is a screen, not a scramble.',
    },
];
</script>

<template>
    <Head :title="t('Alima — Marketing brighter together')" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto w-full max-w-7xl px-5 pt-8 pb-16 sm:px-8 lg:pt-14"
        >
            <p
                class="flex items-center gap-3 font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                <span
                    class="h-0.5 w-8 rounded-full bg-orange"
                    aria-hidden="true"
                />
                {{ t('Bold ideas. Real impact.') }}
            </p>

            <!--
              The statement runs the full width rather than sharing a column with the lead. Its
              break is deliberate — two lines, the second one landing on the orange full stop —
              and at display sizes the first line needs the whole measure to keep it (§3: tight
              leading, no widows). The lead and the actions sit under it on one baseline.
            -->
            <h1
                class="mt-6 max-w-5xl text-5xl leading-[0.95] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl xl:text-[5.25rem]"
            >
                {{ t('Find the right prospects,') }}<br />
                {{ t('reach them first') }}<span class="text-orange">.</span>
            </h1>

            <div
                class="mt-10 flex flex-col gap-8 lg:flex-row lg:items-end lg:justify-between lg:gap-16"
            >
                <p
                    class="max-w-xl text-lg leading-relaxed text-foreground lg:text-xl"
                >
                    {{
                        t(
                            'Alima finds, qualifies and reaches the businesses most likely to buy — with AI built for the UK and the UAE, bilingual from day one.',
                        )
                    }}
                </p>
                <div class="flex shrink-0 flex-wrap items-center gap-3">
                    <Button as-child size="lg" variant="lime">
                        <Link
                            :href="
                                page.props.auth.user ? dashboard() : register()
                            "
                        >
                            {{ t("Let's grow") }}
                            <ArrowRight class="size-4 rtl:rotate-180" />
                        </Link>
                    </Button>
                    <Button as-child size="lg" variant="outline">
                        <a href="#how">{{ t('See how it works') }}</a>
                    </Button>
                </div>
            </div>

            <!-- The workspace, moving ------------------------------------------>
            <Reveal class="mt-14 lg:mt-20">
                <ProductShowcase />
            </Reveal>
        </section>

        <!-- How it works -------------------------------------------------------->
        <section
            id="how"
            class="mx-auto w-full max-w-7xl scroll-mt-28 px-5 py-16 sm:px-8 lg:py-24"
        >
            <Reveal>
                <p
                    class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                >
                    {{ t('How Alima works') }}
                </p>
                <h2
                    class="mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                >
                    {{ t('Four steps to your ')
                    }}<span class="text-teal">{{ t('next customer') }}</span
                    >.
                </h2>
            </Reveal>

            <div class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <Reveal
                    v-for="(s, i) in steps"
                    :key="s.step"
                    :delay="i * 80"
                    class="flex flex-col"
                >
                    <div class="h-px w-full bg-ink" aria-hidden="true" />
                    <div class="mt-4 flex items-center gap-3">
                        <span
                            class="flex size-11 items-center justify-center rounded-full bg-accent text-teal"
                            aria-hidden="true"
                        >
                            <component :is="s.icon" class="size-5" />
                        </span>
                        <span class="font-mono text-sm text-teal">{{
                            s.step
                        }}</span>
                    </div>
                    <h3
                        class="mt-4 text-xl font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(s.title) }}
                    </h3>
                    <p class="mt-3 leading-relaxed text-muted-foreground">
                        {{ t(s.body) }}
                    </p>
                </Reveal>
            </div>
        </section>

        <!-- What's inside ------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal>
                <p
                    class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                >
                    {{ t("What's inside") }}
                </p>
                <h2
                    class="mt-4 max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                >
                    {{ t('One workspace,') }}<br />
                    <span class="text-teal">{{ t('every part') }}</span>
                    {{ t('of the hunt') }}.
                </h2>
            </Reveal>

            <Reveal
                class="mt-12 grid gap-px overflow-hidden rounded-3xl border border-border bg-border sm:grid-cols-2 lg:grid-cols-4"
            >
                <div
                    v-for="item in capabilities"
                    :key="item.title"
                    class="flex flex-col gap-4 bg-card p-7"
                >
                    <div
                        class="flex size-12 items-center justify-center rounded-full bg-accent text-teal"
                        aria-hidden="true"
                    >
                        <component :is="item.icon" class="size-6" />
                    </div>
                    <h3
                        class="text-lg font-semibold tracking-tight rtl:font-bold"
                    >
                        {{ t(item.title) }}
                    </h3>
                    <p class="leading-relaxed text-muted-foreground">
                        {{ t(item.body) }}
                    </p>
                </div>
            </Reveal>
        </section>

        <!-- Two markets --------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal class="grid gap-6 lg:grid-cols-[1.1fr_0.9fr]">
                <div
                    class="flex flex-col justify-between gap-10 rounded-3xl bg-teal p-10 text-cream sm:p-12"
                >
                    <div>
                        <p
                            class="font-mono text-xs tracking-eyebrow text-cream/75 uppercase rtl:tracking-normal"
                        >
                            {{ t('Two markets, one team') }}
                        </p>
                        <h2
                            class="mt-4 max-w-md text-3xl leading-[1.1] font-bold tracking-tight text-balance sm:text-4xl rtl:font-bold"
                        >
                            {{ t('Built for the UK and the UAE')
                            }}<span class="text-lime">.</span>
                        </h2>
                        <p class="mt-5 max-w-md leading-relaxed text-cream/75">
                            {{
                                t(
                                    'Sources, rules and tone differ by market — so Alima treats them as different, and the whole product speaks English and Arabic, right to left included.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-cream">
                        <Languages class="size-5 shrink-0" aria-hidden="true" />
                        <p class="font-mono text-xs">EN · AR · RTL</p>
                    </div>
                </div>

                <div
                    class="flex flex-col justify-between gap-10 rounded-3xl border border-border bg-card p-10 sm:p-12"
                >
                    <div>
                        <p
                            class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                        >
                            {{ t('Compliance is not a checkbox') }}
                        </p>
                        <h2
                            class="mt-4 max-w-sm text-3xl leading-[1.1] font-bold tracking-tight sm:text-4xl rtl:font-bold"
                        >
                            {{ t('The rules are in the software')
                            }}<span class="text-orange">.</span>
                        </h2>
                        <p
                            class="mt-5 max-w-md leading-relaxed text-muted-foreground"
                        >
                            {{
                                t(
                                    'A recorded lawful basis gates discovery. Suppression, sender identity and the opt-out are conditions of sending, not settings someone can forget.',
                                )
                            }}
                        </p>
                    </div>
                    <div class="flex items-center gap-3 text-teal">
                        <ShieldCheck
                            class="size-5 shrink-0"
                            aria-hidden="true"
                        />
                        <p class="font-mono text-xs">
                            {{ t('UK GDPR · UAE PDPL') }}
                        </p>
                    </div>
                </div>
            </Reveal>
        </section>

        <!-- The invitation to About --------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal>
                <Link
                    href="/about"
                    class="group duration-base relative block overflow-hidden rounded-3xl bg-ink px-8 py-14 text-cream transition-shadow ease-brand hover:shadow-md focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none sm:px-14 sm:py-20"
                >
                    <div
                        class="relative z-10 grid gap-10 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end"
                    >
                        <div class="max-w-2xl">
                            <p
                                class="font-mono text-xs tracking-eyebrow text-lilac uppercase rtl:tracking-normal"
                            >
                                {{ t('The bigger picture') }}
                            </p>
                            <h2
                                class="mt-5 text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-6xl rtl:font-bold"
                            >
                                {{ t("There's a lot more") }}<br />
                                {{ t('under the hood')
                                }}<span class="text-orange">.</span>
                            </h2>
                            <p class="mt-6 max-w-lg text-lg text-cream/70">
                                {{
                                    t(
                                        'Every part of the pipeline, and the decisions behind it — where leads come from, how they are scored, and what we will not do with them.',
                                    )
                                }}
                            </p>
                            <span
                                class="mt-8 inline-flex items-center gap-2 font-semibold text-lime"
                            >
                                {{ t('Read about Alima') }}
                                <ArrowRight
                                    class="duration-base size-4 transition-transform ease-brand group-hover:translate-x-1 rtl:rotate-180 rtl:group-hover:-translate-x-1"
                                    aria-hidden="true"
                                />
                            </span>
                        </div>

                        <!--
                          The page's one peek (brand-tokens.md §5): scaled up, set into the card's
                          bottom end corner and cropped there, both eyes still in frame.

                          It gets **its own grid column** rather than being positioned over the
                          card. Floating it over the copy put the lead paragraph on top of the
                          lilac ear at tablet widths — Cream on Lilac, which fails contrast, and
                          §5 allows text only on a solid panel. A column cannot overlap. The
                          negative margins cancel the card's padding so the box still reaches the
                          corner, and the box clips the artwork at exactly the card's edge.

                          Sized by the artwork's 383:278 ratio, never a square box — a square
                          letterboxes it into a small floating panda instead of a peek. The widths
                          keep it past §5's "≥3× any nearby logo" against the 134px header
                          lock-up: 416px at `lg`, 512px at `xl`.
                        -->
                        <div
                            class="relative -me-8 -mb-14 hidden h-64 w-[26rem] self-end overflow-hidden sm:-me-20 sm:-mb-20 lg:block xl:h-80 xl:w-[32rem]"
                        >
                            <PandaPeek
                                class="absolute inset-x-0 -bottom-14 aspect-[383/278] w-full xl:-bottom-16"
                            />
                        </div>
                    </div>
                </Link>
            </Reveal>
        </section>

        <!-- Closing CTA --------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 pt-6 pb-24 sm:px-8">
            <Reveal
                class="flex flex-col items-start gap-6 border-t border-ink/80 pt-12 sm:flex-row sm:items-end sm:justify-between"
            >
                <div>
                    <h2
                        class="max-w-xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Ready to grow') }}<!--
                          The mark is its own key because Arabic ends a question with "؟", not
                          "?". Full stops are shared between the two scripts and stay literal.
                        --><span class="text-orange">{{ t('?') }}</span>
                    </h2>
                    <p class="mt-4 max-w-md text-lg text-muted-foreground">
                        {{ t("Let's build your pipeline together.") }}
                    </p>
                </div>
                <Button
                    v-if="!page.props.auth.user"
                    as-child
                    size="lg"
                    variant="lime"
                >
                    <Link :href="register()">
                        {{ t("Let's grow") }}
                        <ArrowRight class="size-4 rtl:rotate-180" />
                    </Link>
                </Button>
            </Reveal>
        </section>
    </MarketingLayout>
</template>
