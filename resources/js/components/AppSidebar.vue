<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { ChevronsUpDown } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import PandaFace from '@/components/PandaFace.vue';
import UserMenuContent from '@/components/UserMenuContent.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useCurrentUrl } from '@/composables/useCurrentUrl';
import { useI18n } from '@/composables/useI18n';
import { dashboard } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import type { AppCapability } from '@/types';

/*
 * Alima app sidebar — a deep-teal editorial rail of labels and numbers, no icons (the Alima
 * Planner reference). The active item is an Acid
 * Lime pill (lime is the shell's hero accent — decorative, never a status).
 * Shared by the desktop rail and the mobile drawer. Only real destinations
 * appear here. Logical properties mirror under RTL; the logo never mirrors.
 *
 * The rail reads as chapters, because a member thinks "my business", "my
 * leads", "reporting" rather than one list of ten links. The numbering stays a
 * single run (01–09) in pipeline order across the chapters rather than
 * restarting in each: the numbers are the editorial device, and the marketing
 * drawing in `ProductShowcase.vue` quotes them as the product's own sequence.
 */
const { t } = useI18n();
const { isCurrentUrl, isCurrentOrParentUrl } = useCurrentUrl();
const page = usePage();

type Item = {
    n?: string;
    title: string;
    href: string;
    match?: 'exact' | 'startsWith';
    /**
     * A plan feature this destination needs (Phase 19b). The item is dropped when the team's plan
     * does not include it — a link to a screen that will refuse is a promise the product then
     * breaks. The route middleware and the Action's guard are what *enforce* it; this is only what
     * stops us advertising it.
     */
    capability?: AppCapability;
    /**
     * A pre-launch flag this destination waits on (ADR 0026 §4). Dropped while the flag is off,
     * because those routes answer **404** rather than refusing politely — a nav item that 404s
     * reads as a broken product rather than an unfinished one.
     */
    flag?: 'prelaunch.webhooks';
};

/**
 * A chapter is a group of destinations under one eyebrow. The opening chapter
 * carries no label — the Dashboard is the rail's first line, not a section of
 * it — and `key` exists to name the eyebrow for `aria-labelledby`.
 */
type Chapter = { key: string; label?: string; items: Item[] };

/**
 * What this team's plan includes, from the shared prop. Missing keys read as **available**, not
 * excluded: a page that did not send the prop should not silently hide half the nav.
 */
const capabilities = computed<Record<string, boolean>>(
    () => (page.props.capabilities ?? {}) as Record<string, boolean>,
);

/** The pre-launch flags on for this team. Absent reads as **none on**, unlike `capabilities`. */
const prelaunch = computed<string[]>(
    () => (page.props.prelaunch ?? []) as string[],
);

function isAvailable(item: Item): boolean {
    if (
        item.capability !== undefined &&
        capabilities.value[item.capability] === false
    ) {
        return false;
    }

    return item.flag === undefined || prelaunch.value.includes(item.flag);
}

