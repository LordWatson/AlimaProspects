<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ClipboardList,
    List,
    Pencil,
    Plus,
    Settings2,
    Trash2,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef } from 'vue';
import InputError from '@/components/InputError.vue';
import PageHero from '@/components/PageHero.vue';
import GradeBadge from '@/components/scoring/GradeBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { Lead, PipelineStage } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Leads', href: '/leads' },
            { title: 'Pipeline', href: '/leads/pipeline' },
        ],
    },
});

const props = defineProps<{
    stages: PipelineStage[];
    /** Leads per stage, keyed by stage id. */
    cards: Record<number, Lead[]>;
    counts: Record<number, number>;
    columnLimit: number;
    can: { work: boolean; manageStages: boolean };
}>();

const { t } = useI18n();
const { number } = useFormat();

// --- Moving a lead -------------------------------------------------------------------------
// Drag is the quick way; the per-card picker is the one that works with a keyboard, a screen reader
// and a phone. Both call the same endpoint, so neither is the privileged path.
const draggingLeadId = ref<number | null>(null);
const dropTargetStageId = ref<number | null>(null);

/**
 * The picker's pending value per card. The move is **not** sent on `change`: arrowing through a
 * closed `<select>` fires `change` per keypress in Chrome and Edge, which would fire a request per
 * option and tear the card out from under the cursor (WCAG 3.2.2). The member picks, then presses
 * Move — the same shape as the bulk bar's Apply.
 */
const pending = ref<Record<number, number>>({});

/** What a screen reader is told after a move, since the card itself moves out of the column. */
const announcement = ref('');

function pickedFor(lead: Lead, currentStageId: number): number {
    return pending.value[lead.id] ?? currentStageId;
}

function move(lead: Lead, stageId: number): void {
    const stage = props.stages.find((s) => s.id === stageId);

    router.put(
        `/leads/${lead.id}/stage`,
        { pipeline_stage_id: stageId },
        {
            preserveScroll: true,
            onSuccess: () => {
                announcement.value = t('Moved :company to :stage', {
                    company: lead.company_name,
                    stage: stage?.name ?? '',
                });
            },
            // The server is the truth about where the lead is, so a rejected move must not leave
            // the picker showing a column the lead isn't in.
            onFinish: () => {
                delete pending.value[lead.id];
            },
        },
    );
}

function onDragStart(event: DragEvent, lead: Lead): void {
    if (!props.can.work) return;

    draggingLeadId.value = lead.id;
    event.dataTransfer?.setData('text/plain', String(lead.id));

    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}

function onDrop(stageId: number): void {
    const leadId = draggingLeadId.value;

    draggingLeadId.value = null;
    dropTargetStageId.value = null;
    dragDepth = 0;

    if (leadId === null) return;

    const found = leadIn(leadId);

    if (!found || found.stageId === stageId) return;

    move(found.lead, stageId);
}

/**
 * `dragleave` fires when the pointer crosses into a child element too, which made the drop
 * highlight flicker off mid-hover. Counting enters and leaves is the usual answer.
 */
let dragDepth = 0;

function onDragEnter(stageId: number): void {
    dragDepth++;
    dropTargetStageId.value = stageId;
}

function onDragLeave(stageId: number): void {
    dragDepth--;

    if (dragDepth <= 0 && dropTargetStageId.value === stageId) {
        dropTargetStageId.value = null;
    }
}

/** The lead being dragged, and the column it is currently rendered in. */
function leadIn(leadId: number): { lead: Lead; stageId: number } | null {
    for (const [stageId, leads] of Object.entries(props.cards)) {
        const lead = leads.find((candidate) => candidate.id === leadId);

        if (lead) return { lead, stageId: Number(stageId) };
    }

    return null;
}

function cardsFor(stage: PipelineStage): Lead[] {
    return props.cards[stage.id] ?? [];
}

function hiddenIn(stage: PipelineStage): number {
    return Math.max(0, (props.counts[stage.id] ?? 0) - cardsFor(stage).length);
}

const total = computed(() =>
    Object.values(props.counts).reduce((sum, count) => sum + count, 0),
);

// --- Editing the board ---------------------------------------------------------------------
const isAddingStage = ref(false);
const stageForm = useForm({ name: '' });

const addField = useTemplateRef<HTMLElement>('addField');
const addSlot = useTemplateRef<HTMLElement>('addSlot');

