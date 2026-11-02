<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ArrowLeft, Pencil, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import ResourceCell from '@/components/admin/ResourceCell.vue';
import { Button } from '@/components/ui/button';
import type { AdminCellValue, AdminColumn, AdminResourceMeta } from '@/types';

/*
 * One record, every declared column (Phase 19a).
 *
 * A description list rather than a one-row table, because a record page is read top to bottom and
 * several of these fields are long text or an encoded JSON blob that a table cell mangles. The
 * `detail_columns` the descriptor keeps off the index land here, which is the point of the split.
 *
 * The delete confirmation is two clicks and names the record, because the registry's delete is the
 * one generic operation no module Action is watching — there is no cascade designed for it and
 * nothing to undo it with. It only appears at all for the three resources whose descriptors opted
 * in; everything else is refused by `ResourceWriter` whatever the page offers.
 */
const props = defineProps<{
    resource: AdminResourceMeta;
    title: string;
    record: Record<string, AdminCellValue>;
    record_id: string | number;
}>();

const confirming = ref(false);

const columns = computed<AdminColumn[]>(() => [
    ...props.resource.columns,
    ...props.resource.detail_columns,
]);

function destroy(): void {
    router.delete(`/admin/resources/${props.resource.key}/${props.record_id}`);
}
</script>

<template>
    <Head :title="title" />

    <div class="flex flex-col gap-6">
        <div>
            <Button variant="ghost" size="sm" as-child>
                <Link :href="`/admin/resources/${resource.key}`">
                    <ArrowLeft class="size-4" />
                    {{ resource.label }}
                </Link>
            </Button>
        </div>

        <header class="flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <p
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    {{ resource.singular }} · {{ record_id }}
                </p>
                <h1
                    class="mt-1 text-2xl font-semibold break-words text-foreground"
                >
                    {{ title }}
                </h1>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <Button v-if="resource.can_update" variant="outline" as-child>
                    <Link
                        :href="`/admin/resources/${resource.key}/${record_id}/edit`"
                    >
                        <Pencil class="size-4" />
                        Edit
                    </Link>
                </Button>

                <template v-if="resource.can_delete">
                    <Button
                        v-if="!confirming"
                        variant="outline"
                        @click="confirming = true"
                    >
                        <Trash2 class="size-4" />
                        Delete
                    </Button>
                    <template v-else>
                        <span
                            class="text-sm text-muted-foreground"
                            role="alert"
                        >
                            Delete “{{ title }}” permanently?
                        </span>
                        <!-- The first button vanishes with `v-if`, so without this a keyboard user
                             is dropped to the body mid-decision. -->
                        <Button v-focus variant="destructive" @click="destroy">
                            Yes, delete
                        </Button>
                        <Button variant="ghost" @click="confirming = false">
                            Cancel
                        </Button>
                    </template>
                </template>
            </div>
        </header>

        <dl
            class="grid gap-x-6 gap-y-4 rounded-2xl border border-border bg-card px-5 py-5 sm:grid-cols-2"
        >
            <div
                v-for="column in columns"
                :key="column.name"
                class="min-w-0 flex-col"
            >
                <dt
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase"
                >
                    {{ column.label }}
                </dt>
                <dd class="mt-1 text-sm text-foreground">
                    <ResourceCell
                        :column="column"
                        :value="record[column.name] ?? null"
                        :row="record"
                    />
                </dd>
            </div>
        </dl>
    </div>
</template>
