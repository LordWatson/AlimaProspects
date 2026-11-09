<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { CalendarClock, ListTodo, Plus, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Panel } from '@/components/ui/panel';
import { useFormat } from '@/composables/useFormat';
import { useI18n } from '@/composables/useI18n';
import type { LeadTask, TeamMemberOption } from '@/types';

/*
 * Work outstanding on a lead. `is_overdue` comes from the server, computed against the stored UTC
 * column, so a client with a wrong clock can't decide for itself what is late.
 *
 * Reminders are stored and nothing sends them yet — Notifications (Phase 12) sweeps them — which
 * the form says plainly rather than implying a nudge that isn't coming.
 */
const props = defineProps<{
    leadId: number;
    tasks: LeadTask[];
    members: TeamMemberOption[];
    canWrite: boolean;
}>();

const { t } = useI18n();
const { dateTime, date, number } = useFormat();

const isAdding = ref(false);

// Focus follows the affordance the member used, and goes back to it when the form closes. The
// refs sit on wrapping elements rather than on `Input`/`Button`: those are `<script setup>`
// components, so a ref to one is a closed instance proxy with no `focus()` on it.
const titleField = useTemplateRef<HTMLElement>('titleField');
const addTrigger = useTemplateRef<HTMLElement>('addTrigger');

watch(isAdding, async (opening) => {
    await nextTick();

    if (opening) {
        titleField.value?.querySelector('input')?.focus();
    } else {
        addTrigger.value?.querySelector('button')?.focus();
    }
});

const form = useForm<{
    title: string;
    assigned_to: number | null;
    due_at: string;
    remind_at: string;
}>({
    title: '',
    assigned_to: null,
    due_at: '',
    remind_at: '',
});

function save(): void {
    form.post(`/leads/${props.leadId}/tasks`, {
        preserveScroll: true,
        onSuccess: () => {
            isAdding.value = false;
            form.reset();
        },
    });
}

function setDone(task: LeadTask, done: boolean): void {
    // Keep it on screen for the rest of the visit, so the tick has a visible effect and
    // unticking it is where the member is already looking.
    if (done && !justCompleted.value.includes(task.id)) {
        justCompleted.value.push(task.id);
    }

    useForm({ done }).put(`/leads/tasks/${task.id}`, { preserveScroll: true });
}

function remove(task: LeadTask): void {
    useForm({}).delete(`/leads/tasks/${task.id}`, { preserveScroll: true });
}

const open = computed(() =>
    props.tasks.filter((task) => task.status === 'open'),
);
const done = computed(() =>
    props.tasks.filter((task) => task.status === 'done'),
);

/*
 * Completed tasks are folded away rather than listed under the open ones. A lead that gets worked
 * for a month accumulates them, and the panel became mostly struck-through text with the three
 * things that still need doing at the top of it. They are one click away, not gone.
 */
const showDone = ref(false);

// Ticked on this visit. A completed task would otherwise disappear from under the cursor.
const justCompleted = ref<number[]>([]);

const visible = computed(() =>
    showDone.value
        ? [...open.value, ...done.value]
        : [
              ...open.value,
              ...done.value.filter((task) =>
                  justCompleted.value.includes(task.id),
              ),
          ],
);
</script>

