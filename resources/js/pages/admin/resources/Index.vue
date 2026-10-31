<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Plus, Search } from '@lucide/vue';
import { reactive, watch } from 'vue';
import ResourceCell from '@/components/admin/ResourceCell.vue';
import Pagination from '@/components/Pagination.vue';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import type {
    AdminIndexFilters,
    AdminResourceMeta,
    AdminRow,
    AdminTeamOption,
} from '@/types';
import type { PaginationLink } from '@/types';

/*
 * One index page for all forty resources (Phase 19a).
 *
 * It renders entirely from the descriptor: the columns, the filters, whether there is a search box,
 * which columns can be sorted, and whether a create button belongs here. That is what spares the
 * panel forty near-identical screens — and why adding a resource is a config line plus a
 * descriptor, with no frontend change at all.
 *
 * **Server-driven filtering**, like the leads list and the dashboard: the state is in the query
 * string, so an index an operator pastes into a ticket reproduces exactly what they were looking
 * at. That is most of the value of having the panel, and it is also why the search box debounces
 * into a visit rather than filtering a client-side array — there is no client-side array, because
 * these tables do not fit in one.
 */
const props = defineProps<{
    resource: AdminResourceMeta;
    filters: AdminIndexFilters;
    teams: AdminTeamOption[];
    rows: AdminRow[];
    links: PaginationLink[];
    meta: {
        current_page: number;
        last_page: number;
        from: number | null;
        to: number | null;
        total: number;
    };
}>();

const state = reactive({
    search: props.filters.search ?? '',
    team_id: props.filters.team_id ? String(props.filters.team_id) : '',
    filters: { ...props.filters.filters },
});

let debounce: ReturnType<typeof setTimeout> | undefined;

function visit(immediate = false): void {
    if (debounce) {
        clearTimeout(debounce);
    }

    const go = () =>
        router.get(
            `/admin/resources/${props.resource.key}`,
            {
                search: state.search || undefined,
                team_id: state.team_id || undefined,
                filters: state.filters,
                sort: props.filters.sort ?? undefined,
                direction: props.filters.direction,
            },
            { preserveState: true, preserveScroll: true, replace: true },
        );

    if (immediate) {
        go();

        return;
    }

    debounce = setTimeout(go, 350);
}

watch(
    () => state.search,
    () => visit(),
);

function sortBy(column: string): void {
    if (!props.resource.sortable.includes(column)) {
        return;
    }

    const direction =
        props.filters.sort === column && props.filters.direction === 'desc'
            ? 'asc'
            : 'desc';

    router.get(
        `/admin/resources/${props.resource.key}`,
        {
            search: state.search || undefined,
            team_id: state.team_id || undefined,
            filters: state.filters,
            sort: column,
            direction,
        },
        { preserveState: true, preserveScroll: true, replace: true },
    );
}
</script>

<template>
    <Head :title="resource.label" />

    <div class="flex flex-col gap-6">
        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="max-w-2xl">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    {{ resource.group_label }}
                </p>
                <h1 class="mt-1 text-2xl font-semibold text-foreground">
                    {{ resource.label }}
                </h1>
                <p
                    v-if="!resource.can_update && !resource.can_delete"
                    class="mt-2 text-sm text-muted-foreground"
                >
                    Read-only. Changes to these records go through the module
                    that owns them.
                </p>
            </div>

            <Button v-if="resource.can_create" as-child>
                <Link :href="`/admin/resources/${resource.key}/create`">
                    <Plus class="size-4" />
                    New {{ resource.singular.toLowerCase() }}
                </Link>
            </Button>
        </header>

        <section
            class="flex flex-wrap items-end gap-3"
            aria-label="Filter this list"
        >
            <div v-if="resource.searchable" class="min-w-56 flex-1">
                <Label :for="`${resource.key}-search`" class="sr-only">
                    Search {{ resource.label }}
                </Label>
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Input
                        :id="`${resource.key}-search`"
                        v-model="state.search"
                        class="ps-9"
                        type="search"
                        placeholder="Search…"
                    />
                </div>
            </div>

            <div v-if="resource.tenant_owned && teams.length > 0">
                <Label :for="`${resource.key}-team`">Tenant</Label>
                <NativeSelect
                    :id="`${resource.key}-team`"
                    v-model="state.team_id"
                    @change="visit(true)"
                >
                    <option value="">Every tenant</option>
                    <option
                        v-for="team in teams"
                        :key="team.id"
                        :value="String(team.id)"
                    >
                        {{ team.name }}
                    </option>
                </NativeSelect>
            </div>

            <div v-for="filter in resource.filters" :key="filter.name">
                <Label :for="`${resource.key}-${filter.name}`">
                    {{ filter.label }}
                </Label>
                <NativeSelect
                    :id="`${resource.key}-${filter.name}`"
                    v-model="state.filters[filter.name]"
                    @change="visit(true)"
                >
                    <option value="">Any</option>
                    <option
                        v-for="(label, value) in filter.options"
                        :key="value"
                        :value="value"
                    >
                        {{ label }}
                    </option>
                </NativeSelect>
            </div>
        </section>

        <EmptyState
            v-if="rows.length === 0"
            title="Nothing matches"
            description="Try a wider filter, or clear the tenant to look across all of them."
        />

        <div v-else class="overflow-x-auto rounded-2xl border border-border">
            <Table>
                <TableHeader>
                    <TableRow>
                        <TableHead
                            v-for="column in resource.columns"
                            :key="column.name"
                            :class="column.numeric ? 'text-end' : undefined"
                            :aria-sort="
                                filters.sort === column.name
                                    ? filters.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : undefined
                            "
                        >
                            <!-- `aria-sort` on the cell, not just a label on the button: the arrow
                                 is decorative and `aria-hidden`, so without it a screen-reader user
                                 can tell what a column sorts by and not which one is sorted. -->
                            <button
                                v-if="resource.sortable.includes(column.name)"
                                type="button"
                                class="cursor-pointer rounded-sm underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                :aria-label="`Sort by ${column.label}`"
                                @click="sortBy(column.name)"
                            >
                                {{ column.label }}
                                <span
                                    v-if="filters.sort === column.name"
                                    aria-hidden="true"
                                >
                                    {{
                                        filters.direction === 'asc' ? '↑' : '↓'
                                    }}
                                </span>
                            </button>
                            <template v-else>{{ column.label }}</template>
                        </TableHead>
                        <TableHead class="w-0">
                            <span class="sr-only">Open</span>
                        </TableHead>
                    </TableRow>
                </TableHeader>

                <TableBody>
                    <TableRow v-for="row in rows" :key="String(row.id)">
                        <TableCell
                            v-for="column in resource.columns"
                            :key="column.name"
                            :class="column.numeric ? 'text-end' : undefined"
                        >
                            <ResourceCell
                                :column="column"
                                :value="row.cells[column.name] ?? null"
                                :row="row.cells"
                            />
                        </TableCell>
                        <TableCell class="text-end">
                            <Button variant="ghost" size="sm" as-child>
                                <Link
                                    :href="`/admin/resources/${resource.key}/${row.id}`"
                                    :aria-label="`Open ${row.title}`"
                                >
                                    Open
                                </Link>
                            </Button>
                        </TableCell>
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <Pagination
            v-if="rows.length > 0"
            :links="links"
            :meta="meta"
            :label="resource.label"
        />
    </div>
</template>