const allChapters = computed<Chapter[]>(() => [
    {
        key: 'overview',
        items: [
            {
                n: '01',
                title: t('Dashboard'),
                href: dashboard().url,
            },
        ],
    },
    {
        key: 'business',
        label: t('Business'),
        items: [
            {
                n: '02',
                title: t('Business profile'),
                href: '/onboarding',
                match: 'startsWith',
            },
            {
                n: '03',
                title: t('Background check'),
                href: '/business-intel',
                match: 'startsWith',
            },
            {
                n: '04',
                title: t('Ideal customers'),
                href: '/icp',
                match: 'startsWith',
            },
        ],
    },
    {
        key: 'leads',
        label: t('Leads'),
        items: [
            {
                n: '05',
                title: t('Lead discovery'),
                href: '/discovery',
                match: 'startsWith',
            },
            {
                n: '06',
                title: t('Leads'),
                href: '/leads',
                match: 'startsWith',
            },
            {
                n: '07',
                title: t('Signals'),
                capability: 'signals',
                // `startsWith` so the watch-settings screen keeps the nav item lit — it is the same
                // destination as far as a member is concerned.
                href: '/signals',
                match: 'startsWith',
            },
        ],
    },
    {
        key: 'outreach',
        label: t('Outreach'),
        items: [
            {
                n: '08',
                title: t('Outreach'),
                // The per-lead draft screen is reached from the lead, not from here. What this points at
                // is the team-level half of Outreach — the briefs and the do-not-contact list — and the
                // second of those has to be findable without a lead in hand (docs/compliance.md §3.2).
                href: '/outreach/templates',
                match: 'startsWith',
            },
        ],
    },
    {
        key: 'reporting',
        label: t('Reporting'),
        items: [
            {
                n: '09',
                title: t('Insights'),
                capability: 'ai_insights',
                href: '/insights',
                match: 'startsWith',
            },
        ],
    },
    {
        key: 'settings',
        label: t('Settings'),
        items: [
            {
                // The settings pane itself — profile, security, team, notifications, tokens,
                // appearance. Labelled for what it holds so the item does not echo its chapter.
                title: t('Account'),
                href: editProfile().url,
                match: 'startsWith',
            },
            {
                // Its own screen rather than a tab inside the settings pane, and the reason is the
                // screen: a price list of four plans beside two usage meters does not fit a 36rem
                // column built for forms. It is also every member's to read — when discovery stops
                // mid-afternoon, the person it stopped for should be able to see that the month's
                // allowance is gone.
                title: t('Plan & billing'),
                href: '/billing',
                match: 'startsWith',
            },
            {
                // PL7. Beside Compliance and Billing rather than in the settings pane: the delivery
                // log is a table, and the pane is a 36rem column built for forms.
                title: t('Integrations'),
                flag: 'prelaunch.webhooks',
                href: '/integrations',
                match: 'startsWith',
            },
            {
                // Administration rather than daily work, which is why it sits here and not among the
                // numbered chapters. The do-not-contact list stays under Outreach, where a member
                // reaches for it while working a lead.
                title: t('Compliance'),
                href: '/compliance',
                match: 'startsWith',
            },
        ],
    },
]);

/**
 * Whether this account is a tenant at all.
 *
 * **Every destination in the rail below is team-scoped**, and resolves `currentTeam()` on the
 * server. A platform operator belongs to no team on purpose, so for that account the whole rail was
 * a column of links that answered **404** — which is how a working panel came to read as a broken
 * product. One missing team is not a plan boundary to filter per item; it means none of this exists
 * yet, so the rail says something else entirely.
 */
const hasTeam = computed(() => page.props.auth.currentTeam !== null);

/** An operator, who has a panel to be in rather than a pipeline. */
const isOperator = computed(() => page.props.auth.isOperator === true);

/**
 * The rail an account with no team gets: the things that actually work for it.
 *
 * The panel first where there is one, because that is where an operator is going. No numbering —
 * the editorial run 01–09 describes the product's pipeline, and this is not a shorter pipeline, it
 * is a different kind of account.
 */
const operatorChapters = computed<Chapter[]>(() => {
    const items: Item[] = [];

    if (isOperator.value) {
        items.push({
            title: t('Operations panel'),
            href: '/admin',
            match: 'startsWith',
        });
    }

    items.push({
        title: t('Your account'),
        href: editProfile().url,
        match: 'startsWith',
    });

    return [{ key: 'operator', items }];
});

/**
 * The rail as rendered: chapters with their unavailable destinations removed, and any chapter left
 * empty removed with them — an eyebrow over nothing reads as a broken page rather than as a plan
 * boundary.
 */
const chapters = computed<Chapter[]>(() =>
    hasTeam.value
        ? allChapters.value
              .map((chapter) => ({
                  ...chapter,
                  items: chapter.items.filter(isAvailable),
              }))
              .filter((chapter) => chapter.items.length > 0)
        : operatorChapters.value,
);

