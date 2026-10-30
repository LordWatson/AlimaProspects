<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import {
    Building2,
    LayoutGrid,
    Lightbulb,
    Radar,
    Satellite,
    Scan,
    Send,
    Target,
    Users,
} from '@lucide/vue';
import type { LucideIcon } from '@lucide/vue';
import AppLogo from '@/components/AppLogo.vue';
import { useI18n } from '@/composables/useI18n';
import { useReducedMotion } from '@/composables/useReducedMotion';

/*
 * The landing page's one moving part: a miniature of the real workspace that walks a visitor
 * through the four steps the product actually takes — discover, qualify, watch, reach — one
 * screen at a time.
 *
 * **It is a drawing of the app, not a screenshot of it.** Every surface here is built from the
 * same tokens the real screens use, so it cannot drift into a look the product does not have:
 * the Deep Teal sidebar with its numbered items and Acid Lime active pill (`AppSidebar.vue`),
 * and the Midnight Ink opening card with its tracked mono eyebrow, Lime statement tail, single
 * Signal Orange rule and headline figure (`PageHero.vue`). The nav numbering is the real one.
 *
 * Brand (brand-tokens.md):
 *  - §1.2 one hero accent — Lime carries the statements and the active pill; Orange appears once
 *    per screen as the rule and is decorative, never a status. Every status here is a
 *    `status.*` token (§2.1).
 *  - §4 the lock-up is drawn at `sm`, which is 121px wide — above the 120px minimum. The mini
 *    sidebar is sized around that, not the other way round.
 *  - §5 no peek: the showcase is the hero's figure, and the page spends its one peek elsewhere.
 *
 * Motion: the screens advance themselves every 6s so a visitor who scrolls past still sees more
 * than one, and that is exactly the kind of movement nobody asked for — so it stops dead under
 * `prefers-reduced-motion`, and pauses while a pointer or the keyboard is inside the component.
 * The tabs are a real tablist: arrow keys, Home and End all work, and every screen stays in the
 * DOM (stacked in one grid cell) so the frame never resizes as it cycles.
 */
const { t, isRtl } = useI18n();
const reduced = useReducedMotion();

const DWELL_MS = 6000;

type Screen = {
    key: string;
    /** The screen's number in the real sidebar. */
    n: string;
    /**
     * The tab label. These borrow the pipeline's own verbs rather than inventing a second set
     * of names for the same four things — and `Source` over `Discover` because the latter is
     * already a button elsewhere, where Arabic wants the imperative and a tab wants the noun.
     */
    tab: string;
    eyebrow: string;
    meta: string;
    /** Statement head, set in Cloud Cream. */
    head: string;
    /** Statement tail, set in Acid Lime (PageHero's `|` convention). */
    accent: string;
    lead: string;
    stat: { label: string; value: string; caption: string };
};

const nav: { n: string; title: string; icon: LucideIcon }[] = [
    { n: '01', title: t('Dashboard'), icon: LayoutGrid },
    { n: '02', title: t('Business profile'), icon: Building2 },
    { n: '03', title: t('Background check'), icon: Scan },
    { n: '04', title: t('Ideal customers'), icon: Target },
    { n: '05', title: t('Lead discovery'), icon: Radar },
    { n: '06', title: t('Leads'), icon: Users },
    { n: '07', title: t('Signals'), icon: Satellite },
    { n: '08', title: t('Outreach'), icon: Send },
    { n: '09', title: t('Insights'), icon: Lightbulb },
];