async function openAddStage(): Promise<void> {
    isAddingStage.value = true;

    // Focus follows the button the member just used, rather than being dropped on the body when it
    // unmounts.
    await nextTick();
    addField.value?.querySelector('input')?.focus();
}

async function closeAddStage(): Promise<void> {
    isAddingStage.value = false;

    await nextTick();
    addSlot.value?.querySelector('button')?.focus();
}

function addStage(): void {
    stageForm.post('/leads/pipeline/stages', {
        preserveScroll: true,
        onSuccess: () => {
            isAddingStage.value = false;
            stageForm.reset();
        },
    });
}

// Renaming a column, inline on its own heading — the board is the only place a team manages its
// stages, so this is where the name has to be editable.
const renamingStageId = ref<number | null>(null);
const renameForm = useForm({ name: '', outcome: '', is_default: false });
const renameField = useTemplateRef<HTMLElement>('renameField');

async function openRename(stage: PipelineStage): Promise<void> {
    renamingStageId.value = stage.id;
    renameForm.name = stage.name;
    // The rename endpoint saves the whole stage, so the values it isn't changing are sent back as
    // they are rather than being reset to defaults.
    renameForm.outcome = stage.outcome ?? '';
    renameForm.is_default = stage.is_default;

    await nextTick();
    renameField.value?.querySelector('input')?.focus();
}

