<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';

/*
 * The panel's front page (Phase 19a).
 *
 * Deliberately a handful of counts and the suspension list, and nothing more. **Platform reporting
 * is Phase 19c** and is a different thing built differently — MRR, churn, the onboarding funnel and
 * gross margin per team, aggregated over a nightly rollup rather than counted live. What is here is
 * the set an operator wants on the way to somewhere else.
 *
 * The suspension list is the one table on this page because it is the only platform state that is
 * actively costing somebody something: every row is a customer who cannot source, send or spend
 * right now, and a suspension nobody lifts is a suspension nobody meant to be permanent.
 */
defineProps<{
    stats: {
        teams: number;
        teams_suspended: number;
        users: number;
        operators: number;
        leads: number;
        discovery_runs_24h: number;
        ai_requests_24h: number;
        ai_cost_micros_24h: number;
    };
    suspended: {
        id: number;
        name: string;
        suspended_at: string | null;
        reason: string | null;
    }[];
}>();

const { number, dateTime } = useFormat();

/*
 * The AI cost tile is in **cost micro-units**, not money, and the hint says so rather than the
 * label wearing the jargon. Converting it would mean asserting a currency: a cross-tenant sum is
 * what *we* paid providers, not what any one tenant is billed in, and `BuildAiUsageAction` only
 * gets away with `intdiv($micros, 10_000)` because it is scoped to one team's market. The
 * platform's own currency is settled in Phase 19c, alongside gross margin per team.
 */
const tiles = [
    { key: 'teams', label: 'Tenants', hint: undefined },
    {
        key: 'teams_suspended',
        label: 'Suspended',
        hint: 'Cannot source, send or spend',
    },
    { key: 'users', label: 'People', hint: undefined },
    {
        key: 'operators',
        label: 'Operators',
        hint: 'Accounts with a platform role',
    },
    { key: 'leads', label: 'Prospects', hint: undefined },
    {
        key: 'discovery_runs_24h',
        label: 'Discovery runs · 24h',
        hint: undefined,
    },
    { key: 'ai_requests_24h', label: 'AI calls · 24h', hint: undefined },
    {
        key: 'ai_cost_micros_24h',
        label: 'AI cost · 24h',
        hint: 'Provider cost in micro-units (1,000,000 to the major unit)',
    },
] as const;
</script>

<template>
    <Head title="Operations" />

    <div class="flex flex-col gap-8">
        <header class="max-w-2xl">
            <p
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
            >
                Platform
            </p>
            <h1 class="mt-1 text-2xl font-semibold text-foreground">
                Operations
            </h1>
            <p class="mt-2 text-sm text-muted-foreground">
                Every record in the product, across every tenant. Reads of
                tenant data are audited.
            </p>
        </header>

        <section aria-label="Platform counts">
            <dl class="grid grid-cols-2 gap-3 md:grid-cols-4">
                <Card v-for="tile in tiles" :key="tile.key" class="shadow-none">
                    <CardContent class="px-4 py-4">
                        <dt
                            class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                            :title="tile.hint"
                        >
                            {{ tile.label }}
                        </dt>
                        <dd
                            class="mt-1 text-2xl font-semibold text-foreground tabular-nums"
                        >
                            {{ number(stats[tile.key]) }}
                        </dd>
                    </CardContent>
                </Card>
            </dl>
        </section>

        <section class="flex flex-col gap-3" aria-label="Suspended tenants">
            <h2 class="text-lg font-semibold text-foreground">
                Suspended tenants
            </h2>

            <EmptyState
                v-if="suspended.length === 0"
                title="Nobody is suspended"
                description="A suspended tenant keeps reading its own data but cannot source, send or spend."
            />

            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="team in suspended"
                    :key="team.id"
                    class="flex flex-wrap items-center gap-x-4 gap-y-2 rounded-2xl border border-border bg-card px-4 py-3"
                >
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium text-foreground">
                            {{ team.name }}
                        </p>
                        <p class="mt-0.5 text-sm text-muted-foreground">
                            {{ team.reason ?? 'No reason recorded.' }}
                        </p>
                    </div>

                    <Badge variant="warning">
                        {{
                            team.suspended_at
                                ? dateTime(team.suspended_at)
                                : 'Suspended'
                        }}
                    </Badge>

                    <Button variant="outline" size="sm" as-child>
                        <Link
                            :href="`/admin/teams/${team.id}`"
                            :aria-label="`Open ${team.name}`"
                        >
                            Open
                        </Link>
                    </Button>
                </li>
            </ul>
        </section>
    </div>
</template>
