<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import {
    ArrowRight,
    Building2,
    Languages,
    Lightbulb,
    Radar,
    Satellite,
    ScrollText,
    Send,
    ShieldCheck,
    Sparkles,
    Target,
    Users,
} from '@lucide/vue';
import PandaPeek from '@/components/PandaPeek.vue';
import Reveal from '@/components/marketing/Reveal.vue';
import MarketingLayout from '@/layouts/MarketingLayout.vue';
import { Button } from '@/components/ui/button';
import { useI18n } from '@/composables/useI18n';
import { login, register } from '@/routes';

/*
 * The About page — what the landing page promises when it says there is more under the hood.
 * Where the landing page shows the product moving, this one explains the shape of it: the
 * pipeline in full, the workspace grouped the way it actually divides, and the handful of
 * decisions we would rather state up front than be asked about.
 *
 * Brand (brand-tokens.md): Cloud Cream leads, Teal gives the structure, and the page carries
 * **one peek** — Soft Lilac, in the hero, cropped by the fold (§5). The confetti panel in the
 * closing band is the approved Teal-on-Lilac pair (§5); it is never mixed with the squiggle.
 * Signal Orange is the eyebrow dash, the full stops and one rule — never a status (§1.2).
 */
const { t } = useI18n();

/** The pipeline in full — the landing page shows four steps; this is what each one covers. */
const stages = [
    {
        n: '01',
        icon: Building2,
        title: 'We learn your business first',
        body: 'A guided profile in your own words, then an AI background check that reads your site and public sources and fills only the blanks you left. What you wrote is never overwritten.',
    },
    {
        n: '02',
        icon: Target,
        title: 'You define who is worth finding',
        body: 'One or more ideal customer profiles: weighted criteria, exclusions, and one of them active. Change the definition and everything downstream re-scores itself.',
    },
    {
        n: '03',
        icon: Radar,
        title: 'We go and find them',
        body: 'Companies House, Google Places and the open web, searched together in one run you can watch. Records are tidied up, duplicates merged, and every field stamped with where it came from and when.',
    },
    {
        n: '04',
        icon: Sparkles,
        title: 'Everything gets a grade',
        body: 'Your criteria weighed exactly as you set them, plus an AI read of everything they do not cover — one score out of 100 and an A–D grade, with the working shown criterion by criterion.',
    },
    {
        n: '05',
        icon: Satellite,
        title: 'We watch for the moment',
        body: 'A feed of trigger events in your market — funding rounds, exits, founders relocating — found in the news, classified and dated, so a good fit becomes a good week to call.',
    },
    {
        n: '06',
        icon: Send,
        title: 'You reach out, properly',
        body: 'Drafts built from your business and this prospect, in the right language and tone, with every contact attempt logged. Nothing reaches a prospect without passing the rules.',
    },
    {
        n: '07',
        icon: Lightbulb,
        title: 'The week explains itself',
        body: 'Replies, stages and scores come together in one picture, and three insights a day say what to do about it: who you went quiet on, who nobody has approached, and how the figures moved.',
    },
];

/** The workspace, grouped the way the product actually divides. */
const groups = [
    {
        label: 'Understand',
        items: [
            'Business profile — the guided wizard and your own description of what you sell',
            'Background check — the AI read of your company, versioned and reviewable',
            'Ideal customers — weighted criteria, exclusions and the active profile',
        ],
    },
    {
        label: 'Find',
        items: [
            'Lead discovery — tracked runs across three live sources',
            'Enrichment — normalisation, contact derivation, email verification and de-duplication',
            'Scoring — the hybrid grade, its breakdown and its explanation',
            'Signals — trigger events in the markets you sell to',
        ],
    },
    {
        label: 'Reach',
        items: [
            'Leads workspace — filters, saved views, the lead timeline and the pipeline',
            'Outreach — AI drafts, reusable briefs, the contact log and compliant sending',
            'Notifications — in-app, email and push, with per-person preferences and digests',
        ],
    },
    {
        label: 'Learn & protect',
        items: [
            'Reporting — one date window, counted the same way for every figure',
            'AI insights — the stale-lead alert, the contact-today list and the weekly read',
            'Compliance — lawful basis, retention sweeps, access and erasure, the opt-out',
        ],
    },
];

