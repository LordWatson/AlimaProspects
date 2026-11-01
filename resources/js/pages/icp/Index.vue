<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Ban, Check, Pencil, Plus, Star, Target, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import MeterBar from '@/components/MeterBar.vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import { importanceKey } from '@/lib/importance';
import type { Icp, IcpCriterion } from '@/types';

/*
 * The team's ideal-customer profiles.
 *
 * The cards used to list a profile's signals as a row of equal-looking chips with a number stuck
 * on the end — which is exactly backwards for this screen. A profile *is* its weighting: the whole
 * question a member has when they open this page is "what is Alima actually looking for, and what
 * does it care about most". So each signal now gets a bar, the heaviest first, and the numbers
 * stay beside them because a bar is a comparison and a number is a fact.
 *
 * The active profile is also hoisted to the front and given the full width. One of these decides
 * how every lead in the account is graded; it should not be the third card on the second row
 * because somebody created it on a Tuesday.
 */
defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Ideal customers', href: '/icp' }],
    },
});

const props = defineProps<{
    icps: Icp[];
    can: { manage: boolean };
}>();

const { t } = useI18n();
const { date, number } = useFormat();

// Which card is awaiting delete confirmation (inline, no modal).
const confirmingDelete = ref<number | null>(null);

/** Signals, heaviest first — the order the member is asking about. */
function signals(icp: Icp): IcpCriterion[] {
    return icp.criteria
        .filter((c) => !c.is_exclusion)
        .slice()
        .sort((a, b) => (b.weight ?? 0) - (a.weight ?? 0));
}

function exclusions(icp: Icp): IcpCriterion[] {
    return icp.criteria.filter((c) => c.is_exclusion);
}

/**
 * The heaviest signal in the profile, so every bar in one card is a fraction of the same thing.
 * Scaling each bar to 100 instead would draw a profile of three 40s as three near-empty bars and
 * say nothing about which of them leads.
 */
function topWeight(icp: Icp): number {
    return Math.max(1, ...signals(icp).map((c) => c.weight ?? 0));
}

/** What a weight means, in words — the same scale the editor shows (`lib/importance`). */
function importance(weight: number | null): string {
    const key = importanceKey(weight);

    return key === null ? '' : t(key);
}

// The active profile first, and the rest in the order the server sent them.
const ordered = computed(() =>
    [...props.icps].sort((a, b) => Number(b.is_active) - Number(a.is_active)),
);

function activate(icp: Icp): void {
    router.post(`/icp/${icp.id}/activate`, {}, { preserveScroll: true });
}

function destroy(icp: Icp): void {
    router.delete(`/icp/${icp.id}`, {
        preserveScroll: true,
        onFinish: () => (confirmingDelete.value = null),
    });
}
</script>

