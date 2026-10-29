<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { BulkLeadAction, PipelineStage, TeamMemberOption } from '@/types';

/*
 * What to do with the leads currently selected in the list. Appears only while something is
 * selected, and is `aria-live` so a screen reader is told how many leads it is about rather than
 * having to go looking.
 *
 * Only the field the chosen action needs is shown — and only that field is sent — so a stage can't
 * travel along with a tagging request.
 */
const props = defineProps<{
    selected: number[];
    stages: PipelineStage[];
    members: TeamMemberOption[];
    maxBulk: number;
}>();

const emit = defineEmits<{ clear: []; applied: [] }>();

const { t } = useI18n();
const { number } = useFormat();

const action = ref<BulkLeadAction>('add_tags');
const tagText = ref('');
const userId = ref<number | null>(null);
const stageId = ref<number | null>(null);

const actions: { value: BulkLeadAction; label: string }[] = [
    { value: 'add_tags', label: 'Add tags' },
    { value: 'remove_tags', label: 'Remove tags' },
    { value: 'assign', label: 'Assign to' },
    { value: 'unassign', label: 'Remove assignee' },
    { value: 'move_stage', label: 'Move to stage' },
];

/** Which extra field this action needs — mirrors `BulkLeadAction::requires()`. */
const needs = computed<'tags' | 'user_id' | 'pipeline_stage_id'>(() => {
    switch (action.value) {
        case 'add_tags':
        case 'remove_tags':
            return 'tags';
        case 'assign':
        case 'unassign':
            return 'user_id';
        default:
            return 'pipeline_stage_id';
    }
});

watch(
    () => props.members,
    (members) => {
        if (userId.value === null) userId.value = members[0]?.id ?? null;
    },
    { immediate: true },
);

watch(
    () => props.stages,
    (stages) => {
        if (stageId.value === null) stageId.value = stages[0]?.id ?? null;
    },
    { immediate: true },
);

const tags = computed(() =>
    tagText.value
        .split(/[,،]/)
        .map((tag) => tag.trim())
        .filter(Boolean),
);

const canApply = computed(() => {
    if (!props.selected.length) return false;

    if (needs.value === 'tags') return tags.value.length > 0;
    if (needs.value === 'user_id') return userId.value !== null;

    return stageId.value !== null;
});

const overLimit = computed(() => props.selected.length > props.maxBulk);

function apply(): void {
    // Only the field this action needs is sent, so a stage can't travel along with a tagging
    // request and the server's `requiredIf` rules line up with what arrives.
    const payload: {
        action: BulkLeadAction;
        lead_ids: number[];
        tags?: string[];
        user_id?: number | null;
        pipeline_stage_id?: number | null;
    } = {
        action: action.value,
        lead_ids: props.selected,
    };

    if (needs.value === 'tags') payload.tags = tags.value;
    if (needs.value === 'user_id') payload.user_id = userId.value;
    if (needs.value === 'pipeline_stage_id')
        payload.pipeline_stage_id = stageId.value;

    useForm(payload).post('/leads/bulk', {
        preserveScroll: true,
        onSuccess: () => {
            tagText.value = '';
            emit('applied');
        },
    });
}
</script>

<template>
    <div
        class="flex flex-col gap-3 rounded-2xl border border-brand/40 bg-brand/5 p-4 sm:flex-row sm:items-end"
        role="region"
        :aria-label="t('Bulk actions')"
        data-test="bulk-bar"
    >
        <p
            class="font-mono text-xs text-foreground tabular-nums sm:pb-2"
            aria-live="polite"
        >
            {{ t(':count selected', { count: number(props.selected.length) }) }}
        </p>

        <div class="grid gap-2 sm:w-48">
            <Label for="bulk-action">{{
                t('What would you like to do?')
            }}</Label>
            <select
                id="bulk-action"
                v-model="action"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                data-test="bulk-action"
            >
                <option
                    v-for="option in actions"
                    :key="option.value"
                    :value="option.value"
                >
                    {{ t(option.label) }}
                </option>
            </select>
        </div>

        <div v-if="needs === 'tags'" class="grid flex-1 gap-2">
            <Label for="bulk-tags">{{ t('Which tags?') }}</Label>
            <Input
                id="bulk-tags"
                v-model="tagText"
                :placeholder="t('warm, trade show')"
                autocomplete="off"
                data-test="bulk-tags"
            />
        </div>

        <div v-else-if="needs === 'user_id'" class="grid flex-1 gap-2">
            <Label for="bulk-user">{{ t('Which teammate?') }}</Label>
            <select
                id="bulk-user"
                v-model="userId"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                data-test="bulk-user"
            >
                <option
                    v-for="member in props.members"
                    :key="member.id"
                    :value="member.id"
                >
                    {{ member.name }}
                </option>
            </select>
        </div>

        <div v-else class="grid flex-1 gap-2">
            <Label for="bulk-stage">{{ t('Which stage?') }}</Label>
            <select
                id="bulk-stage"
                v-model="stageId"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                data-test="bulk-stage"
            >
                <option
                    v-for="stage in props.stages"
                    :key="stage.id"
                    :value="stage.id"
                >
                    {{ stage.name }}
                </option>
            </select>
        </div>

        <div class="flex items-center gap-2">
            <Button
                type="button"
                variant="brand"
                size="sm"
                :disabled="!canApply || overLimit"
                :aria-describedby="overLimit ? 'bulk-limit' : undefined"
                data-test="bulk-apply"
                @click="apply"
            >
                {{ t('Apply') }}
            </Button>
            <Button
                type="button"
                variant="ghost"
                size="sm"
                data-test="bulk-clear"
                @click="emit('clear')"
            >
                <X class="size-4" />
                {{ t('Clear selection') }}
            </Button>
        </div>

        <p
            v-if="overLimit"
            id="bulk-limit"
            class="text-xs text-destructive sm:pb-2"
            role="alert"
        >
            {{
                t('One action can cover up to :max leads.', {
                    max: number(props.maxBulk),
                })
            }}
        </p>
    </div>
</template>
