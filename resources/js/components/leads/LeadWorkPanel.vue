<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Check, Plus, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/native-select';
import { useI18n } from '@/composables/useI18n';
import type { Lead, PipelineStage, TeamMemberOption } from '@/types';

/*
 * The team's own handle on a lead, on the detail screen: which stage it is in, who is working it,
 * and what it is labelled. Each control sends the **whole** new value (the stage, the full tag set,
 * the full assignee set) and the server works out the difference — so a half-applied change can't
 * leave the screen and the data disagreeing.
 */
const props = defineProps<{
    lead: Lead;
    stages: PipelineStage[];
    members: TeamMemberOption[];
    canWrite: boolean;
}>();

const { t } = useI18n();

// --- Stage ---------------------------------------------------------------------------------
// The lead's own stage, or the default column when it has never been placed.
const currentStageId = computed(
    () =>
        props.lead.stage?.id ??
        props.stages.find((stage) => stage.is_default)?.id ??
        null,
);

function moveStage(stageId: number): void {
    useForm({ pipeline_stage_id: stageId }).put(
        `/leads/${props.lead.id}/stage`,
        { preserveScroll: true },
    );
}

// --- Tags ----------------------------------------------------------------------------------
const newTag = ref('');

function syncTags(names: string[]): void {
    useForm({ tags: names }).put(`/leads/${props.lead.id}/tags`, {
        preserveScroll: true,
    });
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

// --- Assignees -----------------------------------------------------------------------------
const assigneeIds = computed(() =>
    (props.lead.assignees ?? []).map((p) => p.id),
);

function toggleAssignee(userId: number): void {
    const next = assigneeIds.value.includes(userId)
        ? assigneeIds.value.filter((id) => id !== userId)
        : [...assigneeIds.value, userId];

    useForm({ user_ids: next }).put(`/leads/${props.lead.id}/assignees`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Panel :title="t('Your workflow')" data-test="work-panel">
        <!-- Stage -->
        <div class="grid gap-2">
            <Label for="lead-stage">{{ t('Stage') }}</Label>
            <NativeSelect
                v-if="props.canWrite"
                id="lead-stage"
                :model-value="currentStageId ?? ''"
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
            <p v-else class="text-sm text-foreground" dir="auto">
                {{
                    props.stages.find((s) => s.id === currentStageId)?.name ??
                    '—'
                }}
            </p>
        </div>

        <!-- Assignees -->
        <div class="space-y-2" role="group" aria-labelledby="assignees-label">
            <p id="assignees-label" class="text-sm font-medium text-foreground">
                {{ t('Who’s on it') }}
            </p>

            <!--
              One row of toggles, which is both the display and the control. It used to be two: a
              row of removable chips for the people on the lead, and then the whole team again
              underneath as pressed toggles — so an assigned member appeared twice, with two
              different ways to take them off. The pressed state is the answer to "who's on it",
              and the tick keeps it from being colour alone (WCAG 1.4.1).
            -->
            <div v-if="props.canWrite" class="flex flex-wrap gap-1.5">
                <button
                    v-for="member in props.members"
                    :key="member.id"
                    type="button"
                    class="rounded-full transition-transform focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none active:scale-95"
                    :aria-pressed="assigneeIds.includes(member.id)"
                    :data-test="`assign-${member.id}`"
                    @click="toggleAssignee(member.id)"
                >
                    <Badge
                        :variant="
                            assigneeIds.includes(member.id)
                                ? 'brand'
                                : 'outline'
                        "
                        :class="
                            assigneeIds.includes(member.id)
                                ? ''
                                : 'border-input hover:bg-accent'
                        "
                        class="gap-1"
                        dir="auto"
                    >
                        <Check
                            v-if="assigneeIds.includes(member.id)"
                            class="size-3"
                            aria-hidden="true"
                        />
                        {{ member.name }}
                    </Badge>
                </button>
            </div>

            <!-- Read-only: the names, with nothing to press. -->
            <ul
                v-else-if="props.lead.assignees?.length"
                class="flex flex-wrap gap-1.5"
            >
                <li v-for="person in props.lead.assignees" :key="person.id">
                    <Badge variant="brand" dir="auto">{{ person.name }}</Badge>
                </li>
            </ul>
            <p v-else class="text-sm text-muted-foreground">
                {{ t('Nobody yet.') }}
            </p>
        </div>

        <!-- Tags -->
        <div class="space-y-2" role="group" aria-labelledby="tags-label">
            <p id="tags-label" class="text-sm font-medium text-foreground">
                {{ t('Tags') }}
            </p>

            <ul v-if="props.lead.tags?.length" class="flex flex-wrap gap-1.5">
                <li v-for="tag in props.lead.tags" :key="tag.id">
                    <!-- Decorative taxonomy, never state — hence a lilac chip. -->
                    <Badge variant="lilac" class="gap-1" dir="auto">
                        {{ tag.name }}
                        <button
                            v-if="props.canWrite"
                            type="button"
                            class="-me-1 flex size-6 items-center justify-center rounded-full focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
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
                class="flex gap-2 border-t border-border pt-2"
                @submit.prevent="addTag"
            >
                <Label for="new-tag" class="sr-only">{{
                    t('Add a tag')
                }}</Label>
                <Input
                    id="new-tag"
                    v-model="newTag"
                    class="h-8 flex-1 text-sm"
                    maxlength="40"
                    :placeholder="t('e.g. warm')"
                    autocomplete="off"
                    data-test="new-tag"
                />
                <Button
                    type="submit"
                    variant="outline"
                    size="sm"
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