const screens = computed<Screen[]>(() => [
    {
        key: 'discovery',
        n: '05',
        tab: t('Source'),
        eyebrow: t('Lead discovery'),
        meta: t('RUN IN PROGRESS'),
        head: t('Find the businesses'),
        accent: t('worth your week.'),
        lead: t(
            'One run across Companies House, Google Places and the open web — deduplicated as it lands.',
        ),
        stat: {
            label: t('FOUND'),
            value: '182',
            caption: t('new companies this run'),
        },
    },
    {
        key: 'leads',
        n: '06',
        tab: t('Qualify'),
        eyebrow: t('Leads'),
        meta: t('SCORED AGAINST YOUR IDEAL CUSTOMER'),
        head: t('Know who is worth'),
        accent: t('a conversation.'),
        lead: t(
            'Every lead graded against your ideal customer profile, with the reasoning written out in plain English.',
        ),
        stat: {
            label: t('GRADE A'),
            value: '24',
            caption: t('strong matches waiting'),
        },
    },
    {
        key: 'signals',
        n: '07',
        tab: t('Watch'),
        eyebrow: t('Signals'),
        meta: t('LAST 7 DAYS'),
        head: t('Arrive while it'),
        accent: t('still matters.'),
        lead: t(
            'Funding rounds, exits and relocations in your market — found, classified and dated.',
        ),
        stat: {
            label: t('NEW SIGNALS'),
            value: '09',
            caption: t('in the markets you sell to'),
        },
    },
    {
        key: 'outreach',
        n: '08',
        tab: t('Reach'),
        eyebrow: t('Outreach'),
        meta: t('DRAFT · ENGLISH'),
        head: t('Say the right thing,'),
        accent: t('first time.'),
        lead: t(
            'A draft built from your business profile and this lead — checked against the rules before it can send.',
        ),
        stat: {
            label: t('REPLIES'),
            value: '11',
            caption: t('from 48 sent this month'),
        },
    },
]);

const providers = computed(() => [
    { name: t('Companies House'), found: 94, progress: 100 },
    { name: t('Google Places'), found: 61, progress: 100 },
    { name: t('Web search'), found: 27, progress: 62 },
]);

const leads = computed(() => [
    {
        name: 'Harrow & Finch Ltd',
        place: t('Manchester, UK'),
        grade: 'A',
        score: 92,
    },
    {
        name: 'Nadia Interiors FZ-LLC',
        place: t('Dubai, UAE'),
        grade: 'A',
        score: 88,
    },
    {
        name: 'Brightwater Logistics',
        place: t('Leeds, UK'),
        grade: 'B',
        score: 74,
    },
    {
        name: 'Qasr Hospitality Group',
        place: t('Abu Dhabi, UAE'),
        grade: 'B',
        score: 69,
    },
]);

const signals = computed(() => [
    {
        company: 'Meridian Capital',
        kind: t('Funding round'),
        when: t('2 days ago'),
    },
    {
        company: 'Sable & Co',
        kind: t('Founder exit'),
        when: t('4 days ago'),
    },
    {
        company: 'Hallward Group',
        kind: t('Relocating to Dubai'),
        when: t('6 days ago'),
    },
]);

const guards = computed(() => [
    t('Lawful basis recorded'),
    t('Not on the do-not-contact list'),
    t('One-click opt-out attached'),
]);

const active = ref(0);
/** A pointer or the keyboard is inside the component — hold still while it is. */
const paused = ref(false);
/** Someone has picked a screen by hand. Once that happens the carousel is over. */
const steered = ref(false);

let timer: ReturnType<typeof setInterval> | null = null;

function stop(): void {
    if (timer !== null) {
        clearInterval(timer);
        timer = null;
    }
}

function start(): void {
    stop();

    if (reduced.value || paused.value || steered.value) {
        return;
    }

    timer = setInterval(() => {
        active.value = (active.value + 1) % screens.value.length;
    }, DWELL_MS);
}

/**
 * Choosing a tab by hand ends the carousel for good. Someone who has started steering does not
 * want the thing to carry on moving under them two seconds later.
 */
function select(index: number): void {
    active.value = index;
    steered.value = true;
    stop();
}

const tablist = ref<HTMLElement | null>(null);

function focusTab(index: number): void {
    const next = (index + screens.value.length) % screens.value.length;

    select(next);
    tablist.value
        ?.querySelectorAll<HTMLButtonElement>('[role="tab"]')
        .item(next)
        ?.focus();
}