<template>
    <Panel :title="t('To do')" :count="open.length" data-test="tasks">
        <template #actions>
            <Button
                v-if="done.length"
                variant="ghost"
                size="sm"
                type="button"
                :aria-expanded="showDone"
                data-test="toggle-done-tasks"
                @click="showDone = !showDone"
            >
                {{
                    showDone
                        ? t('Hide done')
                        : t('Show :count done', { count: number(done.length) })
                }}
            </Button>

            <span ref="addTrigger">
                <Button
                    v-if="props.canWrite && !isAdding"
                    variant="outline"
                    size="sm"
                    type="button"
                    data-test="add-task"
                    @click="isAdding = true"
                >
                    <Plus class="size-4" />
                    {{ t('Add a task') }}
                </Button>
            </span>
        </template>

        <!-- `bg-background` rather than `bg-card`: the form sits inside a cream card, and a card
             on a card is invisible. The canvas colour reads as a well. -->
        <form
            v-if="isAdding"
            class="space-y-4 rounded-2xl border border-border bg-background p-4"
            @submit.prevent="save"
        >
            <div ref="titleField" class="grid gap-2">
                <Label for="task-title">{{ t('What needs doing?') }}</Label>
                <Input
                    id="task-title"
                    v-model="form.title"
                    :placeholder="t('e.g. Call the owner back')"
                    autocomplete="off"
                    data-test="task-title"
                />
                <InputError :message="form.errors.title" />
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <div class="grid gap-2">
                    <Label for="task-assignee">{{ t('Who?') }}</Label>
                    <select
                        id="task-assignee"
                        v-model="form.assigned_to"
                        class="h-9 rounded-md border border-input bg-background px-3 text-sm focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                        data-test="task-assignee"
                    >
                        <option :value="null">{{ t('Anyone') }}</option>
                        <option
                            v-for="member in props.members"
                            :key="member.id"
                            :value="member.id"
                        >
                            {{ member.name }}
                        </option>
                    </select>
                    <InputError :message="form.errors.assigned_to" />
                </div>

                <div class="grid gap-2">
                    <Label for="task-due">{{ t('Due') }}</Label>
                    <Input
                        id="task-due"
                        v-model="form.due_at"
                        type="datetime-local"
                        data-test="task-due"
                    />
                    <InputError :message="form.errors.due_at" />
                </div>

                <div class="grid gap-2">
                    <Label for="task-remind">{{ t('Remind me') }}</Label>
                    <Input
                        id="task-remind"
                        v-model="form.remind_at"
                        type="datetime-local"
                        aria-describedby="remind-hint"
                        data-test="task-remind"
                    />
                    <InputError :message="form.errors.remind_at" />
                </div>
            </div>

            <p id="remind-hint" class="text-xs text-muted-foreground">
                {{
                    t(
                        'Reminders are saved now — we’re switching the nudges on soon.',
                    )
                }}
            </p>

            <div class="flex justify-end gap-2">
                <Button
                    type="submit"
                    variant="brand"
                    size="sm"
                    :disabled="form.processing || form.title.trim() === ''"
                    data-test="save-task"
                >
                    {{ t('Add task') }}
                </Button>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    @click="isAdding = false"
                >
                    {{ t('Cancel') }}
                </Button>
            </div>
        </form>

        <EmptyState
            v-if="!props.tasks.length && !isAdding"
            :icon="ListTodo"
            size="sm"
            :title="t('Nothing to do yet')"
            :description="t('Add what needs doing next, and who is doing it.')"
            data-test="no-tasks"
        />

        <!-- Everything outstanding is finished, and the done list is folded away: the panel would
             otherwise be empty but for its own header. -->
        <p
            v-else-if="!visible.length && done.length"
            class="text-sm text-muted-foreground"
            data-test="all-tasks-done"
        >
            {{ t('Nothing outstanding — every task here is done.') }}
        </p>

        <ul v-else class="space-y-2">
            <li
                v-for="task in visible"
                :key="task.id"
                class="flex items-start gap-3 rounded-2xl border border-border bg-background p-4"
                :data-test="`task-${task.id}`"
            >
                <Checkbox
                    :id="`task-done-${task.id}`"
                    class="mt-0.5"
                    :model-value="task.status === 'done'"
                    :disabled="!task.can_complete"
                    :data-test="`complete-task-${task.id}`"
                    @update:model-value="setDone(task, $event === true)"
                />

                <div class="min-w-0 flex-1 space-y-1">
                    <Label
                        :for="`task-done-${task.id}`"
                        class="block text-sm font-normal"
                        :class="
                            task.status === 'done'
                                ? 'text-muted-foreground line-through'
                                : 'text-foreground'
                        "
                        dir="auto"
                    >
                        {{ task.title }}
                    </Label>
                    <p
                        class="flex flex-wrap items-center gap-2 font-mono text-xs text-muted-foreground"
                    >
                        <span
                            v-if="task.assignee"
                            class="font-sans"
                            dir="auto"
                            >{{ task.assignee.name }}</span
                        >
                        <span
                            v-if="task.due_at"
                            class="flex items-center gap-1"
                        >
                            <CalendarClock class="size-3" aria-hidden="true" />
                            <bdi>{{ date(task.due_at) }}</bdi>
                        </span>
                        <!-- Late is said in words as well as colour. -->
                        <Badge v-if="task.is_overdue" variant="warning">{{
                            t('Overdue')
                        }}</Badge>
                        <time
                            v-if="task.completed_at"
                            :datetime="task.completed_at"
                            >{{ dateTime(task.completed_at) }}</time
                        >
                    </p>
                </div>

                <button
                    v-if="task.can_delete"
                    type="button"
                    class="flex size-8 shrink-0 items-center justify-center rounded-full text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring focus-visible:outline-none"
                    :title="t('Delete')"
                    :aria-label="
                        t('Delete the task “:title”', { title: task.title })
                    "
                    :data-test="`delete-task-${task.id}`"
                    @click="remove(task)"
                >
                    <Trash2 class="size-3.5" />
                </button>
            </li>
        </ul>
    </Panel>
</template>
