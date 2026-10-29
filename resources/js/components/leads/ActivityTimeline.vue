<script setup lang="ts">
import {
    ArrowRightLeft,
    CircleCheck,
    CircleDashed,
    FileUp,
    History,
    MessageSquare,
    Radar,
    ScanSearch,
    Sparkles,
    Tags,
    UserMinus,
    UserPlus,
} from '@lucide/vue';
import type { Component } from 'vue';
import { EmptyState } from '@/components/ui/empty-state';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { LeadActivity, LeadActivityType } from '@/types';

/*
 * A lead's timeline. The server sends a type and a bag of values; the sentence is composed here so
 * it can be English or Arabic (ADR 0007) and so a stored entry never freezes yesterday's wording.
 *
 * Every entry carries an icon *and* a label — the icon is decorative (aria-hidden), never the only
 * thing conveying what happened.
 */
const props = defineProps<{
    activities: LeadActivity[];
}>();

const { t } = useI18n();
const { dateTime } = useFormat();

const icons: Record<LeadActivityType, Component> = {
    discovered: Radar,
    enriched: ScanSearch,
    scored: Sparkles,
    stage_changed: ArrowRightLeft,
    note_added: MessageSquare,
    task_created: CircleDashed,
    task_completed: CircleCheck,
    assigned: UserPlus,
    unassigned: UserMinus,
    tags_changed: Tags,
    imported: FileUp,
};

/** The one-line description of an entry, built from its type and properties. */
function describe(activity: LeadActivity): string {
    const p = activity.properties;

    switch (activity.type) {
        case 'discovered':
            return t('Found on :source', {
                source: t(sourceLabel(String(p.source ?? ''))),
            });
        case 'enriched':
            return Number(p.contacts ?? 0) > 0
                ? t('Company details filled in · :count contacts found', {
                      count: Number(p.contacts),
                  })
                : t('Company details filled in');
        case 'scored':
            return p.icp
                ? t('Graded :grade (:score/100) against “:icp”', {
                      grade: String(p.grade ?? ''),
                      score: Number(p.score ?? 0),
                      icp: String(p.icp),
                  })
                : t('Graded :grade (:score/100)', {
                      grade: String(p.grade ?? ''),
                      score: Number(p.score ?? 0),
                  });
        case 'stage_changed':
            return p.from
                ? t('Moved from :from to :to', {
                      from: String(p.from),
                      to: String(p.to ?? ''),
                  })
                : t('Moved to :to', { to: String(p.to ?? '') });
        case 'note_added':
            return t('Note added: “:excerpt”', {
                excerpt: String(p.excerpt ?? ''),
            });
        case 'task_created':
            return t('Task added: :title', { title: String(p.title ?? '') });
        case 'task_completed':
            return t('Task done: :title', { title: String(p.title ?? '') });
        case 'assigned':
            return t('Assigned to :members', { members: members(p.members) });
        case 'unassigned':
            return t('Taken off :members', { members: members(p.members) });
        case 'tags_changed':
            return tagChange(p.added, p.removed);
        case 'imported':
            return p.merged
                ? t('Imported from a CSV, into the lead you already had')
                : t('Imported from a CSV');
        default:
            return t(activity.type_label);
    }
}

/** A list of names from a `properties` bag, joined for reading. */
function members(value: unknown): string {
    return Array.isArray(value) ? value.map(String).join(', ') : '';
}

/** "Tags changed" is only useful if it says which way. */
function tagChange(added: unknown, removed: unknown): string {
    const on = Array.isArray(added) ? added.map(String) : [];
    const off = Array.isArray(removed) ? removed.map(String) : [];

    if (on.length && off.length) {
        return t('Tagged :added, untagged :removed', {
            added: on.join(', '),
            removed: off.join(', '),
        });
    }

    if (on.length) return t('Tagged :added', { added: on.join(', ') });
    if (off.length) return t('Untagged :removed', { removed: off.join(', ') });

    return t('Tags changed');
}

// Mirrors LeadSource::label() so a timeline entry names a provider the same way the rest of the
// product does.
function sourceLabel(source: string): string {
    return (
        {
            companies_house: 'Companies House',
            google_places: 'Google Places',
            web_search: 'Web search',
        }[source] ?? source
    );
}

function actor(activity: LeadActivity): string {
    return activity.user?.name ?? t('Alima');
}

/** The rail is drawn under every marker but the last, so the list visibly ends. */
function isLast(activity: LeadActivity): boolean {
    return activity.id === props.activities[props.activities.length - 1]?.id;
}
</script>

<template>
    <EmptyState
        v-if="!props.activities.length"
        :icon="History"
        :title="t('Nothing has happened yet')"
        :description="
            t(
                'As Alima enriches and grades this lead — and as your team works it — everything lands here.',
            )
        "
        data-test="timeline-empty"
    />

    <ol v-else class="space-y-0" data-test="timeline">
        <li
            v-for="activity in props.activities"
            :key="activity.id"
            class="flex gap-4 pb-6 last:pb-0"
            :data-test="`activity-${activity.type}`"
        >
            <!-- The marker column owns the rail, so the line is centred on the marker by layout
                 rather than by a hand-measured offset. Hidden on the last entry, so the list ends. -->
            <div class="flex shrink-0 flex-col items-center">
                <span
                    class="flex size-9 items-center justify-center rounded-full bg-accent text-muted-foreground"
                    aria-hidden="true"
                >
                    <component :is="icons[activity.type]" class="size-4" />
                </span>
                <span
                    v-if="!isLast(activity)"
                    class="w-px flex-1 bg-border"
                    aria-hidden="true"
                />
            </div>

            <div class="min-w-0 flex-1 space-y-0.5 pt-1">
                <p class="text-sm text-foreground" dir="auto">
                    {{ describe(activity) }}
                </p>
                <p class="font-mono text-xs text-muted-foreground">
                    <span dir="auto">{{ actor(activity) }}</span>
                    ·
                    <time :datetime="activity.occurred_at">{{
                        dateTime(activity.occurred_at)
                    }}</time>
                </p>
            </div>
        </li>
    </ol>
</template>