function onTabKeydown(event: KeyboardEvent, index: number): void {
    // The tabs are laid out by the reading direction, so "next" is ArrowLeft in Arabic. Keying
    // off ArrowRight alone would walk the list backwards on half the product's markets.
    const rtl = isRtl.value;
    const next = rtl ? 'ArrowLeft' : 'ArrowRight';
    const previous = rtl ? 'ArrowRight' : 'ArrowLeft';

    const forward = event.key === next || event.key === 'ArrowDown';
    const back = event.key === previous || event.key === 'ArrowUp';

    if (forward) {
        focusTab(index + 1);
    } else if (back) {
        focusTab(index - 1);
    } else if (event.key === 'Home') {
        focusTab(0);
    } else if (event.key === 'End') {
        focusTab(screens.value.length - 1);
    } else {
        return;
    }

    event.preventDefault();
}

watch([reduced, paused, steered], start);

onMounted(start);
onBeforeUnmount(stop);
</script>

<template>
    <div
        class="relative"
        @pointerenter="
            paused = true;
            stop();
        "
        @pointerleave="
            paused = false;
            start();
        "
        @focusin="
            paused = true;
            stop();
        "
        @focusout="
            paused = false;
            start();
        "
    >
        <!-- The app window ---------------------------------------------------->
        <div
            class="overflow-hidden rounded-3xl bg-cream-surface shadow-md ring-1 ring-ink/10"
        >
            <!-- Window chrome -->
            <div
                class="flex items-center gap-3 border-b border-border bg-background px-4 py-3"
            >
                <span class="flex gap-1.5" aria-hidden="true">
                    <span class="size-2.5 rounded-full bg-ink/15" />
                    <span class="size-2.5 rounded-full bg-ink/15" />
                    <span class="size-2.5 rounded-full bg-ink/15" />
                </span>
                <span
                    class="mx-auto hidden rounded-full bg-secondary px-4 py-1 font-mono text-[0.625rem] text-muted-foreground sm:block"
                    dir="ltr"
                >
                    alimaprospects.app
                </span>
            </div>

            <div class="flex">
                <!-- Sidebar — the real one, at the real proportions ------------->
                <aside
                    class="hidden w-52 shrink-0 flex-col bg-teal py-5 text-cream md:flex"
                    aria-hidden="true"
                >
                    <div class="px-4 pb-5">
                        <AppLogo tone="dark" size="sm" />
                    </div>
                    <p
                        class="px-5 pb-2 font-mono text-[0.5625rem] tracking-eyebrow text-cream/75 uppercase rtl:tracking-normal"
                    >
                        {{ t('Workspace') }}
                    </p>
                    <ul class="space-y-0.5 px-2.5">
                        <li v-for="item in nav" :key="item.n">
                            <span
                                class="duration-base flex items-center gap-2.5 rounded-full px-2.5 py-1.5 text-xs font-medium transition-colors ease-brand"
                                :class="
                                    item.n === screens[active].n
                                        ? 'bg-lime text-ink'
                                        : 'text-cream/80'
                                "
                            >
                                <component
                                    :is="item.icon"
                                    class="size-3.5 shrink-0"
                                />
                                <span class="flex-1 truncate">{{
                                    item.title
                                }}</span>
                                <span
                                    class="font-mono text-[0.5625rem]"
                                    :class="
                                        item.n === screens[active].n
                                            ? 'text-ink/60'
                                            : 'text-cream/75'
                                    "
                                    >{{ item.n }}</span
                                >
                            </span>
                        </li>
                    </ul>
                </aside>

                <!-- Stage — every screen lives in one grid cell, so the frame
                     keeps its height while they cross-fade. -->
                <div class="grid min-w-0 flex-1 p-4 sm:p-6">
                    <section
                        v-for="(screen, index) in screens"
                        :id="`showcase-panel-${screen.key}`"
                        :key="screen.key"
                        role="tabpanel"
                        :aria-labelledby="`showcase-tab-${screen.key}`"
                        :aria-hidden="index !== active"
                        :tabindex="index === active ? 0 : undefined"
                        class="col-start-1 row-start-1 flex flex-col gap-4 rounded-2xl transition-[opacity,transform] duration-300 ease-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none"
                        :class="
                            index === active
                                ? 'translate-y-0 opacity-100'
                                : 'pointer-events-none translate-y-3 opacity-0'
                        "
                    >
                        <!-- The Ink opening card ------------------------------>
                        <div class="rounded-2xl bg-ink p-5 text-cream sm:p-7">
                            <div
                                class="flex items-baseline justify-between gap-4 font-mono text-[0.5625rem] tracking-eyebrow uppercase rtl:tracking-normal"
                            >
                                <span class="flex items-center gap-2">
                                    <span
                                        class="size-2 rounded-[2px] bg-lime"
                                        aria-hidden="true"
                                    />
                                    <span class="text-cream"
                                        >{{ screen.n }} —
                                        {{ screen.eyebrow }}</span
                                    >
                                </span>
                                <span class="hidden text-cream/50 sm:inline">{{
                                    screen.meta
                                }}</span>
                            </div>
                            <div
                                class="mt-3 h-px w-full bg-cream/15"
                                aria-hidden="true"
                            />

                            <div
                                class="mt-5 flex items-start justify-between gap-6"
                            >
                                <div class="min-w-0">
                                    <p
                                        class="text-2xl leading-[1.05] font-bold tracking-tight text-balance sm:text-3xl"
                                    >
                                        {{ screen.head }}<br />
                                        <span class="text-lime">{{
                                            screen.accent
                                        }}</span>
                                    </p>
                                    <span
                                        class="mt-4 block h-1 w-10 rounded-full bg-orange"
                                        aria-hidden="true"
                                    />
                                    <p
                                        class="mt-4 max-w-sm text-xs leading-relaxed text-cream/70 sm:text-sm"
                                    >
                                        {{ screen.lead }}
                                    </p>
                                </div>

                                <div class="hidden shrink-0 text-end lg:block">
                                    <p
                                        class="font-mono text-[0.5625rem] tracking-eyebrow text-cream/60 uppercase rtl:tracking-normal"
                                    >
                                        {{ screen.stat.label }}
                                    </p>
                                    <p
                                        class="mt-1 text-5xl leading-none font-bold text-lime"
                                    >
                                        {{ screen.stat.value }}
                                    </p>
                                    <p class="mt-2 text-xs text-cream/60">
                                        {{ screen.stat.caption }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <!-- 05 · Lead discovery ------------------------------->
                        <div
                            v-if="screen.key === 'discovery'"
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <p
                                class="font-mono text-[0.5625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                            >
                                {{ t('Sources') }}
                            </p>
                            <ul class="mt-4 space-y-4">
                                <li
                                    v-for="provider in providers"
                                    :key="provider.name"
                                >
                                    <div
                                        class="flex items-baseline justify-between gap-3 text-xs"
                                    >
                                        <span class="font-medium">{{
                                            provider.name
                                        }}</span>
                                        <span
                                            class="font-mono text-muted-foreground"
                                            >{{ provider.found }}</span
                                        >
                                    </div>
                                    <div
                                        class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-secondary"
                                    >
                                        <div
                                            class="h-full rounded-full bg-teal transition-[width] duration-700 ease-brand motion-reduce:transition-none"
                                            :style="{
                                                width:
                                                    index === active
                                                        ? `${provider.progress}%`
                                                        : '0%',
                                            }"
                                        />
                                    </div>
                                </li>
                            </ul>
                        </div>

                        <!-- 06 · Leads ---------------------------------------->
                        <div
                            v-else-if="screen.key === 'leads'"
                            class="overflow-hidden rounded-2xl border border-border bg-card"
                        >
                            <ul class="divide-y divide-border">
                                <li
                                    v-for="lead in leads"
                                    :key="lead.name"
                                    class="flex items-center gap-3 px-5 py-3"
                                >
                                    <span
                                        class="flex size-7 shrink-0 items-center justify-center rounded-full font-mono text-[0.625rem] font-semibold"
                                        :class="
                                            lead.grade === 'A'
                                                ? 'bg-success text-success-foreground'
                                                : 'bg-accent text-accent-foreground'
                                        "
                                        >{{ lead.grade }}</span
                                    >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-xs font-medium"
                                            >{{ lead.name }}</span
                                        >
                                        <span
                                            class="block truncate text-[0.6875rem] text-muted-foreground"
                                            >{{ lead.place }}</span
                                        >
                                    </span>
                                    <span
                                        class="shrink-0 font-mono text-xs text-teal"
                                        >{{ lead.score }}</span
                                    >
                                </li>
                            </ul>
                        </div>

                        <!-- 07 · Signals -------------------------------------->
                        <div
                            v-else-if="screen.key === 'signals'"
                            class="overflow-hidden rounded-2xl border border-border bg-card"
                        >
                            <ul class="divide-y divide-border">
                                <li
                                    v-for="signal in signals"
                                    :key="signal.company"
                                    class="flex items-center gap-3 px-5 py-3.5"
                                >
                                    <span class="min-w-0 flex-1">
                                        <span
                                            class="block truncate text-xs font-medium"
                                            >{{ signal.company }}</span
                                        >
                                        <span
                                            class="block truncate text-[0.6875rem] text-muted-foreground"
                                            >{{ signal.when }}</span
                                        >
                                    </span>
                                    <!--
                                      The kind of signal is a category, not a status, so it takes
                                      the neutral accent chip. Painting "funding round" in
                                      `status.success` would say something the data does not —
                                      the same mistake the book forbids with Signal Orange.
                                    -->
                                    <span
                                        class="shrink-0 rounded-full bg-accent px-2.5 py-1 text-[0.6875rem] font-medium text-accent-foreground"
                                        >{{ signal.kind }}</span
                                    >
                                </li>
                            </ul>
                        </div>

                        <!-- 08 · Outreach ------------------------------------->
                        <div
                            v-else
                            class="rounded-2xl border border-border bg-card p-5"
                        >
                            <p class="text-xs font-semibold">
                                {{
                                    t(
                                        'Congratulations on the Series A, Meridian',
                                    )
                                }}
                            </p>
                            <div class="mt-3 space-y-1.5" aria-hidden="true">
                                <span
                                    class="block h-1.5 w-full rounded-full bg-secondary"
                                />
                                <span
                                    class="block h-1.5 w-11/12 rounded-full bg-secondary"
                                />
                                <span
                                    class="block h-1.5 w-9/12 rounded-full bg-secondary"
                                />
                            </div>
                            <ul class="mt-4 flex flex-wrap gap-2">
                                <li
                                    v-for="guard in guards"
                                    :key="guard"
                                    class="rounded-full bg-accent px-2.5 py-1 text-[0.6875rem] font-medium text-accent-foreground"
                                >
                                    {{ guard }}
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>
            </div>
        </div>

        <!-- Tabs ---------------------------------------------------------------->
        <div
            ref="tablist"
            role="tablist"
            :aria-label="t('Product screens')"
            class="mt-5 flex flex-wrap justify-center gap-2"
        >
            <button
                v-for="(screen, index) in screens"
                :id="`showcase-tab-${screen.key}`"
                :key="screen.key"
                type="button"
                role="tab"
                :aria-selected="index === active"
                :aria-controls="`showcase-panel-${screen.key}`"
                :tabindex="index === active ? 0 : -1"
                class="duration-base flex items-center gap-2 rounded-full px-4 py-2 text-sm font-medium transition-colors ease-brand focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                :class="
                    index === active
                        ? 'bg-ink text-cream'
                        : 'text-muted-foreground hover:bg-accent hover:text-accent-foreground'
                "
                @click="select(index)"
                @keydown="onTabKeydown($event, index)"
            >
                <span
                    class="font-mono text-[0.625rem]"
                    :class="
                        index === active ? 'text-lime' : 'text-muted-foreground'
                    "
                    >{{ screen.n }}</span
                >
                {{ screen.tab }}
            </button>
        </div>

        <p
            class="mt-4 text-center font-mono text-[0.625rem] text-muted-foreground"
        >
            {{
                t(
                    'Illustrative screens. The companies and figures are made up.',
                )
            }}
        </p>
    </div>
</template>
