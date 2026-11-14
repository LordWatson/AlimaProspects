<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Plus, Search, UserPlus, X } from '@lucide/vue';
import { computed, nextTick, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { Panel } from '@/components/ui/panel';
import { getInitials } from '@/composables/useInitials';
import { useI18n } from '@/composables/useI18n';
import type { Lead, PipelineStage, TeamMemberOption } from '@/types';

/*
 * The team's own handle on a lead, on the detail screen: which stage it is in, who is working it,
 * and what it is labelled. Each control sends the **whole** new value (the stage, the full tag set,
 * the full assignee set) and the server works out the difference — so a half-applied change can't
 * leave the screen and the data disagreeing. That only holds while the screen knows the current
 * set, which is why `FindLeadAction` loads the assignments and tags.
 */
const props = defineProps<{
    lead: Lead;
    stages: PipelineStage[];
    members: TeamMemberOption[];
    canWrite: boolean;
}>();

const { t } = useI18n();

/** `SetLeadAssigneesRequest`'s ceiling. */
const MAX_ASSIGNEES = 10;

// Every control keeps the panel's own state (an open picker, a half-typed search) across the
// round trip, and the page where it is.
const visit = { preserveScroll: true, preserveState: true } as const;

// --- Stage ---------------------------------------------------------------------------------
// The lead's own stage, or the default column when it has never been placed.
const currentStage = computed(
    () =>
        props.lead.stage ??
        props.stages.find((stage) => stage.is_default) ??
        null,
);

/*
 * How far along the open pipeline the lead is, as segments. An outcome stage (won, lost) is not a
 * step further along — "lost" drawn as a full bar would read as progress — so it shows as a word.
 */
const openStages = computed(() =>
    [...props.stages]
        .filter((stage) => stage.outcome === null)
        .sort((a, b) => a.position - b.position),
);
const reached = computed(
    () =>
        openStages.value.findIndex(
            (stage) => stage.id === currentStage.value?.id,
        ) + 1,
);
const outcome = computed(
    () =>
        props.stages.find((stage) => stage.id === currentStage.value?.id)
            ?.outcome ?? null,
);
const outcomeLabel = computed(
    () =>
        props.stages.find((stage) => stage.id === currentStage.value?.id)
            ?.outcome_label ?? null,
);

function moveStage(stageId: number): void {
    useForm({ pipeline_stage_id: stageId }).put(
        `/leads/${props.lead.id}/stage`,
        visit,
    );
}

// --- Assignees -----------------------------------------------------------------------------
const assignees = computed(() => props.lead.assignees ?? []);
const assigneeIds = computed(() => assignees.value.map((p) => p.id));
const atLimit = computed(() => assigneeIds.value.length >= MAX_ASSIGNEES);

function setAssignees(next: number[]): void {
    useForm({ user_ids: next }).put(`/leads/${props.lead.id}/assignees`, visit);
}

function toggleAssignee(userId: number): void {
    if (assigneeIds.value.includes(userId)) {
        setAssignees(assigneeIds.value.filter((id) => id !== userId));
    } else if (!atLimit.value) {
        setAssignees([...assigneeIds.value, userId]);
    }
}

/*
 * The picker: a search over the whole team, opened on demand. A row of every member as a pill
 * stopped working past a handful of people; a filtered list works for five or five hundred.
 */
const picking = ref(false);
const query = ref('');
const searchField = ref<InstanceType<typeof Input> | null>(null);

const matches = computed(() => {
    const term = query.value.trim().toLocaleLowerCase();

    return term === ''
        ? props.members
        : props.members.filter((member) =>
              member.name.toLocaleLowerCase().includes(term),
          );
});

async function openPicker(): Promise<void> {
    picking.value = !picking.value;
    query.value = '';

    if (picking.value) {
        await nextTick();
        (searchField.value?.$el as HTMLInputElement | undefined)?.focus();
    }
}

// --- Tags ----------------------------------------------------------------------------------
const newTag = ref('');

function syncTags(names: string[]): void {
    useForm({ tags: names }).put(`/leads/${props.lead.id}/tags`, visit);
}

function addTag(): void {
    const name = newTag.value.trim();

    if (name === '') return;

    const existing = (props.lead.tags ?? []).map((tag) => tag.name);

    if (!existing.some((t) => t.toLowerCase() === name.toLowerCase())) {
        syncTags([...existing, name]);
    }

    newTag.value = '';
}

function removeTag(name: string): void {
    syncTags(
        (props.lead.tags ?? [])
            .map((tag) => tag.name)
            .filter((n) => n !== name),
    );
}
</script>

<template>
    <Panel :title="t('Your workflow')" data-test="work-panel">
        <!-- Stage, and how far along the pipeline that is -->
        <div class="space-y-2.5">
            <Label
                for="lead-stage"
                class="font-mono text-xs font-normal tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >{{ t('Stage') }}</Label
            >
            <NativeSelect
                v-if="props.canWrite"
                id="lead-stage"
                :model-value="currentStage?.id ?? ''"
                data-test="lead-stage"
                @update:model-value="moveStage(Number($event))"
            >
                <option
                    v-for="stage in props.stages"
                    :key="stage.id"
                    :value="stage.id"
                >
                    {{ stage.name }}
                </option>
            </NativeSelect>
            <p v-else class="text-sm font-semibold text-foreground" dir="auto">
                {{ currentStage?.name ?? '—' }}
            </p>

            <p v-if="outcome" class="text-sm">
                <Badge :variant="outcome === 'won' ? 'success' : 'secondary'">{{
                    t(outcomeLabel ?? '')
                }}</Badge>
            </p>
            <div
                v-else-if="openStages.length > 1 && reached > 0"
                class="flex gap-1"
                role="img"
                :aria-label="
                    t('Stage :n of :total', {
                        n: reached,
                        total: openStages.length,
                    })
                "
            >
                <span
                    v-for="(stage, index) in openStages"
                    :key="stage.id"
                    class="h-1.5 flex-1 rounded-full transition-colors duration-500 ease-brand"
                    :class="index < reached ? 'bg-brand' : 'bg-border'"
                    :title="stage.name"
                />
            </div>
        </div>

        <!-- Who's on it -->
        <div
            class="space-y-2.5 border-t border-line-soft pt-4"
            role="group"
            aria-labelledby="assignees-label"
        >
            <div class="flex items-center justify-between gap-2">
                <p
                    id="assignees-label"
                    class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
                >
                    {{ t('Who’s on it') }}
                </p>
                <Button
                    v-if="props.canWrite"
                    variant="ghost"
                    size="sm"
                    :aria-expanded="picking"
                    aria-controls="assignee-picker"
                    data-test="assign-open"
                    @click="openPicker"
                >
                    <UserPlus class="size-4" />
                    {{ picking ? t('Done') : t('Assign') }}
                </Button>
            </div>

            <!-- The people on it: one row each, with a way off. -->
            <ul v-if="assignees.length" class="space-y-1">
                <li
                    v-for="person in assignees"
                    :key="person.id"
                    class="group flex animate-screen items-center gap-2.5 rounded-full py-1 ps-1 pe-1.5 transition-colors hover:bg-background"
                >
                    <span
                        class="flex size-7 shrink-0 items-center justify-center rounded-full bg-brand font-mono text-[0.625rem] text-brand-foreground"
                        aria-hidden="true"
                        >{{ getInitials(person.name) }}</span
                    >
                    <span
                        class="min-w-0 flex-1 truncate text-sm font-medium"
                        dir="auto"
                        >{{ person.name }}</span
                    >
                    <button
                        v-if="props.canWrite"
                        type="button"
                        class="flex size-7 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-foreground hover:text-background focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        :aria-label="
                            t('Take :name off this lead', { name: person.name })
                        "
                        :data-test="`unassign-${person.id}`"
                        @click="toggleAssignee(person.id)"
                    >
                        <X class="size-3.5" />
                    </button>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Nobody yet.') }}
            </p>

            <!-- The picker -->
            <div
                v-if="props.canWrite && picking"
                id="assignee-picker"
                class="animate-screen space-y-2 rounded-2xl bg-background p-2"
                @keydown.esc.stop="picking = false"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <Label for="assignee-search" class="sr-only">{{
                        t('Find a teammate')
                    }}</Label>
                    <Input
                        id="assignee-search"
                        ref="searchField"
                        v-model="query"
                        type="search"
                        class="h-9 ps-9 text-sm"
                        :placeholder="t('Find a teammate…')"
                        autocomplete="off"
                        data-test="assign-search"
                    />
                </div>

                <ul
                    v-if="matches.length"
                    class="max-h-56 space-y-0.5 overflow-y-auto"
                >
                    <li v-for="member in matches" :key="member.id">
                        <button
                            type="button"
                            class="flex w-full items-center gap-2.5 rounded-xl px-2 py-1.5 text-start text-sm transition-colors hover:bg-card focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-50"
                            :aria-pressed="assigneeIds.includes(member.id)"
                            :disabled="
                                atLimit && !assigneeIds.includes(member.id)
                            "
                            :data-test="`assign-${member.id}`"
                            @click="toggleAssignee(member.id)"
                        >
                            <span
                                class="flex size-7 shrink-0 items-center justify-center rounded-full font-mono text-[0.625rem] transition-colors"
                                :class="
                                    assigneeIds.includes(member.id)
                                        ? 'bg-brand text-brand-foreground'
                                        : 'bg-muted text-foreground'
                                "
                                aria-hidden="true"
                                >{{ getInitials(member.name) }}</span
                            >
                            <span class="min-w-0 flex-1 truncate" dir="auto">{{
                                member.name
                            }}</span>
                            <!-- The tick keeps "assigned" from being colour alone (WCAG 1.4.1). -->
                            <Check
                                v-if="assigneeIds.includes(member.id)"
                                class="size-4 text-brand"
                                aria-hidden="true"
                            />
                        </button>
                    </li>
                </ul>
                <p v-else class="px-2 py-1.5 text-sm text-muted-foreground">
                    {{ t('Nobody on your team matches that.') }}
                </p>

                <p v-if="atLimit" class="px-2 text-xs text-muted-foreground">
                    {{
                        t('A lead can have up to :max people on it.', {
                            max: MAX_ASSIGNEES,
                        })
                    }}
                </p>
            </div>
        </div>

        <!-- Tags -->
        <div
            class="space-y-2.5 border-t border-line-soft pt-4"
            role="group"
            aria-labelledby="tags-label"
        >
            <p
                id="tags-label"
                class="font-mono text-xs tracking-eyebrow text-muted-foreground uppercase rtl:tracking-normal"
            >
                {{ t('Tags') }}
            </p>

            <ul v-if="props.lead.tags?.length" class="flex flex-wrap gap-1.5">
                <li
                    v-for="tag in props.lead.tags"
                    :key="tag.id"
                    class="animate-screen"
                >
                    <!-- Decorative taxonomy, never state — hence a lilac chip. -->
                    <Badge variant="lilac" class="gap-1" dir="auto">
                        {{ tag.name }}
                        <button
                            v-if="props.canWrite"
                            type="button"
                            class="-me-1 flex size-6 items-center justify-center rounded-full transition-colors hover:bg-ink/10 focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :title="t('Remove')"
                            :aria-label="
                                t('Remove the tag “:name”', { name: tag.name })
                            "
                            :data-test="`untag-${tag.id}`"
                            @click="removeTag(tag.name)"
                        >
                            <X class="size-3" />
                        </button>
                    </Badge>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('No tags yet.') }}
            </p>

            <form
                v-if="props.canWrite"
                class="flex gap-2"
                @submit.prevent="addTag"
            >
                <Label for="new-tag" class="sr-only">{{
                    t('Add a tag')
                }}</Label>
                <Input
                    id="new-tag"
                    v-model="newTag"
                    class="h-9 flex-1 text-sm"
                    maxlength="40"
                    :placeholder="t('e.g. warm')"
                    autocomplete="off"
                    data-test="new-tag"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
                    class="h-9"
                    :disabled="newTag.trim() === ''"
                    data-test="add-tag"
                >
                    <Plus class="size-4" />
                    {{ t('Add') }}
                </Button>
            </form>
        </div>
    </Panel>
</template>
