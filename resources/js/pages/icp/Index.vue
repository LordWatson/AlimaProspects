<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Check, Pencil, Plus, Star, Target, Trash2 } from '@lucide/vue';
import { ref } from 'vue';
import PageHero from '@/components/PageHero.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { useI18n } from '@/composables/useI18n';
import type { Icp } from '@/types';

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

// Which card is awaiting delete confirmation (inline, no modal).
const confirmingDelete = ref<number | null>(null);

function signals(icp: Icp) {
    return icp.criteria.filter((c) => !c.is_exclusion);
}

function exclusions(icp: Icp) {
    return icp.criteria.filter((c) => c.is_exclusion);
}

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

    <div class="flex flex-col gap-8 py-2">
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

        <!-- ICP cards -->
        <ul v-else class="grid gap-5 lg:grid-cols-2">
            <li
                v-for="icp in icps"
                :key="icp.id"
                class="flex flex-col rounded-2xl border bg-card p-6 shadow-sm transition-colors"
                :class="icp.is_active ? 'border-brand' : 'border-border'"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0 space-y-1">
                        <div class="flex items-center gap-2">
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
                    </div>
                </div>

                <!-- Signal chips -->
                <div class="mt-5 flex-1 space-y-3">
                    <p
                        class="font-mono text-[0.625rem] tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                    >
                        {{
                            t(':count signals', {
                                count: signals(icp).length,
                            })
                        }}
                    </p>
                    <ul
                        v-if="signals(icp).length"
                        class="flex flex-wrap gap-1.5"
                    >
                        <li
                            v-for="c in signals(icp).slice(0, 6)"
                            :key="c.id"
                            class="inline-flex items-center gap-1.5 rounded-full bg-brand/10 px-3 py-1 text-xs text-brand"
                            dir="auto"
                        >
                            <span class="truncate">{{ c.value }}</span>
                            <span class="font-mono text-brand">{{
                                c.weight
                            }}</span>
                        </li>
                        <li
                            v-if="signals(icp).length > 6"
                            class="inline-flex items-center rounded-full px-2 py-1 text-xs text-muted-foreground"
                        >
                            +{{ signals(icp).length - 6 }}
                        </li>
                    </ul>
                    <p v-else class="text-sm text-muted-foreground">
                        {{ t('No signals yet.') }}
                    </p>

                    <p
                        v-if="exclusions(icp).length"
                        class="text-xs text-muted-foreground"
                    >
                        {{
                            t(':count exclusions', {
                                count: exclusions(icp).length,
                            })
                        }}
                    </p>
                </div>

                <!-- Actions -->
                <div
                    v-if="can.manage"
                    class="mt-6 flex items-center gap-2 border-t border-border pt-4"
                >
                    <template v-if="confirmingDelete === icp.id">
                        <span class="flex-1 text-sm text-muted-foreground">
                            {{ t('Delete this profile?') }}
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
