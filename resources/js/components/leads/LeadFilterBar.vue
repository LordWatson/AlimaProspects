<script setup lang="ts">
import { computed } from 'vue';
import { FilterGroup, FilterToggle } from '@/components/list';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useI18n } from '@/composables/useI18n';
import type {
    LeadFilterOptions,
    LeadFilterState,
    LeadTag,
    PipelineStage,
    TeamMemberOption,
} from '@/types';

/*
 * The leads list's filters, as the panel behind the toolbar's disclosure.
 *
 * It owns no list state: every change emits upward and the parent re-requests the page from the
 * server, so the URL is always the truth about what is on screen.
 *
 * Two things moved out of here in the list rebuild, and both for the same reason — a panel that is
 * always open is a panel that pushes the list off the screen. **Search and ordering** are controls
 * a member uses on every visit, so they live in the toolbar row that is always visible; these six
 * groups are the ones used occasionally, so they fold away, and what they are *doing* is shown as
 * removable chips in the toolbar rather than inferred from a wall of chips in here.
 *
 * `location` is new. It was always in `LeadFilterState`, the export honoured it and a saved view
 * could carry it — but no control on the screen could set it, so the only way to filter by place
 * was to type the query string by hand. The server takes a two-letter country code or a
 * contains-match across country, region and town (`LeadQueryBuilder::applyCountry`), which is why
 * the field asks for a place rather than offering a country list.
 */
const props = defineProps<{
    filters: LeadFilterState;
    options: LeadFilterOptions;
    /** Whether the team has the why-now feature (PL1); off hides its two groups entirely. */
    whyNow: boolean;
    /** The team's own board, labels and people — all three are filters (Phase 10b). */
    stages: PipelineStage[];
    tags: LeadTag[];
    members: TeamMemberOption[];
}>();

const emit = defineEmits<{
    change: [filters: Partial<LeadFilterState>];
}>();

const { t } = useI18n();

/** Toggle one value in a multi-select filter. */
function toggle<
    K extends
        | 'grades'
        | 'sources'
        | 'enrichment_statuses'
        | 'evidence_sources'
        | 'evidence_kinds',
>(key: K, value: string): void {
    const current = props.filters[key] as string[];
    const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];

    emit('change', { [key]: next } as Partial<LeadFilterState>);
}

function isOn(
    key:
        | 'grades'
        | 'sources'
        | 'enrichment_statuses'
        | 'evidence_sources'
        | 'evidence_kinds',
    value: string,
): boolean {
    return (props.filters[key] as string[]).includes(value);
}

/** The same, for the filters whose values are ids (Phase 10b). */
function toggleId(key: 'stages' | 'tags' | 'assignees', value: number): void {
    const current = props.filters[key];
    const next = current.includes(value)
        ? current.filter((v) => v !== value)
        : [...current, value];

    emit(
        'change',
        key === 'assignees'
            ? // Picking a person and "nobody" at once is a contradiction, so one clears the other.
              ({
                  assignees: next,
                  unassigned: false,
              } as Partial<LeadFilterState>)
            : ({ [key]: next } as Partial<LeadFilterState>),
    );
}

function isIdOn(key: 'stages' | 'tags' | 'assignees', value: number): boolean {
    return props.filters[key].includes(value);
}

function toggleUnassigned(): void {
    emit('change', {
        unassigned: !props.filters.unassigned,
        assignees: [],
    });
}

const hasTeamFilters = computed(
    () =>
        props.stages.length > 0 ||
        props.tags.length > 0 ||
        props.members.length > 0,
);
</script>