const user = computed(() => page.props.auth.user);
const initials = computed(() =>
    (user.value?.name ?? '')
        .split(' ')
        .map((p) => p[0])
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase(),
);

const isActive = (item: Item): boolean =>
    item.match === 'startsWith'
        ? isCurrentOrParentUrl(item.href)
        : isCurrentUrl(item.href);
</script>

<template>
    <div class="flex h-full flex-col bg-teal text-cream">
        <!-- Brand header -->
        <div class="px-6 pt-6 pb-5">
            <Link
                :href="dashboard()"
                class="inline-flex flex-col gap-4 rounded-md focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
            >
                <AppLogo tone="dark" size="sm" />
                <span
                    class="font-mono text-xs tracking-eyebrow text-teal-20 uppercase rtl:tracking-normal"
                    >Prospects</span
                >
            </Link>
        </div>

        <!-- Nav -->
        <nav class="flex-1 overflow-y-auto px-3 pb-4" :aria-label="t('Menu')">
            <div
                v-for="(chapter, index) in chapters"
                :key="chapter.key"
                :class="index === 0 ? 'pt-1' : 'pt-4'"
            >
                <p
                    v-if="chapter.label"
                    :id="`nav-chapter-${chapter.key}`"
                    class="px-3 pb-1.5 font-mono text-xs tracking-eyebrow text-teal-20 uppercase rtl:tracking-normal"
                >
                    {{ chapter.label }}
                </p>
                <ul
                    class="space-y-0.5"
                    :aria-labelledby="
                        chapter.label ? `nav-chapter-${chapter.key}` : undefined
                    "
                >
                    <li v-for="item in chapter.items" :key="item.title">
                        <Link
                            :href="item.href"
                            :aria-current="isActive(item) ? 'page' : undefined"
                            class="group flex w-full items-center gap-3 rounded-full px-3 py-2 text-start text-sm transition-colors duration-150 ease-brand focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
                            :class="
                                isActive(item)
                                    ? 'bg-lime font-semibold text-ink'
                                    : 'text-cream hover:bg-cream/10'
                            "
                        >
                            <span class="flex-1 truncate">{{
                                item.title
                            }}</span>
                            <span
                                v-if="item.n"
                                aria-hidden="true"
                                class="font-mono text-[0.625rem]"
                                :class="
                                    isActive(item)
                                        ? 'text-ink/70'
                                        : 'text-cream/70'
                                "
                                >{{ item.n }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </div>
        </nav>

        <!-- Footer: peek watermark + tagline + user -->
        <div class="flex flex-col gap-3 px-5 pt-4 pb-4">
            <PandaFace
                class="ms-1.5 h-[86px] w-[120px] text-teal-deep"
                aria-hidden="true"
            />

            <p
                class="max-w-[12rem] font-mono text-xs leading-relaxed tracking-eyebrow text-teal-20 uppercase rtl:tracking-normal"
            >
                {{ t('Marketing brighter together.') }}
            </p>

            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <button
                        type="button"
                        class="flex w-full items-center gap-2.5 rounded-full bg-teal-deep py-2 ps-2 pe-3 text-start transition-colors duration-150 hover:bg-ink/40 focus-visible:ring-2 focus-visible:ring-cream focus-visible:outline-none"
                    >
                        <span
                            class="flex size-[26px] shrink-0 items-center justify-center rounded-full bg-cream font-mono text-[0.625rem] text-teal"
                        >
                            {{ initials }}
                        </span>
                        <span class="min-w-0 flex-1">
                            <span
                                class="block truncate text-[0.8125rem] font-medium text-cream"
                                >{{ user?.name }}</span
                            >
                        </span>
                        <ChevronsUpDown class="size-4 shrink-0 text-cream/75" />
                    </button>
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="w-64 rounded-xl"
                    align="end"
                    side="top"
                    :side-offset="8"
                >
                    <UserMenuContent :user="user" />
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    </div>
</template>