/** The decisions we would rather state than be asked about. */
const principles = [
    {
        icon: ShieldCheck,
        title: 'Your data is your team’s, full stop',
        body: 'Every record belongs to one team, and that is enforced twice over — once by the database, once again before anything is shown. One team seeing another team’s data is not something we watch for; it is something we test for.',
    },
    {
        icon: ScrollText,
        title: 'A score you can argue with',
        body: 'No grade appears without its reasoning. You can see which criterion moved it, what the model thought and when it was last computed.',
    },
    {
        icon: Languages,
        title: 'Bilingual, not translated later',
        body: 'English and Arabic, left to right and right to left, were there from the first day — in the screens and in the emails, not bolted on later for a second market.',
    },
    {
        icon: Users,
        title: 'We will not go around consent',
        body: 'We buy no contact data, and we take nothing from a source whose terms forbid it. If a prospect may not be approached, the software refuses to send — because a rule that comes from the law should not depend on who holds which permission.',
    },
];
</script>

<template>
    <Head :title="t('About Alima')" />

    <MarketingLayout>
        <!-- Hero ---------------------------------------------------------------->
        <section
            class="mx-auto w-full max-w-7xl px-5 pt-16 pb-10 sm:px-8 lg:pt-24"
        >
            <p
                class="flex items-center gap-3 font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
            >
                <span
                    class="h-0.5 w-8 rounded-full bg-orange"
                    aria-hidden="true"
                />
                {{ t('About Alima') }}
            </p>

            <div
                class="grid items-center gap-10 lg:grid-cols-[minmax(0,1fr)_auto]"
            >
                <div class="pt-6">
                    <h1
                        class="max-w-2xl text-5xl leading-[0.98] font-bold tracking-tight text-balance sm:text-6xl lg:text-7xl"
                    >
                        {{ t('Prospecting,') }}<br />
                        {{ t('run end to end')
                        }}<span class="text-orange">.</span>
                    </h1>
                    <p
                        class="mt-8 max-w-xl text-lg leading-relaxed text-foreground"
                    >
                        {{
                            t(
                                'Alima brings sourcing, AI qualification, bilingual outreach and reply tracking together in one workspace — built for teams selling in the UK and the UAE.',
                            )
                        }}
                    </p>
                </div>

                <!--
                  The page's one peek (brand-tokens.md §5). The box crops it: the artwork is
                  scaled past the bottom edge and cut off there, which is what makes it a peek
                  rather than a panda sitting in the corner. The box carries an explicit width so
                  the crop is the same whatever the headline does to the grid, and it is sized by
                  the artwork's own 383:278 ratio — a square box would letterbox it instead.

                  `self-end` plus the negative bottom margin put the box's bottom edge exactly on
                  the hairline rule below, so the rule is what the eye reads as the crop. Cutting
                  it anywhere else leaves the panda sliced flat in mid-air on the cream canvas,
                  which is not a peek — it is a mistake.
                -->
                <div
                    class="relative -mb-6 hidden h-72 w-[26rem] self-end justify-self-end overflow-hidden lg:block"
                >
                    <PandaPeek
                        class="absolute inset-x-0 -bottom-16 aspect-[383/278] w-full"
                    />
                </div>
            </div>

            <div class="mt-6 h-px w-full bg-ink/80" aria-hidden="true" />
        </section>

        <!-- Why ----------------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 lg:py-24">
            <Reveal class="grid gap-10 lg:grid-cols-[1fr_1fr] lg:gap-20">
                <div>
                    <p
                        class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                    >
                        {{ t('Why we built it') }}
                    </p>
                    <h2
                        class="mt-4 text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Good prospecting is ')
                        }}<span class="text-teal">{{ t('mostly admin') }}</span
                        ><span class="text-orange">.</span>
                    </h2>
                </div>
                <div class="space-y-5 text-lg leading-relaxed">
                    <p>
                        {{
                            t(
                                'A list comes out of one tool, gets cleaned in a spreadsheet, is ranked by whoever has the most patience, and is emailed from somewhere else entirely. By the time anyone calls, the reason for calling has gone cold.',
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'We built Alima so that chain is one pipeline. The same system that finds a company knows why it fits, what changed about it last week, who on your team spoke to it last, and whether it may be contacted at all.',
                            )
                        }}
                    </p>
                </div>
            </Reveal>
        </section>

        <!-- The pipeline in full ------------------------------------------------>
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal>
                <p
                    class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                >
                    {{ t('The whole pipeline') }}
                </p>
                <h2
                    class="mt-4 max-w-3xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                >
                    {{ t('From search to signed, ')
                    }}<span class="text-teal">{{ t('together') }}</span
                    ><span class="text-orange">.</span>
                </h2>
            </Reveal>

            <ol class="mt-12 space-y-px overflow-hidden rounded-3xl bg-border">
                <li v-for="(stage, i) in stages" :key="stage.n">
                    <Reveal
                        :delay="i * 50"
                        class="grid gap-4 bg-card p-7 sm:grid-cols-[auto_1fr] sm:gap-8 sm:p-9"
                    >
                        <div class="flex items-center gap-4 sm:w-28">
                            <span
                                class="flex size-12 shrink-0 items-center justify-center rounded-full bg-accent text-teal"
                                aria-hidden="true"
                            >
                                <component :is="stage.icon" class="size-6" />
                            </span>
                            <span class="font-mono text-sm text-teal">{{
                                stage.n
                            }}</span>
                        </div>
                        <div class="max-w-3xl">
                            <h3
                                class="text-xl font-semibold tracking-tight rtl:font-bold"
                            >
                                {{ t(stage.title) }}
                            </h3>
                            <p
                                class="mt-3 leading-relaxed text-muted-foreground"
                            >
                                {{ t(stage.body) }}
                            </p>
                        </div>
                    </Reveal>
                </li>
            </ol>
        </section>

        <!-- The workspace, grouped ---------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-16 sm:px-8 lg:py-24">
            <Reveal>
                <p
                    class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                >
                    {{ t('Everything you need') }}
                </p>
                <h2
                    class="mt-4 max-w-2xl text-4xl font-bold tracking-tight sm:text-5xl rtl:font-bold"
                >
                    {{ t('One workspace,') }}<br />
                    <span class="text-teal">{{ t('every part') }}</span>
                    {{ t('of the job') }}<span class="text-orange">.</span>
                </h2>
            </Reveal>

            <div class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                <Reveal
                    v-for="(group, i) in groups"
                    :key="group.label"
                    :delay="i * 80"
                    class="flex flex-col"
                >
                    <div class="h-px w-full bg-ink" aria-hidden="true" />
                    <h3
                        class="mt-4 font-mono text-sm tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                    >
                        {{ t(group.label) }}
                    </h3>
                    <ul class="mt-5 space-y-4">
                        <li
                            v-for="item in group.items"
                            :key="item"
                            class="flex gap-3 leading-relaxed text-muted-foreground"
                        >
                            <span
                                class="mt-2.5 size-1.5 shrink-0 rounded-full bg-teal"
                                aria-hidden="true"
                            />
                            <span>{{ t(item) }}</span>
                        </li>
                    </ul>
                </Reveal>
            </div>
        </section>

        <!-- How we build it ----------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal
                class="overflow-hidden rounded-3xl bg-ink px-8 py-14 text-cream sm:px-14 sm:py-16"
            >
                <p
                    class="font-mono text-xs tracking-eyebrow text-lilac uppercase rtl:tracking-normal"
                >
                    {{ t('How we build it') }}
                </p>
                <h2
                    class="mt-4 max-w-2xl text-4xl leading-[1.05] font-bold tracking-tight sm:text-5xl rtl:font-bold"
                >
                    {{ t('Four things we decided ')
                    }}<span class="text-lime">{{ t('early') }}</span
                    ><span class="text-orange">.</span>
                </h2>

                <div class="mt-12 grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    <div
                        v-for="principle in principles"
                        :key="principle.title"
                        class="flex flex-col"
                    >
                        <div
                            class="h-px w-full bg-cream/25"
                            aria-hidden="true"
                        />
                        <span
                            class="mt-5 flex size-11 items-center justify-center rounded-full bg-cream/10 text-lime"
                            aria-hidden="true"
                        >
                            <component :is="principle.icon" class="size-5" />
                        </span>
                        <h3
                            class="mt-5 text-lg font-semibold tracking-tight rtl:font-bold"
                        >
                            {{ t(principle.title) }}
                        </h3>
                        <p class="mt-3 leading-relaxed text-cream/70">
                            {{ t(principle.body) }}
                        </p>
                    </div>
                </div>
            </Reveal>
        </section>

        <!-- Where we work ------------------------------------------------------->
        <section class="mx-auto w-full max-w-7xl px-5 py-10 sm:px-8 lg:py-16">
            <Reveal class="grid gap-10 lg:grid-cols-[1fr_1fr] lg:gap-20">
                <div>
                    <p
                        class="font-mono text-xs tracking-eyebrow text-teal uppercase rtl:tracking-normal"
                    >
                        {{ t('Where we work') }}
                    </p>
                    <h2
                        class="mt-4 text-4xl leading-[1.05] font-bold tracking-tight text-balance sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Two markets, ')
                        }}<span class="text-teal">{{
                            t('two sets of rules')
                        }}</span
                        ><span class="text-orange">.</span>
                    </h2>
                </div>
                <div class="space-y-5 text-lg leading-relaxed">
                    <p>
                        {{
                            t(
                                'The United Kingdom and the United Arab Emirates are not one market with two postcodes. Different registries, different consent rules, different working weeks, different languages.',
                            )
                        }}
                    </p>
                    <p class="text-muted-foreground">
                        {{
                            t(
                                'So the market a team selects decides where its leads come from, how a prospect may be approached, and what Alima is allowed to keep. English and Arabic are equal citizens throughout, right to left included.',
                            )
                        }}
                    </p>
                </div>
            </Reveal>
        </section>

        <!-- CTA band ------------------------------------------------------------>
        <section class="mx-auto w-full max-w-7xl px-5 pt-6 pb-24 sm:px-8">
            <Reveal
                class="grid overflow-hidden rounded-3xl lg:grid-cols-[1.1fr_0.9fr]"
            >
                <div
                    class="flex flex-col items-start justify-center gap-6 bg-ink p-10 text-cream sm:p-14"
                >
                    <h2
                        class="max-w-md text-4xl leading-[1.05] font-bold tracking-tight sm:text-5xl rtl:font-bold"
                    >
                        {{ t('Stop guessing who to call')
                        }}<span class="text-orange">.</span>
                    </h2>
                    <p class="max-w-sm text-lg text-cream/70">
                        {{
                            t(
                                'Let Alima find, qualify and reach the right businesses for you.',
                            )
                        }}
                    </p>
                    <div class="flex flex-wrap items-center gap-3">
                        <Button as-child size="lg" variant="lime">
                            <Link :href="register()">
                                {{ t("Let's grow") }}
                                <ArrowRight class="size-4 rtl:rotate-180" />
                            </Link>
                        </Button>
                        <Button as-child size="lg" variant="hero">
                            <Link :href="login()">{{ t('Log in') }}</Link>
                        </Button>
                    </div>
                </div>

                <!-- Confetti panel (brand element — chunky loops/crosses/dots) -->
                <div class="relative min-h-56 bg-lilac" aria-hidden="true">
                    <svg
                        class="absolute inset-0 h-full w-full text-teal"
                        preserveAspectRatio="xMidYMid slice"
                        viewBox="0 0 240 200"
                    >
                        <g
                            fill="none"
                            stroke="currentColor"
                            stroke-width="7"
                            stroke-linecap="round"
                        >
                            <path d="M28 40c12-14 30-4 20 10s-26 2-14-14" />
                            <path d="M120 24c16-6 26 10 10 18s-28-6-10-18" />
                            <path d="M196 54c14-10 28 6 12 16s-30-4-12-16" />
                            <path d="M60 110c18-8 26 12 8 18s-26-10-8-18" />
                            <path d="M150 120c10-16 32-8 22 8s-30 6-22-8" />
                            <path d="M206 128c12-4 20 10 6 16" />
                            <path d="M36 164c14-6 24 8 10 16s-26-8-10-16" />
                            <path d="M110 170c12-10 28 2 16 14s-30 0-16-14" />
                            <path d="M182 176c10-12 28-2 16 12" />
                            <path d="M86 62l14 14M100 62l-14 14" />
                            <path d="M170 92l14 14M184 92l-14 14" />
                            <path d="M20 108l12 12M32 108l-12 12" />
                        </g>
                        <g fill="currentColor">
                            <circle cx="150" cy="60" r="5" />
                            <circle cx="64" cy="150" r="5" />
                            <circle cx="214" cy="100" r="5" />
                        </g>
                    </svg>
                </div>
            </Reveal>
        </section>
    </MarketingLayout>
</template>