<template>
    <div class="space-y-5">
        <div class="grid gap-4 sm:grid-cols-3">
            <FilterGroup :legend="t('Fit')">
                <FilterToggle
                    v-for="option in props.options.grades"
                    :key="option.value"
                    mono
                    :pressed="isOn('grades', option.value)"
                    :label="option.value"
                    :sr-label="t('Grade :grade', { grade: option.value })"
                    :data-test="`filter-grade-${option.value}`"
                    @toggle="toggle('grades', option.value)"
                />
            </FilterGroup>

            <FilterGroup :legend="t('Source')">
                <FilterToggle
                    v-for="option in props.options.sources"
                    :key="option.value"
                    :pressed="isOn('sources', option.value)"
                    :label="t(option.label)"
                    :data-test="`filter-source-${option.value}`"
                    @toggle="toggle('sources', option.value)"
                />
            </FilterGroup>

            <FilterGroup :legend="t('Data')">
                <FilterToggle
                    v-for="option in props.options.enrichment_statuses"
                    :key="option.value"
                    :pressed="isOn('enrichment_statuses', option.value)"
                    :label="t(option.label)"
                    :data-test="`filter-enrichment-${option.value}`"
                    @toggle="toggle('enrichment_statuses', option.value)"
                />
            </FilterGroup>
        </div>

        <!-- The team's own board, labels and people (Phase 10b). Each group is hidden when the
             team has nothing of that kind, so a fresh account isn't shown three empty rows. -->
        <div
            v-if="hasTeamFilters"
            class="grid gap-4 border-t border-border pt-5 sm:grid-cols-3"
        >
            <FilterGroup v-if="props.stages.length" :legend="t('Stage')">
                <FilterToggle
                    v-for="stage in props.stages"
                    :key="stage.id"
                    :pressed="isIdOn('stages', stage.id)"
                    :label="stage.name"
                    :data-test="`filter-stage-${stage.id}`"
                    @toggle="toggleId('stages', stage.id)"
                />
            </FilterGroup>

            <FilterGroup
                v-if="props.tags.length"
                :legend="t('Tags')"
                :hint="
                    props.filters.tags.length > 1
                        ? t('Leads must carry every tag you pick.')
                        : null
                "
            >
                <FilterToggle
                    v-for="tag in props.tags"
                    :key="tag.id"
                    :pressed="isIdOn('tags', tag.id)"
                    :label="tag.name"
                    :data-test="`filter-tag-${tag.id}`"
                    @toggle="toggleId('tags', tag.id)"
                />
            </FilterGroup>

            <FilterGroup v-if="props.members.length" :legend="t('Who’s on it')">
                <FilterToggle
                    v-for="member in props.members"
                    :key="member.id"
                    :pressed="isIdOn('assignees', member.id)"
                    :label="member.name"
                    :data-test="`filter-assignee-${member.id}`"
                    @toggle="toggleId('assignees', member.id)"
                />
                <FilterToggle
                    :pressed="props.filters.unassigned"
                    :label="t('Nobody')"
                    data-test="filter-unassigned"
                    @toggle="toggleUnassigned"
                />
            </FilterGroup>
        </div>

        <!-- Why now (PL1). Hidden wholesale for a team without the feature, rather than shown
             empty: a filter group that narrows by something the team cannot see is a filter group
             that returns nothing for no visible reason.

             "Evidence from" is a different question from "Source" above it, and the legend says
             so: Source is where the *lead* came from, this is where a *reason* came from. An
             imported company whose Companies House filing Alima later read is both. -->
        <div
            v-if="props.whyNow"
            class="grid gap-4 border-t border-border pt-5 sm:grid-cols-3"
        >
            <FilterGroup
                :legend="t('Evidence type')"
                :hint="
                    t(
                        'A trigger event is the only kind that makes a lead urgent.',
                    )
                "
            >
                <FilterToggle
                    v-for="option in props.options.evidence_kinds"
                    :key="option.value"
                    :pressed="isOn('evidence_kinds', option.value)"
                    :label="t(option.label)"
                    :data-test="`filter-evidence-kind-${option.value}`"
                    @toggle="toggle('evidence_kinds', option.value)"
                />
            </FilterGroup>

            <FilterGroup :legend="t('Evidence from')">
                <FilterToggle
                    v-for="option in props.options.evidence_sources"
                    :key="option.value"
                    :pressed="isOn('evidence_sources', option.value)"
                    :label="t(option.label)"
                    :data-test="`filter-evidence-source-${option.value}`"
                    @toggle="toggle('evidence_sources', option.value)"
                />
            </FilterGroup>

            <FilterGroup
                :legend="t('Confidence')"
                :hint="
                    t(
                        'Leads Alima knows too little about are hidden by default. Your threshold is on the Team settings screen.',
                    )
                "
            >
                <FilterToggle
                    :pressed="props.filters.include_low_confidence"
                    :label="t('Include low confidence')"
                    data-test="filter-include-low-confidence"
                    @toggle="
                        emit('change', {
                            include_low_confidence:
                                !props.filters.include_low_confidence,
                        })
                    "
                />
            </FilterGroup>
        </div>

        <div class="border-t border-border pt-5">
            <div class="grid max-w-sm gap-2">
                <Label for="filter-location">{{ t('Location') }}</Label>
                <Input
                    id="filter-location"
                    :model-value="props.filters.country"
                    :placeholder="t('Town, region or country')"
                    autocomplete="off"
                    aria-describedby="filter-location-hint"
                    dir="auto"
                    data-test="filter-location"
                    @change="
                        emit('change', {
                            country: (
                                $event.target as HTMLInputElement
                            ).value.trim(),
                        })
                    "
                />
                <p
                    id="filter-location-hint"
                    class="text-xs text-muted-foreground"
                >
                    {{
                        t(
                            'Matches a town, a region or a country — “GB” or “Manchester” both work.',
                        )
                    }}
                </p>
            </div>
        </div>
    </div>
</template>