function saveRename(stage: PipelineStage): void {
    renameForm.put(`/leads/pipeline/stages/${stage.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            renamingStageId.value = null;
        },
    });
}

// Removing a column moves its leads, so the confirmation says where they go rather than relying on
// the member knowing.
const confirmingRemovalId = ref<number | null>(null);

const defaultStageName = computed(
    () => props.stages.find((stage) => stage.is_default)?.name ?? '',
);

function removeStage(stage: PipelineStage): void {
    confirmingRemovalId.value = null;

    useForm({}).delete(`/leads/pipeline/stages/${stage.id}`, {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="t('Pipeline')" />

    <div class="flex flex-col gap-8 py-2">
        <PageHero
            index="06"
            :eyebrow="t('Pipeline')"
            :title="t('Your|board.')"
            :description="
                t(
                    'Every lead your team is working, by where it has got to. Drag a card, or pick a stage and press Move — both do the same thing.',
                )
            "
        >
            <template #actions>
                <Button variant="lime" as-child>
                    <Link href="/leads">
                        <List class="size-4" />
                        {{ t('List view') }}
                    </Link>
                </Button>
                <div
                    v-if="can.manageStages && !isAddingStage"
                    ref="addSlot"
                    class="contents"
                >
                    <Button
                        variant="hero"
                        data-test="add-stage"
                        @click="openAddStage"
                    >
                        <Plus class="size-4" />
                        {{ t('Add a stage') }}
                    </Button>
                </div>
            </template>
        </PageHero>

        <!-- A move takes the card out of the column the member was looking at, so what happened is
             said out loud. Always mounted, because a live region inserted with text already in it
             is usually not announced. -->
        <p class="sr-only" role="status" aria-live="polite">
            {{ announcement }}
        </p>

        <!-- Add a stage -->
        <form
            v-if="isAddingStage"
            class="flex flex-col gap-3 rounded-2xl border border-border bg-card p-4 sm:flex-row sm:items-end"
            @submit.prevent="addStage"
        >
            <div ref="addField" class="grid flex-1 gap-2">
                <Label for="stage-name">{{ t('Stage name') }}</Label>
                <Input
                    id="stage-name"
                    v-model="stageForm.name"
                    :placeholder="t('e.g. Proposal sent')"
                    autocomplete="off"
                    data-test="stage-name"
                />
                <InputError :message="stageForm.errors.name" />
            </div>
            <div class="flex gap-2">
                <Button
                    type="submit"
                    variant="brand"
                    size="sm"
                    :disabled="stageForm.processing"
                    data-test="save-stage"
                >
                    {{ t('Add') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="closeAddStage"
                >
                    {{ t('Cancel') }}
                </Button>
            </div>
        </form>

        <EmptyState
            v-if="total === 0"
            :icon="ClipboardList"
            :title="t('Nothing on the board yet')"
            :description="
                t(
                    'Your leads land in the first stage as Alima finds them, and you move them along from there.',
                )
            "
            data-test="empty-board"
        >
            <Button variant="brand" as-child>
                <Link href="/discovery">{{ t('Find leads') }}</Link>
            </Button>
        </EmptyState>

        <!-- The board. Horizontally scrollable, logical properties throughout, so it mirrors. -->
        <div
            v-else
            class="-mx-4 flex gap-4 overflow-x-auto px-4 pb-4 sm:mx-0 sm:px-0"
        >
            <section
                v-for="stage in stages"
                :key="stage.id"
                class="flex w-72 shrink-0 flex-col gap-3 rounded-2xl border bg-card p-3 transition-colors"
                :class="
                    dropTargetStageId === stage.id
                        ? 'border-brand bg-brand/5'
                        : 'border-border'
                "
                :aria-label="stage.name"
                :data-test="`stage-${stage.id}`"
                @dragover.prevent
                @dragenter.prevent="onDragEnter(stage.id)"
                @dragleave="onDragLeave(stage.id)"
                @drop.prevent="onDrop(stage.id)"
            >
                <header class="flex items-center gap-1 px-1">
                    <h2
                        class="flex-1 truncate text-sm font-semibold text-foreground rtl:font-bold"
                        dir="auto"
                    >
                        {{ stage.name }}
                    </h2>
                    <Badge
                        v-if="stage.outcome && stage.outcome_label"
                        :variant="
                            stage.outcome === 'won' ? 'success' : 'outline'
                        "
                    >
                        {{ t(stage.outcome_label) }}
                    </Badge>
                    <span
                        class="px-1 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        <bdi dir="ltr">{{ number(counts[stage.id] ?? 0) }}</bdi>
                    </span>
                    <template v-if="can.manageStages">
                        <button
                            type="button"
                            class="flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :title="t('Rename')"
                            :aria-label="
                                t('Rename the stage “:name”', {
                                    name: stage.name,
                                })
                            "
                            :data-test="`rename-stage-${stage.id}`"
                            @click="openRename(stage)"
                        >
                            <Pencil class="size-3.5" />
                        </button>
                        <button
                            v-if="!stage.is_default"
                            type="button"
                            class="flex size-8 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                            :title="t('Remove')"
                            :aria-label="
                                t('Remove the stage “:name”', {
                                    name: stage.name,
                                })
                            "
                            :data-test="`remove-stage-${stage.id}`"
                            @click="confirmingRemovalId = stage.id"
                        >
                            <Trash2 class="size-3.5" />
                        </button>
                    </template>
                </header>

                <!-- Rename, inline, so the copy's promise that the stages are the team's is true. -->
                <form
                    v-if="renamingStageId === stage.id"
                    class="space-y-2 rounded-xl border border-border bg-background p-2"
                    @submit.prevent="saveRename(stage)"
                >
                    <div ref="renameField" class="grid gap-1.5">
                        <Label :for="`rename-${stage.id}`" class="sr-only">{{
                            t('Stage name')
                        }}</Label>
                        <Input
                            :id="`rename-${stage.id}`"
                            v-model="renameForm.name"
                            class="h-8 text-sm"
                            autocomplete="off"
                            :data-test="`rename-input-${stage.id}`"
                        />
                        <InputError :message="renameForm.errors.name" />
                    </div>
                    <div class="flex gap-2">
                        <Button
                            type="submit"
                            variant="brand"
                            size="sm"
                            :disabled="renameForm.processing"
                            :data-test="`save-rename-${stage.id}`"
                        >
                            {{ t('Save') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="renamingStageId = null"
                        >
                            {{ t('Cancel') }}
                        </Button>
                    </div>
                </form>

                <!-- Removing a column moves its leads, so the confirmation says where they go. -->
                <div
                    v-if="confirmingRemovalId === stage.id"
                    class="space-y-2 rounded-xl border border-border bg-background p-3"
                    role="alertdialog"
                    :aria-label="t('Remove this stage?')"
                >
                    <p class="text-xs text-muted-foreground">
                        {{
                            counts[stage.id]
                                ? t(
                                      'Its :count leads move to “:stage”. Nothing is deleted.',
                                      {
                                          count: counts[stage.id],
                                          stage: defaultStageName,
                                      },
                                  )
                                : t('This stage is empty, so nothing moves.')
                        }}
                    </p>
                    <div class="flex gap-2">
                        <Button
                            type="button"
                            variant="destructive"
                            size="sm"
                            :data-test="`confirm-remove-${stage.id}`"
                            @click="removeStage(stage)"
                        >
                            {{ t('Remove stage') }}
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="confirmingRemovalId = null"
                        >
                            {{ t('Cancel') }}
                        </Button>
                    </div>
                </div>

                <ul class="flex flex-col gap-2">
                    <li
                        v-for="lead in cardsFor(stage)"
                        :key="lead.id"
                        class="rounded-xl border border-border bg-background p-3 transition-opacity"
                        :class="draggingLeadId === lead.id ? 'opacity-50' : ''"
                        :draggable="can.work"
                        :data-test="`card-${lead.id}`"
                        @dragstart="onDragStart($event, lead)"
                        @dragend="draggingLeadId = null"
                    >
                        <div class="flex items-start justify-between gap-2">
                            <Link
                                :href="`/leads/${lead.id}`"
                                class="rounded-sm text-sm font-medium text-foreground underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                                dir="auto"
                            >
                                {{ lead.company_name }}
                            </Link>
                            <GradeBadge
                                v-if="lead.score"
                                :grade="lead.score.grade"
                                :score="lead.score.score"
                                :label="t(lead.score.grade_label)"
                            />
                        </div>

                        <p
                            v-if="lead.locality || lead.industry"
                            class="mt-1 truncate text-xs text-muted-foreground"
                            dir="auto"
                        >
                            {{
                                [lead.industry, lead.locality]
                                    .filter(Boolean)
                                    .join(' · ')
                            }}
                        </p>

                        <ul
                            v-if="lead.tags?.length"
                            class="mt-2 flex flex-wrap gap-1"
                        >
                            <li v-for="tag in lead.tags" :key="tag.id">
                                <Badge variant="lilac" dir="auto">{{
                                    tag.name
                                }}</Badge>
                            </li>
                        </ul>

                        <div
                            class="mt-2 flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                v-for="person in lead.assignees ?? []"
                                :key="person.id"
                                class="truncate"
                                dir="auto"
                                >{{ person.name }}</span
                            >
                            <span
                                v-if="lead.open_tasks_count"
                                class="font-mono tabular-nums"
                            >
                                {{
                                    t(':count to do', {
                                        count: lead.open_tasks_count,
                                    })
                                }}
                            </span>
                        </div>

                        <!-- The keyboard, screen-reader and touch route to the same move. Picking
                             doesn't send it: arrowing through a closed select fires `change` per
                             keypress, which would fire a request per option and pull the card out
                             from under the cursor. The member picks, then presses Move. -->
                        <div v-if="can.work" class="mt-3 flex gap-1.5">
                            <label class="sr-only" :for="`move-${lead.id}`">{{
                                t('Move :company to another stage', {
                                    company: lead.company_name,
                                })
                            }}</label>
                            <select
                                :id="`move-${lead.id}`"
                                class="h-11 min-w-0 flex-1 rounded-md border border-input bg-background px-2 text-xs text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none sm:h-8"
                                :value="pickedFor(lead, stage.id)"
                                :data-test="`move-${lead.id}`"
                                @change="
                                    pending[lead.id] = Number(
                                        ($event.target as HTMLSelectElement)
                                            .value,
                                    )
                                "
                            >
                                <option
                                    v-for="option in stages"
                                    :key="option.id"
                                    :value="option.id"
                                >
                                    {{ option.name }}
                                </option>
                            </select>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                class="h-11 shrink-0 sm:h-8"
                                :disabled="
                                    pickedFor(lead, stage.id) === stage.id
                                "
                                :data-test="`move-go-${lead.id}`"
                                @click="move(lead, pickedFor(lead, stage.id))"
                            >
                                {{ t('Move') }}
                            </Button>
                        </div>
                    </li>
                </ul>

                <!-- A column is a summary: past the limit it says so and sends you to the list. -->
                <Link
                    v-if="hiddenIn(stage) > 0"
                    :href="`/leads?stages[]=${stage.id}`"
                    class="rounded-md px-1 py-2 text-center text-xs text-brand underline-offset-4 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{
                        t(':count more in this stage', {
                            count: hiddenIn(stage),
                        })
                    }}
                </Link>

                <p
                    v-else-if="!cardsFor(stage).length"
                    class="px-1 py-6 text-center text-xs text-muted-foreground"
                >
                    {{
                        can.work
                            ? t('Drop a lead here.')
                            : t('Nothing here yet.')
                    }}
                </p>
            </section>
        </div>

        <p
            v-if="can.manageStages && total > 0"
            class="flex items-center gap-2 text-xs text-muted-foreground"
        >
            <Settings2 class="size-3.5" aria-hidden="true" />
            {{
                t(
                    'The stages are yours — rename, add and remove them as you like.',
                )
            }}
        </p>
    </div>
</template>