<template>
    <Head :title="t('Ideal customers')" />

    <div class="flex flex-col gap-6 py-2">
        <PageHero
            index="04"
            :eyebrow="t('Ideal customers')"
            :title="t('Who you’re|looking for.')"
            :description="
                t(
                    'Describe the businesses you want to win, and how much each signal matters. Alima uses your active profile to find and score the right prospects.',
                )
            "
        >
            <template v-if="can.manage && icps.length" #actions>
                <Button variant="lime" as-child>
                    <Link href="/icp/create" data-test="new-icp">
                        <Plus class="size-4" />
                        {{ t('New profile') }}
                    </Link>
                </Button>
            </template>
        </PageHero>

        <!-- Empty state -->
        <EmptyState
            v-if="!icps.length"
            :icon="Target"
            :title="t('Define your ideal customer')"
            :description="
                t(
                    'Start with a profile of the businesses you serve best. It takes a minute, and you can refine it any time.',
                )
            "
        >
            <Button v-if="can.manage" variant="brand" as-child>
                <Link href="/icp/create" data-test="new-icp-empty">
                    <Plus class="size-4" />
                    {{ t('Create your first profile') }}
                </Link>
            </Button>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Ask an owner or admin to set one up.') }}
            </p>
        </EmptyState>

        <!-- ICP cards. The active one takes the full row: it is the one deciding every grade in
             the account, so it reads as the headline rather than as one of several. -->
        <ul v-else class="grid gap-5 lg:grid-cols-2">
            <li
                v-for="icp in ordered"
                :key="icp.id"
                class="flex flex-col rounded-2xl border bg-card p-6 shadow-sm transition-colors"
                :class="
                    icp.is_active
                        ? 'border-brand ring-1 ring-brand/30 lg:col-span-2'
                        : 'border-border'
                "
                :data-test="`icp-${icp.id}`"
            >
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h2
                                class="truncate text-lg font-semibold text-foreground rtl:font-bold"
                                dir="auto"
                            >
                                {{ icp.name }}
                            </h2>
                            <Badge v-if="icp.is_active" variant="brand">
                                <Star class="size-3" />
                                {{ t('Active') }}
                            </Badge>
                        </div>
                        <p
                            v-if="icp.description"
                            class="line-clamp-2 text-sm text-muted-foreground"
                            dir="auto"
                        >
                            {{ icp.description }}
                        </p>
                        <p v-if="icp.is_active" class="text-sm text-brand">
                            {{
                                t(
                                    'Alima grades every new lead against this profile.',
                                )
                            }}
                        </p>
                    </div>

                    <!-- The profile's shape, in one mono line: what it looks for, what it skips,
                         and when somebody last changed it. -->
                    <p
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{
                            t(':count signals', {
                                count: number(signals(icp).length),
                            })
                        }}
                        <template v-if="exclusions(icp).length">
                            ·
                            {{
                                t(':count exclusions', {
                                    count: number(exclusions(icp).length),
                                })
                            }}
                        </template>
                        <template v-if="icp.updated_at">
                            ·
                            {{
                                t('Edited :date', {
                                    date: date(icp.updated_at),
                                })
                            }}
                        </template>
                    </p>
                </div>

                <!-- Signals, heaviest first, with their weight as a bar -->
                <div class="mt-5 flex-1 space-y-3">
                    <ul
                        v-if="signals(icp).length"
                        class="space-y-2"
                        :aria-label="t('Signals, most important first')"
                    >
                        <li
                            v-for="c in signals(icp).slice(0, 5)"
                            :key="c.id"
                            class="flex items-center gap-3"
                        >
                            <span class="min-w-0 flex-1 truncate text-sm">
                                <span class="text-foreground" dir="auto">{{
                                    c.value
                                }}</span>
                                <span class="text-muted-foreground">
                                    · {{ t(c.type_label) }}</span
                                >
                            </span>
                            <MeterBar
                                :value="c.weight ?? 0"
                                :max="topWeight(icp)"
                                width="sm"
                                :label="
                                    t(':importance — :weight out of 100', {
                                        importance: importance(c.weight),
                                        weight: c.weight ?? 0,
                                    })
                                "
                            />
                            <span
                                class="w-8 shrink-0 text-end font-mono text-xs text-muted-foreground tabular-nums"
                                aria-hidden="true"
                                dir="ltr"
                                >{{ c.weight }}</span
                            >
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('No signals yet.') }}
                    </p>

                    <p
                        v-if="signals(icp).length > 5"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t('and :count more', {
                                count: number(signals(icp).length - 5),
                            })
                        }}
                    </p>

                    <!-- Exclusions are a different kind of rule, so they read differently: no
                         weight, because they are absolute. -->
                    <div
                        v-if="exclusions(icp).length"
                        class="flex flex-wrap items-center gap-1.5 border-t border-border pt-3"
                    >
                        <Ban
                            class="size-3.5 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <span class="text-xs text-muted-foreground">{{
                            t('Always skip:')
                        }}</span>
                        <Badge
                            v-for="c in exclusions(icp).slice(0, 6)"
                            :key="c.id"
                            variant="outline"
                            class="border-input"
                            dir="auto"
                            >{{ c.value }}</Badge
                        >
                        <span
                            v-if="exclusions(icp).length > 6"
                            class="text-xs text-muted-foreground"
                            >+{{ exclusions(icp).length - 6 }}</span
                        >
                    </div>
                </div>

                <!-- Actions -->
                <div
                    v-if="can.manage"
                    class="mt-6 flex items-center gap-2 border-t border-border pt-4"
                >
                    <template v-if="confirmingDelete === icp.id">
                        <span class="flex-1 text-sm text-foreground" dir="auto">
                            {{
                                t('Delete “:name”? This cannot be undone.', {
                                    name: icp.name,
                                })
                            }}
                        </span>
                        <Button
                            variant="ghost"
                            size="sm"
                            @click="confirmingDelete = null"
                        >
                            {{ t('Cancel') }}
                        </Button>
                        <Button
                            variant="destructive"
                            size="sm"
                            :data-test="`confirm-delete-${icp.id}`"
                            @click="destroy(icp)"
                        >
                            {{ t('Delete') }}
                        </Button>
                    </template>
                    <template v-else>
                        <Button
                            v-if="!icp.is_active"
                            variant="outline"
                            size="sm"
                            :data-test="`activate-${icp.id}`"
                            @click="activate(icp)"
                        >
                            <Check class="size-4" />
                            {{ t('Make active') }}
                        </Button>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="`/icp/${icp.id}/edit`">
                                <Pencil class="size-4" />
                                {{ t('Edit') }}
                            </Link>
                        </Button>
                        <span class="flex-1" />
                        <Button
                            variant="ghost"
                            size="icon-sm"
                            class="text-muted-foreground hover:text-destructive"
                            :aria-label="t('Delete :name', { name: icp.name })"
                            :data-test="`delete-${icp.id}`"
                            @click="confirmingDelete = icp.id"
                        >
                            <Trash2 class="size-4" />
                        </Button>
                    </template>
                </div>
            </li>
        </ul>
    </div>
</template>
