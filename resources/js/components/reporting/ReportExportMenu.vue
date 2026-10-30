<script setup lang="ts">
import { Download } from '@lucide/vue';
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useI18n } from '@/composables/useI18n';
import type { ReportFilterState, ReportKey } from '@/types';

/*
 * Download any report on the screen as CSV.
 *
 * Every report is offered, from one list built off the server's own enum — so a chart cannot ship
 * without its download, and the menu cannot drift out of step with what exists.
 *
 * Plain `<a>` links, not Inertia visits: the response is a streamed file, and an Inertia request
 * would try to read it as a page. The current window rides along in the query string, so the file
 * covers exactly the period on screen.
 */
const props = defineProps<{
    filters: ReportFilterState;
    reports: { value: ReportKey; label: string }[];
}>();

const { t } = useI18n();

const query = computed(() => {
    const params = new URLSearchParams();

    if (props.filters.range !== 'all_time') {
        params.set('range', props.filters.range);
    }

    if (props.filters.range === 'custom') {
        params.set('from', props.filters.from);
        params.set('to', props.filters.to);
    }

    return params;
});

const urlFor = (report: ReportKey): string => {
    const params = new URLSearchParams(query.value);
    params.set('report', report);

    return `/reports/export?${params.toString()}`;
};
</script>

<template>
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <Button
                variant="outline"
                size="sm"
                class="gap-2"
                data-test="report-export-trigger"
            >
                <Download class="size-4" aria-hidden="true" />
                {{ t('Export') }}
            </Button>
        </DropdownMenuTrigger>

        <DropdownMenuContent align="end" class="min-w-56">
            <DropdownMenuLabel class="text-xs text-muted-foreground">
                {{ t('Download as CSV') }}
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="report in reports"
                :key="report.value"
                as-child
            >
                <a
                    :href="urlFor(report.value)"
                    :data-test="`report-export-${report.value}`"
                    download
                >
                    {{ t(report.label) }}
                </a>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
