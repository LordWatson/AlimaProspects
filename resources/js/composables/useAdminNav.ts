import { usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    ChartNoAxesColumn,
    CircuitBoard,
    LayoutGrid,
    ListTodo,
    Megaphone,
    Radar,
    ScrollText,
    Send,
    ShieldCheck,
    SlidersHorizontal,
    UserPlus,
    type LucideIcon,
} from '@lucide/vue';
import { computed, type ComputedRef } from 'vue';
import type { AdminNavSection } from '@/types';

/*
 * Every destination in the panel, in one place (the 19d overhaul).
 *
 * **Why this exists.** The rail and the ⌘K palette answer the same question — "where can an
 * operator go?" — and they used to answer it twice: the rail from hard-coded arrays, the palette
 * not at all. Two lists of forty-odd destinations is one list that goes stale. The registry half
 * is still server-driven (`ResourceRegistry::navigation()`); this adds the bespoke screens, which
 * have no table to derive them from, and gives both consumers one shape.
 *
 * It also fixes the base path: `ADMIN_PATH` moves the panel, so every URL is built from the
 * `panel.path` the server shares rather than from a hard-coded `/admin`.
 */

export type AdminDestination = {
    label: string;
    href: string;
    icon: LucideIcon;
    /** `exact` for the panel's front page, which every other URL starts with. */
    match?: 'exact' | 'startsWith';
    /** Extra words the palette matches on — what somebody would type looking for this. */
    keywords?: string;
};

export type AdminGroup = {
    key: string;
    label: string;
    /** The registry's groups collapse; the two bespoke groups do not. */
    collapsible: boolean;
    destinations: AdminDestination[];
};

export type UseAdminNav = {
    /** The panel's base path, from the server. */
    base: ComputedRef<string>;
    /** Everything, grouped the way the rail renders it. */
    groups: ComputedRef<AdminGroup[]>;
    /** The same thing flattened, for the palette. */
    all: ComputedRef<(AdminDestination & { group: string })[]>;
};

export function useAdminNav(): UseAdminNav {
    const page = usePage();

    const base = computed<string>(
        () => (page.props.panel as { path?: string } | null)?.path ?? '/admin',
    );

    const sections = computed<AdminNavSection[]>(
        () => (page.props.navigation ?? []) as AdminNavSection[],
    );

    const groups = computed<AdminGroup[]>(() => {
        const url = (suffix: string): string => base.value + suffix;

        const built: AdminGroup[] = [
            {
                key: 'watch',
                // What an operator opens without being asked to: the state of the platform.
                label: 'Keep an eye on',
                collapsible: false,
                destinations: [
                    {
                        label: 'Operations',
                        href: base.value,
                        icon: LayoutGrid,
                        match: 'exact',
                        keywords: 'home dashboard overview pulse',
                    },
                    {
                        label: 'Reporting',
                        href: url('/reporting'),
                        icon: ChartNoAxesColumn,
                        keywords: 'mrr churn funnel margin revenue growth',
                    },
                    {
                        label: 'Queues and jobs',
                        href: url('/operations'),
                        icon: ListTodo,
                        keywords: 'failed jobs horizon schedule workers pulse',
                    },
                    {
                        label: 'Provider health',
                        href: url('/providers'),
                        icon: CircuitBoard,
                        keywords: 'serper stripe openai circuit breaker outage',
                    },
                    {
                        label: 'Signal runs',
                        href: url('/signal-runs'),
                        icon: Radar,
                        keywords: 'signals watches cost failures',
                    },
                    {
                        label: 'Signal library',
                        href: url('/signal-library'),
                        icon: BookOpen,
                        keywords:
                            'signals presets packs submissions promote curate library adoption',
                    },
                    {
                        label: 'Outreach',
                        href: url('/outreach'),
                        icon: Send,
                        keywords: 'sending suppression bounces messages',
                    },
                    {
                        label: 'Compliance',
                        href: url('/compliance'),
                        icon: ShieldCheck,
                        keywords: 'dsar erasure retention lawful basis',
                    },
                    {
                        label: 'Audit trail',
                        href: url('/audit'),
                        icon: ScrollText,
                        keywords: 'who did what reads writes impersonation',
                    },
                ],
            },
            {
                key: 'do',
                // Things an operator came here to change. Separated because a destination that
                // writes should not sit in a list of destinations that read.
                label: 'Do something',
                collapsible: false,
                destinations: [
                    {
                        label: 'Onboard a business',
                        href: url('/provisioning'),
                        icon: UserPlus,
                        keywords: 'new team create tenant trial provision',
                    },
                    {
                        label: 'Announcements',
                        href: url('/announcements'),
                        icon: Megaphone,
                        keywords: 'banner notice maintenance message tenants',
                    },
                    {
                        label: 'Platform settings',
                        href: url('/settings'),
                        icon: SlidersHorizontal,
                        keywords:
                            'switches flags stripe capabilities kill switch',
                    },
                ],
            },
        ];

        // The registry: one group per section the server sent, each collapsible, because this is
        // forty-four destinations and an operator opens two of them a week.
        for (const section of sections.value) {
            built.push({
                key: 'registry:' + section.label,
                label: section.label,
                collapsible: true,
                destinations: section.items.map((item) => ({
                    label: item.label,
                    href: `${base.value}/resources/${item.key}`,
                    icon: Building2,
                    keywords: item.key.replace(/-/g, ' '),
                })),
            });
        }

        return built;
    });

    const all = computed(() =>
        groups.value.flatMap((group) =>
            group.destinations.map((destination) => ({
                ...destination,
                group: group.label,
            })),
        ),
    );

    return { base, groups, all };
}
